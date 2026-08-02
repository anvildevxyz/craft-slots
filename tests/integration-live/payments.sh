#!/usr/bin/env bash
#
# Live behavioural integration suite for direct payments.
#
# Exercises the REAL code against the REAL database + Stripe test mode — the
# behavioural coverage the unit suite can't provide (there is no in-process
# Craft test harness). Complements the Playwright browser E2E (tests/e2e).
#
# Prerequisites:
#   - DDEV running; the plugin in `direct` payment mode with Stripe TEST keys
#     and a webhook secret matching an active `stripe listen`.
#   - `stripe listen --forward-to <site>/index.php?p=slots/api/v1/payment/webhook/stripe`
#   - A bookable priced service (defaults to id 1236; override with SERVICE_ID).
#
# Usage:  bash tests/integration-live/payments.sh
#
set -uo pipefail
BASE="${SLOTS_BASE:-https://craft-plugin-dev.ddev.site}"
SERVICE_ID="${SERVICE_ID:-1236}"
PASS=0; FAIL=0
ok(){ echo "  ✓ $1"; PASS=$((PASS+1)); }
bad(){ echo "  ✗ $1"; FAIL=$((FAIL+1)); }
q(){ ddev mysql -N -e "$1" 2>/dev/null; }
sql(){ ddev mysql -e "$1" 2>/dev/null; }
csrf_jar(){ curl -sS -k -c /tmp/bkintjar "$BASE/index.php?p=actions/users/session-info" -H "Accept: application/json" | python3 -c "import json,sys;print(json.load(sys.stdin)['csrfTokenValue'])"; }

cleanup(){ sql "DELETE p FROM slots_payments p JOIN slots_reservations r ON p.reservationId=r.id WHERE r.userEmail='int@example.com'; DELETE FROM slots_reservations WHERE userEmail='int@example.com'; DELETE FROM slots_soft_locks; UPDATE slots_services SET price=NULL WHERE id=$SERVICE_ID;"; }

echo "════════ Direct-payment live integration suite ════════"
CSRF=$(csrf_jar)

# ── Scenario 1: full happy path (create → pay → webhook → confirmed) ──
echo "── 1. Happy path: create → pay → webhook → confirmed ──"
sql "UPDATE slots_services SET price=50 WHERE id=$SERVICE_ID;"
TOK="int-$(q 'SELECT LOWER(HEX(RANDOM_BYTES(6)))')"
sql "INSERT INTO slots_reservations (bookingDate,confirmationToken,userEmail,userName,status,serviceId,quantity,dateCreated,dateUpdated,uid) VALUES ('2026-09-20','$TOK','int@example.com','Integ','pending',$SERVICE_ID,1,NOW(),NOW(),UUID());"
RID=$(q "SELECT id FROM slots_reservations WHERE confirmationToken='$TOK';")
CREATE=$(curl -sS -k -b /tmp/bkintjar "$BASE/index.php?p=slots/api/v1/payment/create" -H "Accept: application/json" -H "X-CSRF-Token: $CSRF" -d "reservationId=$RID" -d "token=$TOK")
CS=$(echo "$CREATE" | python3 -c "import json,sys;print(json.load(sys.stdin).get('clientSecret',''))" 2>/dev/null)
[ -n "$CS" ] && ok "payment/create returned a clientSecret" || bad "payment/create failed: $CREATE"
INTENT="${CS%%_secret_*}"
stripe payment_intents confirm "$INTENT" -d "payment_method=pm_card_visa" -d "return_url=$BASE" >/dev/null 2>&1
sleep 5
[ "$(q "SELECT status FROM slots_reservations WHERE id=$RID")" = "confirmed" ] && ok "webhook confirmed the reservation" || bad "reservation not confirmed"
[ "$(q "SELECT status FROM slots_payments WHERE reservationId=$RID ORDER BY id DESC LIMIT 1")" = "paid" ] && ok "payment marked paid" || bad "payment not paid"
CUR=$(q "SELECT currency FROM slots_payments WHERE reservationId=$RID ORDER BY id DESC LIMIT 1")

# ── Scenario 2: currency consistency (record matches getCurrency resolver) ──
echo "── 2. Currency consistency ──"
INSTALL_CUR=$(q "SELECT COALESCE(NULLIF(defaultCurrency,'auto'),'USD') FROM slots_settings")
[ "$CUR" = "$INSTALL_CUR" ] && ok "payment.currency ($CUR) matches the install currency" || bad "currency mismatch: $CUR vs $INSTALL_CUR"

# ── Scenario 3: CRITICAL — GC never cancels a paid booking ──
echo "── 3. GC paid-exclusion (critical race guard) ──"
GTOK="int-gc-$(q 'SELECT LOWER(HEX(RANDOM_BYTES(6)))')"
sql "INSERT INTO slots_reservations (bookingDate,confirmationToken,userEmail,userName,status,serviceId,quantity,dateCreated,dateUpdated,uid) VALUES ('2026-09-20','$GTOK','int@example.com','GCpaid','pending',$SERVICE_ID,1,'2026-07-01 00:00:00',NOW(),UUID());"
GID=$(q "SELECT id FROM slots_reservations WHERE confirmationToken='$GTOK';")
sql "INSERT INTO slots_payments (reservationId,gateway,externalId,status,amount,currency,refundedAmount,dateCreated,dateUpdated,uid) VALUES ($GID,'stripe','pi_int_paid_$GID','paid',5000,'$INSTALL_CUR',0,NOW(),NOW(),UUID());"
ddev exec 'cd /var/www/html && php craft gc' >/dev/null 2>&1
GST=$(q "SELECT status FROM slots_reservations WHERE id=$GID")
[ "$GST" != "cancelled" ] && ok "GC did NOT cancel the paid-but-pending booking (status=$GST)" || bad "GC cancelled a paid booking!"

# ── Scenario 4: GC DOES cancel an unpaid stale pending ──
echo "── 4. GC cancels an unpaid stale pending ──"
UTOK="int-gcu-$(q 'SELECT LOWER(HEX(RANDOM_BYTES(6)))')"
sql "INSERT INTO slots_reservations (bookingDate,confirmationToken,userEmail,userName,status,serviceId,quantity,dateCreated,dateUpdated,uid) VALUES ('2026-09-20','$UTOK','int@example.com','GCunpaid','pending',$SERVICE_ID,1,'2026-07-01 00:00:00',NOW(),UUID());"
UID2=$(q "SELECT id FROM slots_reservations WHERE confirmationToken='$UTOK';")
ddev exec 'cd /var/www/html && php craft gc' >/dev/null 2>&1
[ "$(q "SELECT status FROM slots_reservations WHERE id=$UID2")" = "cancelled" ] && ok "GC cancelled the unpaid stale pending" || bad "GC left an abandoned pending"

# ── Scenario 5: reconcile recovers a missed webhook ──
echo "── 5. reconcile: pending record for a paid intent → paid ──"
RPI=$(stripe payment_intents create -d amount=4200 -d currency=usd -d "payment_method=pm_card_visa" -d confirm=true -d "automatic_payment_methods[enabled]=true" -d "automatic_payment_methods[allow_redirects]=never" 2>/dev/null | python3 -c "import json,sys;print(json.load(sys.stdin)['id'])")
RTOK2="int-rec-$(q 'SELECT LOWER(HEX(RANDOM_BYTES(6)))')"
sql "INSERT INTO slots_reservations (bookingDate,confirmationToken,userEmail,userName,status,serviceId,quantity,dateCreated,dateUpdated,uid) VALUES ('2026-09-20','$RTOK2','int@example.com','Recon','pending',$SERVICE_ID,1,NOW(),NOW(),UUID());"
RRID=$(q "SELECT id FROM slots_reservations WHERE confirmationToken='$RTOK2';")
sql "INSERT INTO slots_payments (reservationId,gateway,externalId,status,amount,currency,refundedAmount,dateCreated,dateUpdated,uid) VALUES ($RRID,'stripe','$RPI','pending',4200,'$INSTALL_CUR',0,NOW(),NOW(),UUID());"
ddev exec 'cd /var/www/html && php craft slots/payments/reconcile' >/dev/null 2>&1
[ "$(q "SELECT status FROM slots_payments WHERE externalId='$RPI'")" = "paid" ] && ok "reconcile flipped the pending record to paid" || bad "reconcile did not recover the payment"

# ── Scenario 6: dashboard refund syncs (monotonic, absolute) ──
echo "── 6. dashboard refund → record synced ──"
if [ -n "${INTENT:-}" ]; then
  stripe refunds create -d "payment_intent=$INTENT" -d amount=1500 >/dev/null 2>&1
  sleep 5
  RA=$(q "SELECT refundedAmount FROM slots_payments WHERE externalId='$INTENT'")
  [ "$RA" = "1500" ] && ok "refundedAmount synced to 1500" || bad "refund sync = $RA (expected 1500)"
  [ "$(q "SELECT status FROM slots_payments WHERE externalId='$INTENT'")" = "partiallyRefunded" ] && ok "status → partiallyRefunded" || bad "status wrong after refund"
fi

echo "── cleanup ──"; cleanup
echo ""
echo "══════════════════════════════════════════════════"
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ] && echo "✅ Integration suite GREEN" || { echo "❌ Failures above"; exit 1; }
