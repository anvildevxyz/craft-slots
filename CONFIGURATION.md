# Configuration Guide

All settings are configured via **Settings → Slots** in the Craft control panel.

Store sensitive credentials (API keys, secrets) in `.env` and reference them via environment variable fields in the CP.

---

## General

| Setting | Default | Description |
|---------|---------|-------------|
| Default Currency | Auto-detect | ISO 4217 code; falls back to CHF |
| Soft Lock Duration | 5 min | Holds slot while customer completes booking |
| Minimum Advance Booking | 0 hours | 0 = no minimum |
| Maximum Advance Booking | 90 days | |
| Cancellation Policy | 24 hours | 0 = no deadline |
| Default Time Slot Length | Service duration | See below |
| Mutex Driver | `auto` | Lock driver for booking concurrency (`auto`, `file`, `db`, `redis`) |
| Booking Page URL | — | Public URL to the booking page, used in notification links (`null` = auto-detect) |

### Time Slot Interval

The slot interval determines how often available times appear in the booking calendar. This is separate from the service duration.

**Fallback chain**: Service `timeSlotLength` → Global `defaultTimeSlotLength` → Service `duration`

Example with `defaultTimeSlotLength` = 15:
- 30 min massage → slots at 09:00, 09:15, 09:30...
- 60 min facial → slots at 09:00, 09:15, 09:30...
- 90 min package (own `timeSlotLength` = 30) → slots at 09:00, 09:30, 10:00...

---

## Security

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Rate Limiting | Yes | |
| Rate Limit Per Email | 5 | Max bookings per email per day |
| Rate Limit Per IP | 10 | Max bookings per IP per day |
| Enable Honeypot | Yes | |
| Honeypot Field Name | `website` | |
| Enable IP Blocking | No | |
| Blocked IPs | — | JSON-encoded array of IPs |
| Enable Time-Based Limits | Yes | |
| Minimum Submission Time | 3 sec | Seconds between submissions |
| Enable Audit Log | No | Logs to `@storage/logs/slots-audit.log` |

**Important**: The global rate limits are checked **before** per-service customer limits. If you set `Rate Limit Per Email` to 5, a customer is blocked after 5 bookings that day even if the service allows 10 per week. Set the global limit high enough to accommodate your per-service customer limits.

### CAPTCHA

Supports reCAPTCHA v3, hCaptcha, and Cloudflare Turnstile. Select a provider in the CP and enter your site key and secret key. Store keys in `.env` and use environment variable fields in the CP.

| Setting | Default | Description |
|---------|---------|-------------|
| reCAPTCHA Score Threshold | 0.5 | Minimum score (0–1) to accept a reCAPTCHA v3 submission |
| reCAPTCHA Action | `booking` | Action name sent with reCAPTCHA v3 verification requests |

---

## Email Notifications

| Setting | Default | Description |
|---------|---------|-------------|
| Owner Notification Enabled | Yes | |
| Owner Email | — | Falls back to Craft system email |
| Owner Name | — | Falls back to Craft system sender name |
| Owner Notification Subject | — | `null` = translated default |
| Owner Notification Language | — | e.g. `de`, `null` = primary site language |
| Booking Confirmation Subject | — | `null` = translated default |
| Reminder Email Subject | — | `null` = translated default |
| Cancellation Email Subject | — | `null` = translated default |
| Email Reminders Enabled | Yes | |
| Email Reminder Hours Before | 24 | |
| Send Cancellation Email | Yes | |

Reminders require a cron job — see [Console Commands](CONSOLE_COMMANDS.md).

---

## Cancellation

Cancellation can be controlled at two levels:

### Per-Service Toggle

**Service** elements have an **Allow Cancellation** toggle (enabled by default). When disabled, customers cannot cancel bookings for that service — the cancel button is hidden and the cancellation endpoint rejects requests.

### Global Cancellation Policy

The **Cancellation Policy** setting under **Settings → Slots → Booking** sets the minimum hours before an appointment that cancellation is allowed (default: 24 hours, 0 = no deadline).

---

## Refunds

| Setting | Default | Description |
|---------|---------|-------------|
| Enable Auto Refund | No | Automatically process refunds when bookings are cancelled |
| Default Refund Tiers | — | JSON-encoded array of refund percentage tiers based on time before appointment |


**Refund tiers** define what percentage of the booking price is refunded based on how far in advance the cancellation occurs. Example:

```json
[
  { "hoursBeforeStart": 48, "refundPercentage": 100 },
  { "hoursBeforeStart": 24, "refundPercentage": 50 },
  { "hoursBeforeStart": 0, "refundPercentage": 0 }
]
```

This means: full refund if cancelled 48+ hours before, 50% if 24-48 hours, no refund within 24 hours.

---

## Staff Access

A staff member sees only the bookings for the employee record they are linked to.
That link is per-employee, not a setting.

1. **Link the employee to a Craft user** — Employee edit page → User field (one user
   per employee).
2. **Grant the user's group three permissions.** All three are needed, and missing
   any one of them produces a 403 rather than an empty screen:

   | Permission | Where it lives | Why |
   |---|---|---|
   | Access the control panel | Craft, General | Lets a non-admin reach `/admin` at all |
   | Access Slots | Craft, per-plugin | Craft gates every `/admin/slots/…` URL on this |
   | Slots → View bookings | this plugin | Shows the Bookings screen, scoped to their employee |

   Craft's "Access Slots" and this plugin's own "Slots" permission tree are two
   different things with similar names. Both are required.

| Role | Permissions | Sees |
|------|-------------|------|
| Staff | the three above | Only their own employee's bookings |
| Supervisor | add `slots-manageBookings` | All bookings — this switches scoping off |
| Admin | Admin account | Everything |

Scoping applies to the bookings list, the calendar and the dashboard alike.

---

## Public Booking URLs

These token-based URLs are included in confirmation emails and require no authentication. The `{token}` is the reservation's unique confirmation token.

| URL | Purpose |
|-----|---------|
| `/booking/manage/{token}` | View booking details, reschedule, or cancel |
| `/booking/cancel/{token}` | Direct cancellation page |
| `/booking/ics/{token}` | Download `.ics` calendar file |

The ICS endpoint returns a `text/calendar` response with a `Content-Disposition: attachment` header, triggering a download in all browsers and email clients.

---

## Booking Wizard Behavior

The wizard adapts its flow automatically, skipping any step that has only one
possible answer:

- **Location step** is skipped when the service has its own schedule, only one
  location exists, or no locations are configured
- **Employee step** is skipped for schedule-based services (tours, classes)

| Service type | Location step | Employee step |
|---|---|---|
| Employee-based (massage, consultation) | Shows if multiple locations | Shows available employees |
| Schedule-based (tour, class) | Skipped | Skipped |

A single-service, single-staff setup therefore reduces to "pick a time".

---

## Environment Variables

Keep secrets out of project config: put them in `.env` and reference them from the
CP settings fields, which accept environment variables.

```bash
# .env
STRIPE_PUBLISHABLE_KEY=pk_live_…
STRIPE_SECRET_KEY=sk_live_…
STRIPE_WEBHOOK_SECRET=whsec_…
```

In **Settings → Slots → Payments**, enter `$STRIPE_SECRET_KEY` rather than the key
itself. The same applies to the CAPTCHA keys under Security.

---

## Next Steps

- [Email Templates](EMAIL_TEMPLATES.md) - Customize email notifications
- [Developer Guide](DEVELOPER_GUIDE.md) - API reference and extension guide
- [Event System](EVENT_SYSTEM.md) - Hook into the booking lifecycle
- [Console Commands](CONSOLE_COMMANDS.md) - CLI commands for reminders, cleanup, and diagnostics
