# Direct payments (Stripe)

Slots takes **paid bookings through Stripe** — no Craft Commerce required.
A booking with a price is created as *pending*, the customer pays in-page with
Stripe's Payment Element, and the booking is confirmed by a verified Stripe
webhook. This guide covers setup, testing, and troubleshooting.


---

## 1. Payment modes

**Settings → Payments** is the single place you choose how you get paid:

| Mode | Behaviour |
|------|-----------|
| `none` | Bookings are free; no payment is taken. |
| `direct` | Native Stripe checkout. **This guide.** |

Pick the mode (and the currency) on the **Payments** tab.

A service with a **price** is what triggers payment. Free services always confirm
immediately, regardless of mode.

---

## 2. Stripe keys

From your [Stripe dashboard](https://dashboard.stripe.com/apikeys), copy your
**publishable key** (`pk_…`) and **secret key** (`sk_…`) into
**Settings → Payments**.

Store them as environment variables and reference them, so secrets never live in
project config or the database:

```bash
# .env
STRIPE_PUBLISHABLE_KEY=pk_test_xxx
STRIPE_SECRET_KEY=sk_test_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
```

Then enter `$STRIPE_PUBLISHABLE_KEY`, `$STRIPE_SECRET_KEY`, and
`$STRIPE_WEBHOOK_SECRET` in the settings fields.

Use **test** keys (`sk_test_…`/`pk_test_…`) in development and **live** keys in
production. Slots's health check warns when they don't match the environment
(see §7).

---

## 3. Webhook (required)

The webhook is the **source of truth** — a booking is confirmed when Stripe
tells Slots the payment succeeded. Without it, payments never confirm.

1. In Stripe → **Developers → Webhooks → Add endpoint**, set the URL to:

   ```
   https://your-site.example/slots/api/v1/payment/webhook/stripe
   ```

2. Subscribe to at least these events:
   - `payment_intent.succeeded`
   - `charge.refunded` *(so refunds issued in the Stripe dashboard sync back to Slots)*

3. Copy the endpoint's **signing secret** (`whsec_…`) into
   **Settings → Payments → Stripe webhook secret** (as `$STRIPE_WEBHOOK_SECRET`).

Slots verifies every webhook's signature and ignores unsigned or replayed
events.

### Testing the webhook locally

Use the [Stripe CLI](https://stripe.com/docs/stripe-cli):

```bash
stripe listen --forward-to https://your-site.ddev.site/slots/api/v1/payment/webhook/stripe
```

`stripe listen` prints a `whsec_…` secret — put that in your settings while
testing.

---

## 4. Content Security Policy

Stripe's Payment Element loads `js.stripe.com` and renders card fields in
Stripe-hosted iframes. If your site sends a strict CSP, **allow Stripe** on
pages that show the booking wizard:

```
script-src  https://js.stripe.com;
frame-src   https://js.stripe.com https://hooks.stripe.com;
connect-src https://api.stripe.com;
```

Free bookings need none of this — only direct-payment pages load
Stripe.

---

## 5. Currency, refunds, and abandoned checkouts

- **Currency** — set **Settings → Payments → Currency** (install-wide). `auto`
  falls back to USD.
- **Refund policy** — the same time-based refund policy (per service / event
  date, or the install default) governs how much of a payment can be refunded.
  Refunds are issued from the **booking's edit screen** by anyone with the
  *Issue refunds* permission, or synced automatically if issued in the Stripe
  dashboard.
- **Pending-payment TTL** — a booking whose checkout is abandoned is cancelled
  after `pendingPaymentTtlMinutes` (default 30), freeing the slot. Adjust it in
  **Settings → Payments**.

---

## 6. Testing a payment

With test keys and the webhook running, book a paid service and pay with a
[Stripe test card](https://stripe.com/docs/testing):

| Card | Result |
|------|--------|
| `4242 4242 4242 4242` | Succeeds |
| `4000 0025 0000 3155` | Requires 3-D Secure authentication |
| `4000 0000 0000 9995` | Declined (insufficient funds) |

Any future expiry, any CVC, any postal code. After a successful payment the
booking flips from *pending* to *confirmed*.

---

## 7. Operations & troubleshooting

**Check your configuration:**

```bash
php craft slots/doctor
```

In direct mode this reports on your keys, the webhook secret, the registered
gateway, and the resolved currency — and warns about live/test-vs-environment
mismatches.

**Reconcile after a missed webhook:**

```bash
php craft slots/payments/reconcile            # confirm any paid-but-pending records
php craft slots/payments/reconcile --dry-run  # report only, change nothing
php craft slots/payments/reconcile --since=30 # look back 30 days (default 7)
```

This re-queries Stripe for every non-finalized payment and confirms any that
Stripe reports as paid — a safety net if a webhook was dropped.

**Common issues**

| Symptom | Likely cause |
|---------|--------------|
| Payment succeeds at Stripe but the booking stays *pending* | Webhook not configured, or the wrong/blank **webhook secret**. Check §3 and run `reconcile`. |
| Card fields don't render | CSP is blocking `js.stripe.com` (see §4), or the publishable key is missing/wrong. |
| "Payment is currently unavailable" | Secret key missing/invalid, or the Stripe gateway isn't reachable. Run `slots/doctor`. |
| No real charges in production | **Test** keys are still configured. `slots/doctor` warns about this. |
| A refund in Stripe isn't reflected in Slots | Subscribe the webhook to `charge.refunded` (§3). |
