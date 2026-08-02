# Changelog

All notable changes to Slots are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 - 2026-08-02

First release. See [docs/SCOPE.md](docs/SCOPE.md) for the feature contract that defines what this plugin
does and, just as deliberately, what it does not.

### Added
- Appointment booking across services, staff and locations, with availability built from recurring
  schedules, one-off windows and blackout dates
- A zero-dependency, CSP-safe multi-step booking wizard that skips any step with only one option
- Group bookings with per-slot capacity, and soft locking so two customers can't claim the same slot
- Direct Stripe payments with in-page Stripe Elements, webhook-confirmed bookings, and full or partial
  policy-clamped refunds
- Reservations are first-class Craft elements, with a native element index, and are queryable in Twig
- Customer self-service from a signed link: cancel, reschedule, adjust quantity, download an `.ics` file
- Confirmation, reminder, cancellation, reschedule, status-change and quantity-change emails, plus an
  owner notification — every template overridable
- Translation catalogs for 8 locales, guarded in three directions: locale parity, no key referenced in
  source but missing from the catalogs, and no catalog key that nothing references

### Notes
- One third-party dependency (`stripe/stripe-php`)
- A fresh install builds the whole schema from `Install.php`; `schemaVersion` is 1.2.0
- MySQL 8.0.17+ and PostgreSQL 13+ are both supported. Postgres is verified by a manual
  browser-suite run against a Postgres install — see `tests/e2e/README.md` — not by the
  automated suite, which does not touch a database
