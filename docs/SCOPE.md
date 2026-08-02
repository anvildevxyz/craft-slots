# Slots — Scope

Slots is a **standalone, sellable plugin**.

Its pitch: **paid bookings for a small business — no Craft Commerce, no OAuth, no front-end framework.**
One composer dependency (`stripe/stripe-php`) instead of six.

This document is the contract. Anything not on the Keep list is out of scope, and adding to that list is
a product decision, not an implementation detail.

## Keep

| Area | Detail |
|---|---|
| Elements | Service, Employee, Location, Schedule, BlackoutDate, Reservation |
| Availability | Recurring schedules, one-time windows, blackout dates, soft locks, timezone conversion |
| Booking | Vanilla multi-step wizard (framework-free and CSP-safe) |
| Capacity | Group bookings with capacity limits |
| Payments | Direct Stripe only — full and partial refunds, with time-based refund tiers set per service or globally |
| Notifications | Email: confirmation, reminder, cancellation, reschedule, status change, quantity change, owner alert |
| Customer | Self-service cancel, reschedule, quantity change and `.ics` download via signed token link; account portal at `/slots/account` for logged-in users |
| Anti-spam | Honeypot + one captcha provider |
| Control Panel | Bookings element index, calendar view, customers view, settings, one revenue/bookings summary |
| Console | `bookings`, `payments`, `reminders`, `doctor` |

Multi-staff and multi-location stay. The core market is "one shop, a few staff, two locations" — the
elements already exist and cost nothing to retain.

## Drop

These are out of scope by decision, not by omission. Each one buys back the simplicity that is the
product's entire pitch.

| Dropped | Why |
|---|---|
| Craft Commerce integration | Would reintroduce a dual Element/ActiveRecord persistence model |
| Google + Microsoft calendar sync | 4 tables, 3 composer deps, an OAuth flow |
| Virtual meetings (Zoom / Meet / Teams) | Integration surface |
| SMS / Twilio | Integration surface, 1 composer dep |
| Webhooks | 2 tables, service, console command |
| GraphQL | Headless is not this product's market |
| MCP tools | Not this product's market |
| Event dates / event bookings | A second booking model |
| Waitlist | Element, 2 controllers, conversion flow, console command |
| Multi-day / flexible-day stays | A second availability model |
| Service extras | Pricing/duration complexity |
| Multi-report analytics dashboard | Reduced to one summary |
| Managed employees | Staff hierarchy |
| Front-end employee schedule management | Self-service surface |

## Tables

14.

**Installed:** `services`, `employees`, `locations`, `schedules`, `blackout_dates`,
`blackout_dates_employees`, `blackout_dates_locations`, `service_locations`,
`employee_schedule_assignments`, `service_schedule_assignments`, `reservations`, `payments`,
`settings`, `soft_locks`.

## Non-goals

- Any integration requiring OAuth, a second SaaS account, or a webhook receiver other than Stripe's.
- A second payment gateway before there is customer demand.
- Any edition, tier, or feature-gating mechanism inside this plugin.
