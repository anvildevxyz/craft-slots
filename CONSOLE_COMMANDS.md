# Console Commands

All commands are run via `php craft <command>`.

## Doctor (Health Check)

```bash
php craft slots/doctor         # Run all health checks
```

Validates database tables, settings, email config, data presence (services/employees/locations/schedules), direct payments (Stripe keys, webhook secret, gateway, currency), CAPTCHA, and queue status. Returns exit code `0` on success, `1` on errors.

## Bookings

### `slots/bookings/list`

```bash
php craft slots/bookings/list
php craft slots/bookings/list --date=2026-03-01 --status=confirmed --limit=50
```

| Option | Default | Description |
|--------|---------|-------------|
| `--date` | *(none)* | Filter by date (Y-m-d) |
| `--status` | *(none)* | `pending`, `confirmed`, `cancelled` |
| `--limit` | `20` | Max results |

### `slots/bookings/validate`

Runs a data integrity check across all bookings. Scans every reservation and reports errors and warnings:

- **Errors:** missing customer email, missing booking date, missing start/end time, references to deleted services, invalid status values
- **Warnings:** orphaned employee or location references, start time >= end time, bookings not linked to any service

```bash
php craft slots/bookings/validate
```

Returns exit code `0` if no errors are found (warnings are allowed), `1` if any errors are detected.

### `slots/bookings/info <id>`

Shows full booking details: customer data, service, employee, location, payment status, notification tracking, and timestamps.

```bash
php craft slots/bookings/info 42
```

### `slots/bookings/cancel <id>`

Cancels a booking after a confirmation prompt.

```bash
php craft slots/bookings/cancel 42 --reason="Customer requested reschedule"
```

### `slots/bookings/export`

Export bookings to stdout in CSV or JSON format.

```bash
php craft slots/bookings/export > bookings.csv
php craft slots/bookings/export --format=json --from=2026-03-01 --to=2026-03-31 --status=confirmed > march.json
```

| Option | Default | Description |
|--------|---------|-------------|
| `--format` | `csv` | `csv` or `json` |
| `--from` | *(none)* | Start date (Y-m-d) |
| `--to` | *(none)* | End date (Y-m-d) |
| `--status` | *(none)* | Filter by status |

**CSV columns:** `id`, `status`, `bookingDate`, `startTime`, `endTime`, `duration`, `quantity`, `customerName`, `customerEmail`, `customerPhone`, `service`, `serviceId`, `employee`, `employeeId`, `location`, `locationId`, `notes`, `confirmationToken`, `createdAt`

### `slots/bookings/mark-no-shows`

Mark confirmed bookings as no-show once their end time plus a grace period has passed without check-in.
Intended for cron; run it with `--dry-run` first to see what it would touch.

```bash
php craft slots/bookings/mark-no-shows
php craft slots/bookings/mark-no-shows --grace-period=30 --dry-run
```

| Option | Default | Description |
|--------|---------|-------------|
| `--grace-period` | `30` | Minutes to wait past the booking's end time before marking it |
| `--dry-run` | `false` | Report what would change, without writing |

## Reminders

```bash
php craft slots/reminders/send   # Send pending reminders synchronously
php craft slots/reminders/queue  # Queue reminders for async processing (recommended for cron)
```


## Payments

```bash
php craft slots/payments/reconcile              # Re-query non-finalized payments at the gateway
php craft slots/payments/reconcile --dry-run    # Report what would change, without writing
php craft slots/payments/reconcile --since=7    # Only look at payments from the last N days
```

Reconcile is the safety net for a webhook that never arrived: it asks Stripe for the current state of
every payment still marked pending and applies the result idempotently, so running it twice is harmless.
