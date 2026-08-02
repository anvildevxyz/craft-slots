import { test, expect, type Page } from '@playwright/test';

/**
 * Browser E2E for the vanilla wizard's review + success rendering. Guards three
 * regressions reported against 1.4.2:
 *   - #75 the review summary used step prompts ("Choose a service") as row
 *     labels instead of the singular nouns ("Service", "Employee", "Location").
 *   - #76 a free service (no price) showed a "0.00" total row.
 *   - #77 the success screen rendered an empty booking id because the reservation
 *     was put on the context *after* the confirmed-state render.
 *
 * The dev seed has no bookable availability, so the booking API is stubbed to a
 * deterministic single-choice, price-0 service and a fixed reservation id. The
 * page, the shipped bundle and the renderer are all real — we drive the wizard
 * through its public controller (the object autoinit stores on the element) to
 * reach the review/success screens without depending on seeded calendar data,
 * then assert on the actually-rendered DOM.
 *
 * Run from the plugin root against the DDEV site:
 *   SLOTS_E2E_URL=https://craft-plugin-dev.ddev.site/wizard/service \
 *     npx playwright test -c tests/e2e/playwright.config.ts wizard-review-success.spec.ts
 */

const BOOKING_URL =
  process.env.SLOTS_E2E_URL || 'https://craft-plugin-dev.ddev.site/wizard/service';

const FREE_SERVICE = {
  id: 12,
  title: 'Free Consult',
  description: '',
  duration: 30,
  price: 0,
  bufferBefore: 0,
  bufferAfter: 0,
  locationIds: [],
};

const RESERVATION_ID = 45678;

/** Stub the wizard's data API to a single-choice, price-0 service + fixed booking. */
async function stubApi(page: Page) {
  await page.route('**/slots/api/v1/**', (route) => {
    const path = new URL(route.request().url()).pathname;
    const json = (body: unknown) =>
      route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(body) });

    if (/\/services\/employees$/.test(path)) {
      return json({
        success: true,
        message: '',
        employeeRequired: false,
        hasSchedules: true,
        serviceHasSchedule: false,
        employees: [{ id: 9437, name: 'Ada Employee' }],
        locations: [{ id: 9404, name: 'Main Studio', address: '', timezone: 'America/New_York' }],
      });
    }
    if (/\/services\/extras$/.test(path)) return json({ success: true, message: '', extras: [] });
    if (/\/services$/.test(path)) return json({ success: true, message: '', services: [FREE_SERVICE] });
    if (/\/commerce-settings$/.test(path)) return json({ success: true, commerceEnabled: false });
    if (/\/locks\/slot$/.test(path)) return json({ success: true, token: 'lock-e2e', expiresIn: 300 });
    if (/\/locks\/(extend|release)$/.test(path)) return json({ success: true, expiresIn: 300 });
    if (/\/bookings$/.test(path)) {
      return json({
        success: true,
        message: '',
        reservation: { id: RESERVATION_ID, statusLabel: 'Confirmed', formattedDateTime: 'Aug 1, 2026 10:00' },
      });
    }
    return route.continue();
  });
}

test('wizard review + success render correctly (#75, #76, #77)', async ({ page }) => {
  await stubApi(page);
  await page.goto(BOOKING_URL);

  // The lone service/employee/location auto-select, so the wizard lands on datetime.
  await page.waitForFunction(() => {
    const el = document.querySelector('[data-slots-wizard]') as any;
    return el?.__slotsController?.wizard?.stepId === 'datetime';
  });

  // Drive through the real core to the review step (bypassing the seed-less calendar).
  await page.evaluate(async () => {
    const wizard = (document.querySelector('[data-slots-wizard]') as any).__slotsController.wizard;
    await wizard.selectSlot({ date: '2026-08-01', time: '10:00', quantity: 1 });
    wizard.goNext(); // → info
    wizard.setCustomer({ name: 'Ada Lovelace', email: 'ada@example.com' });
    wizard.goNext(); // → review
  });

  // #75 — singular row labels, not the "Choose a …" step prompts.
  const dtText = (key: string) =>
    page.evaluate((k) => {
      const dd = document.querySelector(`[data-slots-step="review"] [data-slots-summary="${k}"]`);
      const dt = dd?.previousElementSibling;
      return { label: dt?.textContent?.trim() ?? null, hidden: (dd as HTMLElement)?.hidden ?? null };
    }, key);

  expect(await dtText('service')).toEqual({ label: 'Service', hidden: false });
  expect(await dtText('employee')).toEqual({ label: 'Employee', hidden: false });
  expect(await dtText('location')).toEqual({ label: 'Location', hidden: false });

  // #76 — a free service must not show a "0.00" total row.
  expect((await dtText('total')).hidden).toBe(true);

  // #77 — confirm the booking and assert the success screen shows the real id.
  await page.evaluate(async () => {
    const wizard = (document.querySelector('[data-slots-wizard]') as any).__slotsController.wizard;
    await wizard.submit();
  });

  const bookingId = page.locator('[data-slots-summary="booking-id"]');
  await expect(bookingId).toHaveText(String(RESERVATION_ID));
  await expect(page.locator('[data-slots-summary="status"]')).toHaveText('Confirmed');
});
