# Control-panel end-to-end smoke

Two Playwright scripts that drive the real control panel. They exist because the
PHPUnit suite never boots Craft: it can prove a route resolves and a template
file exists, but not that the screen renders without a 500.

That gap was not theoretical. The strip-down shipped a deleted calendar view, a
CP stylesheet that failed to load, and two templates with unbalanced Twig tags —
all with a green unit suite.

## Running

```bash
export SLOTS_CP_USER='admin@example.test'
export SLOTS_CP_PASS='…'
export SLOTS_CP_BASE='https://your-site.ddev.site'   # optional
export CHROME_PATH="$(ls -d ~/Library/Caches/ms-playwright/chromium-*/chrome-mac/Chromium.app/Contents/MacOS/Chromium | tail -1)"

node tests/e2e/cp-smoke.mjs   # loads every CP screen, reports non-200s and JS errors
node tests/e2e/cp-crud.mjs    # creates one of each entity through the real forms
```

Credentials come from the environment and are never committed.

## What each covers

**`cp-smoke.mjs`** — every CP screen: dashboard, bookings (index + new), the three
calendar views, all five element indexes and their edit forms, reports, and all
four settings tabs. Flags a non-200, a Craft exception rendered into the page, a
missing CP stylesheet, or a JS console error.

**`cp-crud.mjs`** — the save paths, which is where field handling was removed:
creates a location, service, schedule, employee and blackout date through the
forms, re-saves an existing record, and saves the settings tabs.

`cp-crud.mjs` leaves rows behind, prefixed `CPSMOKE`. Clean up with:

```sql
DELETE FROM elements WHERE id IN (
  SELECT elementId FROM elements_sites WHERE title LIKE 'CPSMOKE%'
);
```

## What they do not cover

The front-end booking wizard and the Stripe payment flow. Those need a booking
page and test keys — see the wizard notes in `docs/VANILLA_WIZARD.md`.

## brand-check.mjs

Loads every Slots-owned CP screen plus the front-end wizard and fails if any
visible text still shows stale upstream branding. The literal string it looks
for is defined at the top of the script.

`NoUpstreamBrandingTest` covers the source tree; this covers what a user
actually sees, which is not the same thing — the translated `plugin.name` is
what `Slots::displayName()` renders, and all eight catalogs carried the old name
long after the source looked clean.

It scopes each scan to the page's content area on purpose. A dev site running
another booking plugin alongside Slots will legitimately show that plugin's name
in the shared CP chrome (nav, plugin list, permission tree); only the Slots
content area is ours.

```sh
SLOTS_CP_USER=… SLOTS_CP_PASS=… node tests/e2e/brand-check.mjs
```

## wizard-battery.mjs

Everything the wizard has to survive that isn't the happy path — 14 scenarios
covering validation, back navigation, calendar bounds, quantity limits,
accessibility, double submits, soft-lock conflicts and a dead availability
endpoint.

```sh
node tests/e2e/wizard-battery.mjs                    # all
node tests/e2e/wizard-battery.mjs quantity-controls  # one
```

Two setup notes, both learned the hard way:

* **Seed data drives coverage.** The service step only appears with two or more
  bookable services, and the quantity picker only appears when a slot holds more
  than one seat — which happens only for *employee-less* services, since a
  service with a practitioner attached has a capacity of exactly that one
  person. Without a group service seeded, `quantity-controls` can only skip.
* **It resets state between scenarios.** Each slot click takes a soft lock that
  outlives the page, and the month calendar is cached for 300s without
  invalidation, so a stale calendar keeps showing days as full after its
  bookings are deleted. `SLOTS_RESET_CMD` clears both; set it to `''` to opt
  out and expect scenarios to contend.

## Postgres

The plugin advertises MySQL *or* Postgres, and the two disagree. Postgres folds
an unquoted identifier to lowercase, and Yii only quotes column names it
recognises as simple references — a raw join condition or a raw expression goes
through untouched. Three control-panel screens shipped a 500 on Postgres for
that reason while every MySQL run was green.

A standing Postgres 16 install lives at `~/Documents/experiments/cartograph-pg-test`.
It holds a **copy** of this plugin, so start by refreshing it — otherwise you are
testing whatever was current the last time somebody looked:

```sh
PG=~/Documents/experiments/cartograph-pg-test
rsync -a --exclude vendor --exclude node_modules --exclude .git \
  --exclude .phpstan --exclude test-results \
  ./ "$PG/plugins/slots/"
cd "$PG" && ddev exec php craft clear-caches/all
```

Then point the suites at it:

```sh
export SLOTS_CP_BASE=https://cartograph-pg-test.ddev.site
export SLOTS_CP_USER=admin SLOTS_CP_PASS=…
node tests/e2e/cp-smoke.mjs
node tests/e2e/customers-smoke.mjs      # the rawest SQL in the plugin
node tests/e2e/bookings-index-smoke.mjs
```

Four things that cost time the first time:

* **Run it empty, then with rows.** Postgres only enforces its GROUP BY rules
  once there is data, so an empty pass proves less than it looks. `cp-crud.mjs`
  creates one of each entity; `tests/integration-live/reservation-lifecycle.php
  seed <hours> <email>` adds bookings.
* **`ddev craft` is unavailable there** — the project type is `php`, so use
  `ddev exec php craft …`.
* **Copy the plugin in, don't symlink it.** A symlink pointing outside the
  project does not resolve inside the container.
* **Expected counts differ.** The suites take them from the environment
  (`SLOTS_EXPECT_TOTAL`, `SLOTS_SEARCH_TERM`, …); the defaults describe the MySQL
  dev site.
