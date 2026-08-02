# Plugin Store listing — Slots

Draft copy for the Craft Plugin Store. Not shipped to users; this file is the source of truth for what
goes into the Store form so the wording stays consistent with the README.

## Name

Slots

## Handle

`slots`

## Short description

*(Store limit is short — this is the one-liner under the plugin name.)*

> Straightforward appointment booking with Stripe payments built in.

## Long description

Slots is appointment booking for a business that books time — a salon, a clinic, a studio, a
workshop. Customers pick a service, a member of staff and a slot, pay if the service costs money, and get
a confirmation email. You manage it all from the control panel.

It is deliberately small. One composer dependency. No Craft Commerce, no OAuth apps to register, no
front-end framework to load.

**Booking**
- Services with their own duration, price, buffers and booking window
- Staff and locations, each location with its own timezone
- Availability built from recurring schedules, one-off windows and blackout dates
- Group bookings with per-slot capacity
- Soft locking, so two customers can't claim the same slot while one is still typing
- A zero-dependency, CSP-safe booking wizard that skips any step with only one option — a single-service,
  single-staff setup is just "pick a time"

**Payments**
- Stripe, directly. Card details are entered in-page with Stripe Elements
- Bookings are confirmed by a signature-verified webhook, not by the browser
- Full and partial refunds from the control panel, clamped to your cancellation policy; refunds issued in
  the Stripe dashboard sync back automatically
- Free bookings work with no payment configuration at all

**Everything else**
- Confirmation, reminder, cancellation and reschedule emails, all templates overridable
- Customers cancel, reschedule and download an `.ics` file from a signed link — no account required
- Bookings are first-class Craft elements with a native element index, queryable in Twig
- Honeypot, rate limiting, and reCAPTCHA / hCaptcha / Turnstile
- Multi-site: services are localised and propagate
- Revenue reporting and a CSV export of bookings
- Translated into English, German, French, Spanish, Italian, Japanese, Dutch and Portuguese

**Is this the right plugin for you?**

Slots does not do Craft Commerce checkout, Google or Outlook calendar sync, Zoom/Meet/Teams meetings,
SMS, outgoing webhooks, GraphQL, event-based ticketing, waitlists, multi-day stays or service add-ons.
That is a design decision, not a roadmap — it will not grow them. If your booking flow needs a service, a
member of staff, a time and a card payment, there is nothing here to get in your way.

## Categories

Primary: **Ecommerce** (payments) — secondary: **Utilities**

*(Confirm against the Store's current category list before submitting.)*

## Keywords

booking, appointments, scheduling, reservations, stripe, payments, calendar, availability

## Requirements

Craft CMS 5.0+, PHP 8.2+, MySQL 8.0.17+ or PostgreSQL 13+. A Stripe account only if you charge for
bookings.

## Screenshots to capture

Ordered by what sells the plugin fastest. All at 2x on a clean install with the seeded demo data.

1. **The booking wizard, mid-flow** — the time-slot step on a real front-end page. This is the product.
2. **Stripe payment step** — the in-page Stripe Element, showing payment happens without leaving the site
3. **Bookings index** in the CP, with a few bookings across statuses
4. **Booking detail** with the payment panel and the refund control visible
5. **Service edit screen** — duration, price, buffers, booking window
6. **Availability / schedule editor** — the weekly pattern with breaks
7. **Settings → Payments** — the three-field Stripe setup, to show how little there is
8. **Revenue report**

## Pricing

**$69 per site, $29/year renewal.** Single edition — no tiers.

The plugin Slots actually competes with is [Stub](https://craft-stub.com/) at $79 + $59/year: same pitch
(multi-provider scheduling, vanilla-JS step wizard, direct Stripe, no Commerce, Craft 5 / PHP 8.2). The
rest of the category sells a different product — Solspace Events $149 + $45/yr and Owl (free–$149) are
events and Commerce ticketing, and Showtime at $249 bundles memberships.

$69 undercuts Stub on the sticker price, and the renewal undercuts it by more than half. Over three years
that is **$127 against Stub's $197** — a spread worth stating outright in the listing.

Pricing low is also what keeps the product honest: at $69 there is no pressure to bolt on the integrations
the Scope doc rules out just to justify a higher number.

Where Slots beats Stub, and what the copy should lean on: 8 translated locales (Stub claims only
translatable emails), full and partial policy-clamped refunds with Stripe-dashboard refunds syncing back,
group bookings with per-slot capacity, soft locking, blackout dates, multi-site propagation, and
honeypot / rate limiting / captcha.

Reservations are first-class Craft elements with a native element index, so they are queryable in Twig
and behave the way a Craft developer expects. This was the one gap that would have lost a head-to-head
against Stub, and it is closed — the copy should say so plainly rather than leaving it implied.

Customer-to-user linking is covered too: reservations carry a `userId`, and `craft.slots.myBookings()`
resolves the current user by ID *or* email, so a logged-in customer sees bookings they made as a guest
before registering. Worth a line in the listing — it reads as a missing feature until stated.

## Still to do before submitting

- [x] **Plugin icon** — calendar with a marked slot, 237×237 dark card, plus the CP-nav mask
- [x] **Pricing and edition** — $69 + $29/yr, single edition (see Pricing above)
- [ ] Capture the screenshots above
- [ ] Confirm the Store category names against the live list
- [ ] Transfer the repo to **`anvildevxyz/craft-slots`** and make it public. The support URLs in
      `composer.json` and the README already point there, so they 404 until the transfer happens.
