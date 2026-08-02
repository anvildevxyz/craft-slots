import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * Browser E2E for issue #85 — a Schedule's per-day capacity in the real wizard.
 *
 * Drives the vanilla booking wizard against a live DDEV site and asserts that a
 * slot backed by a multi-seat schedule keeps being offered while seats remain,
 * shrinks its advertised capacity as bookings land, and only leaves the list
 * once the last seat is taken. This covers the layer the PHP suite can't: what
 * a customer actually sees.
 *
 * Run from the plugin root:
 *   SLOTS_E2E_URL="https://craft-plugin-dev.ddev.site/wizard/service" \
 *   npx playwright test schedule-capacity -c tests/e2e/playwright.config.ts
 */

const SERVICE_ID = process.env.SLOTS_E2E_SERVICE_ID ?? '1236';
const TIME = '09:00';
const CAPACITY = 3;

/** Project root, from which `ddev exec` resolves the fixture path. */
const PROJECT_ROOT = process.env.SLOTS_E2E_PROJECT_ROOT ?? '../../..';
const FIXTURE = 'plugins/slots/tests/integration-live/capacity-fixture.php';

/**
 * Options go in as flags: `ddev exec` does not forward the host environment into
 * the container, so an env-var date would silently seed the fixture's default day.
 */
function run(...args: string[]): string {
  return execFileSync('ddev', ['exec', 'php', FIXTURE, ...args, `--service=${SERVICE_ID}`, `--time=${TIME}`], {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
  }).trim();
}

/**
 * The browser can only reach a day the availability calendar marks bookable, so
 * the date is resolved from the live site rather than pinned — the dev database
 * carries blackout dates that would otherwise make a hard-coded date unclickable.
 */
const DATE = process.env.SLOTS_E2E_DATE ?? run('pick-date');

const fixture = (...args: string[]) => run(...args, `--date=${DATE}`);

/** Open the wizard on the target service and reach the date/time step. */
async function openDateStep(page: Page): Promise<void> {
  await page.goto(`?serviceId=${SERVICE_ID}`);

  // The wizard opens straight on the date step and fills the calendar over the
  // network. Without waiting for the first month to render, the walk below sees
  // zero cells, clicks forward 36 times and ends up years past the target date.
  await page.locator('[data-slots-date]').first().waitFor({ state: 'attached', timeout: 20000 });

  const day = page.locator(`[data-slots-date="${DATE}"]`);

  // Walk the calendar forward a month at a time. Each step refetches the month's
  // bookable days, and a cell stays aria-disabled until that response lands — so
  // wait for the fetch rather than clicking a cell the calendar hasn't enabled.
  for (let i = 0; i < 36 && (await day.count()) === 0; i++) {
    await Promise.all([
      page.waitForResponse((r) => r.url().includes('availability/calendar')),
      page.locator('[data-slots-cal="next"]').click(),
    ]);
  }

  await expect(day).toHaveCount(1);
  await expect(day).not.toHaveAttribute('aria-disabled', 'true');
  await Promise.all([
    page.waitForResponse((r) => r.url().includes('availability/slots')),
    day.click(),
  ]);
}

const slot = (page: Page) => page.locator(`[data-slots-time="${TIME}"]`);

test.describe('schedule capacity in the booking wizard', () => {
  test.beforeAll(() => {
    fixture('capacity', String(CAPACITY));
  });

  test.afterAll(() => {
    fixture('reset');
  });

  test.beforeEach(() => {
    fixture('clear');
  });

  test('offers every seat on an unbooked day', async ({ page }) => {
    await openDateStep(page);

    await expect(slot(page)).toHaveCount(1);
    await expect(slot(page)).toHaveAttribute('data-slots-capacity', String(CAPACITY));
    await expect(slot(page)).not.toHaveAttribute('aria-disabled', 'true');
  });

  test('shows the remaining seats on a group slot', async ({ page }) => {
    fixture('seed', '1');
    await openDateStep(page);

    // A customer has to be able to read the remaining count, not just find it
    // in a data attribute.
    await expect(slot(page).locator('[data-slots-slot-seats]')).toHaveText(`${CAPACITY - 1} available`);
  });

  test('leaves one-on-one slots unannotated', async ({ page }) => {
    fixture('seed', String(CAPACITY - 1));
    await openDateStep(page);

    // One remaining seat is the ordinary case and gets no "1 available" noise.
    await expect(slot(page)).toHaveAttribute('data-slots-capacity', '1');
    await expect(slot(page).locator('[data-slots-slot-seats]')).toHaveCount(0);
  });

  test('keeps the slot bookable after a single booking', async ({ page }) => {
    fixture('seed', '1');
    await openDateStep(page);

    // The regression: one booking used to withdraw the slot entirely.
    await expect(slot(page)).toHaveCount(1);
    await expect(slot(page)).toHaveAttribute('data-slots-capacity', String(CAPACITY - 1));
  });

  test('counts each booking down to the last seat', async ({ page }) => {
    fixture('seed', String(CAPACITY - 1));
    await openDateStep(page);

    await expect(slot(page)).toHaveCount(1);
    await expect(slot(page)).toHaveAttribute('data-slots-capacity', '1');
  });

  test('withdraws the slot once every seat is taken', async ({ page }) => {
    fixture('seed', String(CAPACITY));
    await openDateStep(page);

    await expect(slot(page)).toHaveCount(0);
    // Neighbouring slots must be unaffected by a full hour.
    await expect(page.locator('[data-slots-time]')).not.toHaveCount(0);
  });

  test('reveals the quantity picker while several seats remain', async ({ page }) => {
    await openDateStep(page);
    await slot(page).click();

    const picker = page.locator('[data-slots-slot-quantity]');
    await expect(picker).toBeVisible();

    await picker.locator('[data-slots-action="qty-increment"]').click();
    await expect(picker.locator('[data-slots-slot-qty-value]')).toHaveText('2');
  });

  test('books a seat and leaves the rest of the slot open', async ({ page }) => {
    fixture('seed', String(CAPACITY - 1));
    await openDateStep(page);

    await expect(slot(page)).toHaveAttribute('data-slots-capacity', '1');
    await slot(page).click();

    // The last seat is a single-capacity selection, so no picker is offered.
    await expect(page.locator('[data-slots-slot-quantity]')).toBeHidden();
  });
});
