# Slots

Straightforward appointment booking for Craft CMS, with Stripe payments built in.

Slots is for a business that books time: a salon, a clinic, a studio, a workshop. Customers pick a
service, a member of staff and a slot, pay if the service costs money, and get a confirmation email. You
manage it from the control panel.

**One composer dependency.** No Craft Commerce, no OAuth apps, no front-end framework.

## Features

### Booking
- **Services** with their own duration, price, buffers and booking window
- **Staff and locations** — assign staff to services, run more than one location, each with its own timezone
- **Availability** from recurring schedules, one-off windows and blackout dates
- **Group bookings** with per-slot capacity limits
- **Soft locking** so two people can't take the same slot while one of them is still typing
- **Booking wizard** — a zero-dependency, CSP-safe multi-step form that skips any step with only one
  option, so a single-service single-staff setup is just "pick a time"

### Payments
- **Stripe, directly.** Card details are entered in-page with Stripe Elements; the booking is confirmed by
  a signature-verified webhook, not by the browser
- **Refunds** — full or partial, clamped to your cancellation policy, issued from the control panel;
  refunds made in the Stripe dashboard sync back
- **Free bookings** work with no payment configuration at all

### Everything else
- **Email** — confirmation, reminder, cancellation and reschedule notices, all templates overridable
- **Customer self-service** — cancel, reschedule, adjust quantity and download an `.ics` file from a
  signed link, no account required
- **Account portal** — ready-made pages at `/slots/account` for logged-in customers; bookings resolve by
  user ID *or* email, so ones made as a guest still show up after they register
- **Customers view** — every booking grouped by the address that made it
- **Anti-spam** — honeypot, rate limiting, and reCAPTCHA / hCaptcha / Turnstile
- **Multi-site** — services are localised and propagate across sites
- **Revenue reporting** and a CSV export of bookings
- **8 languages** — English, German, French, Spanish, Italian, Japanese, Dutch, Portuguese

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- MySQL 8.0.17+ or PostgreSQL 13+
- A Stripe account, only if you charge for bookings

## Installation

```bash
composer require anvildev/craft-slots
php craft plugin/install slots
```

With DDEV:

```bash
ddev composer require anvildev/craft-slots
ddev php craft plugin/install slots
```

Then drop the wizard into a template:

```twig
{% include 'slots/frontend/wizard' %}
```

## Documentation

**Start here**
- [Tutorial](TUTORIAL.md) — a working booking page in 15 minutes

**Setup**
- [Configuration](CONFIGURATION.md) — every setting, and the config file
- [Payments](docs/payments-setup.md) — Stripe keys, the webhook endpoint, CSP, test cards
- [Availability](AVAILABILITY.md) — how schedules, windows and blackout dates combine into slots
- [Email templates](EMAIL_TEMPLATES.md) — overriding the confirmation, reminder and cancellation emails

**Building on it**
- [Developer guide](DEVELOPER_GUIDE.md) — services, queries and extension points
- [Event system](EVENT_SYSTEM.md) — hooks for custom business logic
- [Field types](FIELD_TYPES.md) — relating entries to services
- [Console commands](CONSOLE_COMMANDS.md) — reminders, payments, diagnostics
- [Booking wizard](docs/VANILLA_WIZARD.md) — theming and driving the front-end wizard

## Is this the right plugin?

Slots is deliberately small, and staying that way is the point. It does **not** do Craft Commerce
checkout, Google or Outlook calendar sync, Zoom/Meet/Teams meetings, SMS, outgoing webhooks, GraphQL,
event-based ticketing, waitlists, multi-day stays or service add-ons — and it will not grow them.

If your booking flow needs any of those, this is the wrong plugin and no amount of configuration will
change that. If it needs a service, a member of staff, a time and a card payment, there is nothing here
to get in your way.

## Support

- **Issues**: [GitHub Issues](https://github.com/anvildevxyz/craft-slots/issues)

## License

Copyright © anvildev. All rights reserved.

## Credits

Developed by anvildev for Craft CMS.

Built with [Craft CMS](https://craftcms.com), [Yii](https://www.yiiframework.com) and
[Stripe](https://stripe.com).
