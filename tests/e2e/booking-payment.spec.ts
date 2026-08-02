import { test, expect, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';

/**
 * End-to-end browser smoke of the direct-payment booking flow: drive the vanilla
 * wizard through to the Stripe Payment Element, pay with a test card, and assert
 * the booking confirms. This is the one layer the headless/server smoke can't
 * cover — the real Stripe Element rendering + card entry + confirmation.
 *
 * Direct payments are a site-wide setting and every other suite needs them off,
 * so this is not part of a default run. To exercise it:
 *
 *   ddev mysql -e "UPDATE slots_settings SET paymentMode='direct';"
 *   ddev exec bash -c 'cd /var/www/html && php craft clear-caches/data'
 *   …run this spec…
 *   ddev mysql -e "UPDATE slots_settings SET paymentMode='none';"
 *
 * It skips with a reason when payments are off, so leaving them off is safe.
 *
 * How far it can get locally: a booking is confirmed by a signature-verified
 * webhook, and Stripe cannot reach a machine on your desk. Without a forwarder
 * the booking correctly stops at `pending` holding a real PaymentIntent, which
 * is what this asserts. Run `stripe listen --forward-to
 * <site>/slots/api/v1/payment/webhook/stripe` and set
 * SLOTS_STRIPE_WEBHOOK_FORWARDING=1 to assert confirmation as well.
 *
 * Prerequisites (see tests/e2e/README.md):
 *   - Stripe **test** keys saved in the plugin's payment settings.
 *   - SLOTS_E2E_URL pointing at a booking page (defaults to the DDEV dev site).
 */

const BOOKING_URL =
  process.env.SLOTS_E2E_URL || 'https://craft-plugin-dev.ddev.site/wizard/service';

// Optionally target a specific service card (by data-slots-id); else the first.
const SERVICE_ID = process.env.SLOTS_E2E_SERVICE_ID || '';

/** Run `action` only if the given wizard step is currently visible (steps are conditional). */
async function ifStepVisible(page: Page, stepId: string, action: () => Promise<void>) {
  const region = page.locator(`[data-slots-step="${stepId}"]`);
  if (await region.isVisible().catch(() => false)) {
    await action();
  }
}

test('direct payment: wizard → Stripe Element → booking confirmed', async ({ page }) => {
  await page.goto(BOOKING_URL);

  // Direct payments have to be switched on and given Stripe keys for this to
  // mean anything. On a site without them the wizard has no payment step, and
  // failing at the Stripe iframe reports a configuration gap as a product bug.
  const paymentMode = await page.evaluate(() => {
    const el = document.querySelector('[data-slots-config]');
    return el ? JSON.parse(el.textContent || '{}')?.config?.paymentMode : null;
  });
  test.skip(
    !paymentMode || paymentMode === 'none',
    `payments are off on this site (paymentMode=${paymentMode}); set them up with Stripe test keys to run this`,
  );

  // 1. Service — skipped when the page preselects one. Otherwise pick the
  //    configured service (SLOTS_E2E_SERVICE_ID) or the first card. Going through
  //    the real service step is what triggers the availability fetch.
  await ifStepVisible(page, 'service', async () => {
    const serviceStep = page.locator('[data-slots-step="service"]');
    const card = SERVICE_ID
      ? serviceStep.locator(`[data-slots-action="select-service"][data-slots-id="${SERVICE_ID}"]`)
      : serviceStep.locator('[data-slots-action="select-service"]').first();
    await card.click();
    await serviceStep.locator('[data-slots-action="next"]').click();
  });

  // 2. Optional steps — pick the first option (if any) and advance.
  for (const step of ['extras', 'location', 'employee']) {
    await ifStepVisible(page, step, async () => {
      const region = page.locator(`[data-slots-step="${step}"]`);
      const pick = region.locator('[data-slots-action^="select-"]').first();
      if (await pick.isVisible().catch(() => false)) await pick.click();
      await region.locator('[data-slots-action="next"]').click();
    });
  }

  // 3. Date + time
  const datetime = page.locator('[data-slots-step="datetime"]');
  await expect(datetime).toBeVisible();
  await page.locator('[data-slots-date]:not([aria-disabled="true"])').first().click();
  await page.locator('[data-slots-slots] button:not([disabled])').first().click();
  // Let the soft-lock request settle before advancing (submit sends its token).
  await page.waitForTimeout(1500);
  await datetime.locator('[data-slots-action="next"]').click();

  // 4. Customer info
  const info = page.locator('[data-slots-step="info"]');
  await expect(info).toBeVisible();
  await info.locator('[data-slots-field="name"]').fill('E2E Tester');
  await info.locator('[data-slots-field="email"]').fill('e2e@example.com');
  await info.locator('[data-slots-action="next"]').click();

  // 5. Review → submit. Creates the *pending* booking and enters the payment step.
  const review = page.locator('[data-slots-step="review"]');
  await expect(review).toBeVisible();
  await review.locator('[data-slots-action="submit"]').click();

  // 6. Stripe Payment Element (rendered in a Stripe-hosted iframe). The Payment
  //    Element combines fields; selectors may need adjusting for your Stripe
  //    layout (see README). Test card: always-succeeds Visa.
  const payment = page.locator('[data-slots-step="payment"]');
  await expect(payment).toBeVisible();

  const stripe = page
    .frameLocator('iframe[title="Secure payment input frame"], iframe[name^="__privateStripeFrame"]')
    .first();
  await stripe.getByPlaceholder('1234 1234 1234 1234').fill('4242424242424242');
  await stripe.getByPlaceholder(/MM ?\/ ?YY/).fill('12 / 34');
  await stripe.getByPlaceholder('CVC').fill('123');
  const zip = stripe.getByPlaceholder(/ZIP|Postal/);
  if (await zip.isVisible().catch(() => false)) await zip.fill('12345');
  // Depending on country/config the Payment Element also requires a billing name
  // (and sometimes email) — fill them when present, or confirmPayment 400s.
  const fullName = stripe.getByPlaceholder(/Full name|Name on card/i);
  if (await fullName.isVisible().catch(() => false)) await fullName.fill('E2E Tester');
  const email = stripe.getByPlaceholder('you@example.com');
  if (await email.isVisible().catch(() => false)) await email.fill('e2e@example.com');

  await payment.locator('[data-slots-action="pay"]').click();

  // 7. Stripe accepted the card. What the payment step must not do is report an
  //    error — a declined card, a missing billing field or a bad key all surface
  //    here, and each would otherwise be indistinguishable from the wait below.
  await expect(page.locator('[data-slots-error]:visible')).toHaveCount(0);

  // 8. The strongest evidence available locally: Stripe issued a PaymentIntent
  //    for this booking, at the service's price. "No error on screen" alone
  //    would also pass if the request had never been made.
  const paymentRow = execFileSync(
    'ddev',
    [
      'mysql', '-N', '-e',
      "SELECT CONCAT(p.gateway,'|',LEFT(p.externalId,3),'|',p.amount,'|',p.currency,'|',r.status)"
      + " FROM slots_payments p JOIN slots_reservations r ON r.id = p.reservationId"
      + " WHERE r.userEmail = 'e2e@example.com' ORDER BY p.id DESC LIMIT 1;",
    ],
    { cwd: process.env.SLOTS_E2E_PROJECT_ROOT ?? '../../..', encoding: 'utf8' },
  ).trim();

  const [gateway, intentPrefix, amount, currency, bookingStatus] = paymentRow.split('|');
  expect(gateway).toBe('stripe');
  expect(intentPrefix).toBe('pi_');
  expect(Number(amount)).toBeGreaterThan(0);
  expect(currency).toMatch(/^[A-Z]{3}$/);
  expect(bookingStatus).toBe('pending');

  // 9. A booking is confirmed by a signature-verified webhook, not by the
  //    browser. Stripe cannot reach a local site, so on a developer machine the
  //    booking legitimately sits at `pending` with a real PaymentIntent against
  //    it — that is the flow working, not failing. Point a forwarder at the site
  //    (`stripe listen --forward-to <site>/slots/api/v1/payment/webhook/stripe`)
  //    and set SLOTS_STRIPE_WEBHOOK_FORWARDING=1 to assert the whole path.
  if (process.env.SLOTS_STRIPE_WEBHOOK_FORWARDING === '1') {
    await expect(page.locator('[data-slots-step="success"]')).toBeVisible({ timeout: 30_000 });
    await expect(page.locator('[data-slots-summary="status"]')).not.toBeEmpty();
  } else {
    // Still on the payment step, waiting for confirmation rather than erroring.
    await expect(page.locator('[data-slots-step="payment"]')).toBeVisible();
    console.log(
      'Stripe accepted the card and the booking is awaiting webhook confirmation.\n'
      + '  Set SLOTS_STRIPE_WEBHOOK_FORWARDING=1 with `stripe listen` running to assert confirmation too.',
    );
  }
});
