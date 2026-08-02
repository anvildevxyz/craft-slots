/**
 * Drives the front-end booking wizard end to end in a real browser.
 *
 * Two modes:
 *   free  — paymentMode=none, booking confirms immediately
 *   paid  — paymentMode=direct, Stripe Payment Element, webhook confirms
 *
 *   SLOTS_SMOKE_URL=… node tests/e2e/wizard-smoke.mjs [free|paid]
 */
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

const URL = process.env.SLOTS_SMOKE_URL ?? 'https://craft-plugin-dev.ddev.site/slots-smoke';
const MODE = process.argv[2] ?? 'free';
// A unique address per run: the plugin rate-limits repeat bookings per email,
// so a fixed one makes the third run of the day fail for the wrong reason.
const EMAIL = `wizsmoke+${Date.now().toString(36)}@example.test`;

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH,
  args: ['--ignore-certificate-errors'],
});
// Tall enough that the Payment Element (~650px) plus the Pay button below it
// both fit — otherwise the button sits under the fold.
const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 1600 } });
const page = await ctx.newPage();

const jsErrors = [];
const failedRequests = [];
page.on('pageerror', (e) => jsErrors.push(e.message));
page.on('console', (m) => { if (m.type() === 'error') jsErrors.push(m.text()); });
page.on('response', async (r) => {
  if (r.status() >= 400 && /\/actions\/slots\//.test(r.url())) {
    failedRequests.push(`${r.status()} ${r.url().split('/actions/')[1]} ${(await r.text().catch(() => '')).slice(0, 160)}`);
  }
});

const step = (n) => page.locator(`[data-slots-step="${n}"]`);
const visible = async (n) => (await step(n).count()) && await step(n).isVisible();

async function activeStep() {
  for (const n of ['service', 'location', 'employee', 'datetime', 'info', 'review', 'payment', 'success']) {
    if (await visible(n)) return n;
  }
  return '(none)';
}

async function clickNext() {
  const btn = page.locator('[data-slots-action="next"]:visible').first();
  if (await btn.count()) { await btn.click(); await page.waitForTimeout(400); return true; }
  return false;
}

console.log(`mode: ${MODE}\nurl:  ${URL}\n`);
await page.goto(URL, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2500); // wizard bootstraps: services + payment settings

// The mode argument only tells this script how to drive the wizard — what the
// wizard actually does is the plugin's `paymentMode` setting. Mismatched, the
// run fails for a reason that has nothing to do with the code under test.
const payEnabled = await page.evaluate(async () => {
  try {
    const r = await fetch('/slots/api/v1/payment-settings', { headers: { Accept: 'application/json' } });
    const j = await r.json();
    return typeof j?.paymentEnabled === 'boolean' ? j.paymentEnabled : null;
  } catch { return null; }
});
const wantPaid = MODE === 'paid';
if (payEnabled !== null && payEnabled !== wantPaid) {
  console.log(`\nABORT — direct payments are ${payEnabled ? 'ON' : 'OFF'}, this run needs them ${wantPaid ? 'ON' : 'OFF'}.`);
  console.log(`  UPDATE slots_settings SET paymentMode='${wantPaid ? 'direct' : 'none'}';  then clear-caches/data\n`);
  await browser.close();
  process.exit(2);
}

console.log('step after load:', await activeStep());

// --- service: click the real card so availability is fetched --------------
const card = page.locator('[data-slots-action="select-service"]:visible').first();
if (await card.count()) {
  await card.click();
  await page.waitForTimeout(1500);
} else {
  console.log('  (service step auto-skipped — single service)');
}
console.log('after service:', await activeStep());

// location / employee steps auto-skip when there is only one option
for (let i = 0; i < 3 && !(await visible('datetime')); i++) {
  const opt = page.locator('[data-slots-action="select-location"]:visible, [data-slots-action="select-employee"]:visible').first();
  if (await opt.count()) { await opt.click(); await page.waitForTimeout(1200); }
  else if (!(await clickNext())) break;
}
console.log('at datetime:', await visible('datetime'));

// --- pick a day that has slots -------------------------------------------
let picked = false;
for (let month = 0; month < 2 && !picked; month++) {
  const days = page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])');
  const n = await days.count();
  for (let i = 0; i < n && !picked; i++) {
    await days.nth(i).click();
    await page.waitForTimeout(3000); // availability/slots round trip
    const slots = page.locator('[data-slots-slots] button[data-slots-time]:visible');
    if (await slots.count()) {
      await slots.first().click();
      await page.waitForTimeout(2000); // soft lock is acquired here
      picked = true;
    }
  }
  if (!picked) {
    const next = page.locator('[data-slots-cal="next"]:visible').first();
    if (await next.count()) { await next.click(); await page.waitForTimeout(1500); }
  }
}
console.log('slot picked:', picked, '| step:', await activeStep());

// --- customer details -----------------------------------------------------
if (!(await visible('info'))) await clickNext();
for (const [field, value] of [['name', 'Wizard Smoke'], ['email', EMAIL], ['phone', '+41791234567']]) {
  const el = page.locator(`[data-slots-step="info"] [data-slots-field="${field}"]`).first();
  if (await el.count()) await el.fill(value);
}
await clickNext();
await page.waitForTimeout(600);
console.log('after info:', await activeStep());

// --- review → submit ------------------------------------------------------
if (await visible('review')) {
  const submit = page.locator('[data-slots-action="submit"]:visible').first();
  await submit.click();
  await page.waitForTimeout(MODE === 'paid' ? 4000 : 3000);
}
console.log('after submit:', await activeStep());

// --- paid: fill the Stripe Payment Element -------------------------------
if (MODE === 'paid') {
  try {
    // The Payment Element mounts into an iframe of its own and can take well
    // over 10s on a cold cache. Its field ids are locale-independent; the
    // placeholders are not (a CH account renders German, plus TWINT/Klarna).
    await page.waitForFunction(
      () => [...document.querySelectorAll('iframe')].some((f) => /elements-inner-payment/.test(f.src)),
      { timeout: 45000 },
    );
    const frame = page.frameLocator('iframe[src*="elements-inner-payment"]').first();

    const cardTab = frame.locator('#card-tab');
    if (await cardTab.count()) await cardTab.click();

    await frame.locator('#payment-numberInput').fill('4242424242424242', { timeout: 20000 });
    await frame.locator('#payment-expiryInput').fill('12/34');
    await frame.locator('#payment-cvcInput').fill('123');
    for (const id of ['#payment-billingNameInput', '#payment-postalCodeInput']) {
      const el = frame.locator(id);
      if (await el.count()) await el.fill(id.includes('Name') ? 'Wizard Smoke' : '8001');
    }
    console.log('stripe element filled');

    const pay = page.locator('[data-slots-action="pay"]:visible').first();
    await pay.scrollIntoViewIfNeeded();
    await page.waitForTimeout(500); // the element reflows as payment-method tabs settle
    await pay.click();
    // card confirm + webhook round trip
    await page.waitForFunction(
      () => { const s = document.querySelector('[data-slots-step="success"]'); return s && s.offsetParent !== null; },
      { timeout: 60000 },
    ).catch(() => {});
  } catch (e) {
    console.log('stripe element FAILED:', e.message.split('\n')[0].slice(0, 140));
  }
}

const final = await activeStep();
console.log('\nfinal step:', final);
if (failedRequests.length) {
  console.log('\nFAILED REQUESTS:');
  for (const r of failedRequests) console.log('  ' + r);
}
const realJsErrors = jsErrors.filter((t) => !/favicon|404 \(\)|Failed to load resource: the server responded with a status of 404/i.test(t));
if (realJsErrors.length) {
  console.log('\nJS ERRORS:');
  for (const e of realJsErrors.slice(0, 6)) console.log('  ' + e.slice(0, 200));
}

const ok = final === 'success' && failedRequests.length === 0 && realJsErrors.length === 0;
if (!ok) {
  // A booking POST that 400s after several runs is almost always the anti-abuse
  // limiter (rateLimitPerIp, default 10) rather than a broken wizard. Its
  // counter lives in the data cache.
  console.log(
    '\nIf the booking request returned 400, the per-IP rate limit is likely spent.\n'
    + "Clear it with: ddev exec bash -c 'cd /var/www/html && php craft clear-caches/data'",
  );
}

console.log(`\n${ok ? 'PASS' : 'FAIL'} — booking ${ok ? 'confirmed' : 'did not reach success'}`);
// Remove the booking this run made. Left behind it takes a seat on the group
// service for good, and the battery's quantity-controls scenario needs a slot
// with more than one seat free — so this suite quietly broke that one.
const CLEANUP_CMD = process.env.SLOTS_CLEANUP_CMD ?? `ddev mysql -e "`
  + `DELETE e FROM elements e JOIN slots_reservations r ON r.id = e.id WHERE r.userEmail = '${EMAIL}'; `
  + `DELETE FROM slots_reservations WHERE userEmail = '${EMAIL}';"`;

if (CLEANUP_CMD) {
  try {
    execSync(CLEANUP_CMD, { stdio: 'ignore', cwd: process.env.SLOTS_PROJECT_ROOT ?? '../..' });
  } catch {
    console.log(`could not clean up ${EMAIL} — delete it before running the battery`);
  }
}

await browser.close();
process.exit(ok ? 0 : 1);
