/**
 * Checks the customer index against what the bookings table actually holds.
 *
 * The aggregation is the whole feature here — a screen that renders but counts
 * wrong is worse than one that errors, so these assertions compare the rendered
 * numbers with figures passed in from the database.
 *
 * Usage:
 *   SLOTS_CP_USER=… SLOTS_CP_PASS=… \
 *   SLOTS_EXPECT_CUSTOMERS=20 SLOTS_EXPECT_EMAIL=someone@example.test \
 *   SLOTS_EXPECT_BOOKINGS=1 node tests/e2e/customers-smoke.mjs
 */
import { chromium } from '@playwright/test';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const USER = process.env.SLOTS_CP_USER;
const PASS = process.env.SLOTS_CP_PASS;
const EXPECT_TOTAL = Number(process.env.SLOTS_EXPECT_CUSTOMERS ?? 0);
const EXPECT_EMAIL = process.env.SLOTS_EXPECT_EMAIL;
const EXPECT_BOOKINGS = Number(process.env.SLOTS_EXPECT_BOOKINGS ?? 0);

if (!USER || !PASS || !EXPECT_TOTAL || !EXPECT_EMAIL) {
  console.error('SLOTS_CP_USER, SLOTS_CP_PASS, SLOTS_EXPECT_CUSTOMERS and SLOTS_EXPECT_EMAIL are required.');
  process.exit(2);
}

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH,
  args: ['--ignore-certificate-errors'],
});
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
await page.locator('input[name="username"]:visible').first().fill(USER);
await page.locator('input[name="password"]:visible').first().fill(PASS);
await page.locator('button[type="submit"], #submit, .submit').first().click();
await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
console.log('logged in\n');

// --- index
const res = await page.goto(`${BASE}/admin/slots/customers`, { waitUntil: 'domcontentloaded' });
check('customer index loads', res?.status() === 200, `status=${res?.status()}`);

const nav = await page.locator('nav a[href*="/admin/slots/customers"]').count();
check('appears in the plugin subnav', nav > 0);

const countText = (await page.locator('.light').last().textContent().catch(() => '')) || '';
check(
  'reports the same number of customers the database has',
  countText.includes(String(EXPECT_TOTAL)),
  `expected ${EXPECT_TOTAL}, page says "${countText.trim()}"`,
);

const rawKeys = await page.evaluate(() => {
  const shape = /^(customers|titles|nav|labels|buttons)\.[a-zA-Z][a-zA-Z0-9.]*$/;
  const found = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const t = n.textContent.trim();
    if (shape.test(t)) found.add(t);
  }
  return [...found];
});
check('no untranslated keys', rawKeys.length === 0, rawKeys.join(', '));

// --- search narrows the list
await page.locator('input[name="search"]').fill(EXPECT_EMAIL);
await page.locator('button[type="submit"]').first().click();
await page.waitForLoadState('domcontentloaded');

const searchRows = await page.locator('table.data tbody tr').count();
check('search narrows to the matching customer', searchRows === 1, `${searchRows} row(s)`);

// --- detail
const link = page.locator('table.data tbody tr a').first();
const linkedName = (await link.textContent())?.trim();
const href = await link.getAttribute('href');

// Navigate rather than click so the status code is observable — a 404 here
// still "opens a page", which is how a broken lookup passed as a success.
const detail = await page.goto(new URL(href, BASE).toString(), { waitUntil: 'domcontentloaded' });
check('detail page opens', detail?.status() === 200, `status=${detail?.status()} ${page.url()}`);
check(
  'detail shows the customer',
  (await page.content()).includes(EXPECT_EMAIL),
  `looking for ${EXPECT_EMAIL} (list showed "${linkedName}")`,
);

const historyRows = await page.locator('table.data tbody tr').count();
check(
  'booking history matches the database',
  historyRows === EXPECT_BOOKINGS,
  `expected ${EXPECT_BOOKINGS} booking(s), found ${historyRows}`,
);

const detailKeys = await page.evaluate(() => {
  const shape = /^(customers|titles|nav|labels)\.[a-zA-Z][a-zA-Z0-9.]*$/;
  const found = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const t = n.textContent.trim();
    if (shape.test(t)) found.add(t);
  }
  return [...found];
});
check('no untranslated keys on the detail page', detailKeys.length === 0, detailKeys.join(', '));

// --- an address that belongs to nobody must 404, not 500
const missing = await page.goto(`${BASE}/admin/slots/customers/nobody%40example.invalid`, {
  waitUntil: 'domcontentloaded',
});
check('unknown customer 404s', missing?.status() === 404, `status=${missing?.status()}`);

const jsErrs = consoleErrors.filter((t) => !/favicon|status of 404/i.test(t));
check('no JS errors', jsErrs.length === 0, jsErrs.slice(0, 2).join(' | '));

await browser.close();

const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length - bad.length}/${results.length} checks passed`);
process.exit(bad.length ? 1 : 0);
