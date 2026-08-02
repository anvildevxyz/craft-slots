#!/usr/bin/env bash
#
# Live regression check: timeless bookings must not crash timed-slot availability.
#
# Whole-day / flexible-day services store reservations with a NULL startTime and
# endTime. Those reservations used to leak into the timed-slot overlap math
# (`filterDeduplicatedSoftLocks`, `subtractBookingsFromWindows`) and throw
# `TimeWindowService::timeToMinutes()/addMinutes(): ... null given`, taking down
# the whole availability calendar for unrelated timed services.
#
# This drives the REAL availability endpoints against the REAL database and
# asserts the calendar responds for a timed service AND for a flexible-day
# service, given at least one timeless booking exists. Read-only.
#
# Usage:  bash tests/integration-live/availability-timeless-bookings.sh
set -uo pipefail
BASE="${SLOTS_BASE:-https://craft-plugin-dev.ddev.site}"
API="$BASE/slots/api/v1"
TIMED_ID="${TIMED_SERVICE_ID:-9433}"       # a timed, employee-based service
DAY_ID="${DAY_SERVICE_ID:-1219}"           # a flexible-day service
PASS=0; FAIL=0
ok(){ echo "  ✓ $1"; PASS=$((PASS+1)); }
bad(){ echo "  ✗ $1"; FAIL=$((FAIL+1)); }
q(){ ddev mysql -N -e "$1" 2>/dev/null; }

# Assert the endpoint responds without an exception (success flag present, no exception key).
assert_no_exception(){ # $1=label $2=url
  local body
  body=$(curl -sS -k -H 'Accept: application/json' -H 'X-Requested-With: XMLHttpRequest' "$2" 2>/dev/null)
  echo "$body" | python3 -c "import sys,json;d=json.load(sys.stdin);sys.exit(0 if (d.get('success') is not None and not d.get('exception')) else 1)" 2>/dev/null \
    && ok "$1" \
    || bad "$1 — $(echo "$body" | python3 -c "import sys,json;print(json.load(sys.stdin).get('message',''))" 2>/dev/null | head -c 100)"
}

echo "════════ Availability timeless-booking regression ════════"

TIMELESS=$(q "SELECT COUNT(*) FROM slots_reservations WHERE (startTime IS NULL OR endTime IS NULL) AND status <> 'cancelled';")
[ "${TIMELESS:-0}" -ge 1 ] && ok "precondition: $TIMELESS timeless booking(s) present" \
  || bad "precondition: no timeless bookings in the DB — this check is meaningless without one"

assert_no_exception "timed service #$TIMED_ID calendar responds (no null-time crash)" \
  "$API/availability/calendar?serviceId=$TIMED_ID&site=default"
assert_no_exception "flexible-day service #$DAY_ID calendar responds (no null-time crash)" \
  "$API/availability/calendar?serviceId=$DAY_ID&site=default"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
