# Changelog

All notable changes to Slots are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

### Changed
- The `stripe/stripe-php` requirement is now a range (`^13.0` through `^21.0`) instead of `^21.0` only,
  so Slots can be installed alongside plugins that cap the same SDK — Formie caps it at `^16`, Craft
  Commerce's Stripe gateway at `^13`. Slots only uses Payment Intents, refunds and webhook signature
  verification, all unchanged across that range ([#8](https://github.com/anvildevxyz/craft-slots/issues/8))

## 1.0.0 - 2026-08-02

First release. See the [README](README.md) for what this plugin does, and
[CONFIGURATION.md](CONFIGURATION.md) for how to set it up.

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
