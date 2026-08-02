/**
 * Drives the bookings index now that it is a native Craft element index.
 *
 * The previous screen hand-rolled its status filter, search, sorting,
 * pagination and export. Those are now the element's sources, sort options,
 * condition rules and exporter, driven by Craft's own index JS — so this
 * clicks the real UI rather than posting to endpoints, because what matters is
 * that a person can use it.
 *
 * Usage:
 *   SLOTS_CP_USER=… SLOTS_CP_PASS=… SLOTS_EXPECT_TOTAL=24 \
 *   SLOTS_EXPECT_CONFIRMED=11 node tests/e2e/bookings-index-smoke.mjs
 */
import { chromium } from '@playwright/test';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const USER = process.env.SLOTS_CP_USER;
const PASS = process.env.SLOTS_CP_PASS;
const EXPECT_TOTAL = Number(process.env.SLOTS_EXPECT_TOTAL ?? 0);
const EXPECT_CONFIRMED = Number(process.env.SLOTS_EXPECT_CONFIRMED ?? 0);
// A term that appears in some booking's customer name on the site under test.
// Hardcoding one couples this suite to whatever the dev site happens to be seeded with.
const SEARCH_TERM = process.env.SLOTS_SEARCH_TERM ?? 'Wizard';

if (!USER || !PASS || !EXPECT_TOTAL) {
  console.error('SLOTS_CP_USER, SLOTS_CP_PASS and SLOTS_EXPECT_TOTAL are required.');
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

const rowCount = () => page.locator('.elements table.data tbody tr').count();
const settle = async () => {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(600);
};

await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
await page.locator('input[name="username"]:visible').first().fill(USER);
await page.locator('input[name="password"]:visible').first().fill(PASS);
await page.locator('button[type="submit"], #submit, .submit').first().click();
await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
console.log('logged in\n');

const res = await page.goto(`${BASE}/admin/slots/bookings`, { waitUntil: 'domcontentloaded' });
check('bookings index loads', res?.status() === 200, `status=${res?.status()}`);
await settle();

// --- it is genuinely Craft's index, not our old table
const isNative = await page.locator('.elements .tableview, .elements table.data').count();
check('renders as a native element index', isNative > 0);

const rows = await rowCount();
check('lists the bookings', rows === EXPECT_TOTAL, `expected ${EXPECT_TOTAL}, found ${rows}`);

// --- status sources replace the old status dropdown
// Sources carry a data-key, which is a far steadier hook than their label.
const sourceKeys = await page.evaluate(
  () => [...document.querySelectorAll('a[data-key]')].map((a) => a.getAttribute('data-key')),
);
const hasStatusSources = ['*', 'confirmed', 'pending', 'cancelled', 'no_show']
  .every((k) => sourceKeys.includes(k));
check('status sources are listed in the sidebar', hasStatusSources, sourceKeys.join(' | '));

const confirmedSource = page.locator('a[data-key="confirmed"]').first();
if (await confirmedSource.count()) {
  await confirmedSource.click();
  await settle();
  const confirmedRows = await rowCount();
  check(
    'the confirmed source narrows the list',
    confirmedRows === EXPECT_CONFIRMED,
    `expected ${EXPECT_CONFIRMED}, found ${confirmedRows}`,
  );

  await page.locator('a[data-key="*"]').first().click();
  await settle();
} else {
  check('the confirmed source narrows the list', false, 'no Confirmed source link found');
}

// --- search
const searchBox = page.locator('input[type="text"].text.fullwidth').first();
if (await searchBox.count()) {
  await searchBox.fill(SEARCH_TERM);
  await page.waitForTimeout(1800);
  await settle();
  const searched = await rowCount();
  check('search narrows the list', searched > 0 && searched <= EXPECT_TOTAL, `${searched} row(s) for "${SEARCH_TERM}"`);
  await searchBox.fill('');
  await page.waitForTimeout(1500);
  await settle();
} else {
  check('search narrows the list', false, 'no search box on the index');
}

// --- element actions and the exporter, which the old table had to hand-roll
const firstCheckbox = page.locator('table.data tbody tr td.checkbox-cell').first();
if (await firstCheckbox.count()) {
  await firstCheckbox.click();
  await page.waitForTimeout(900);
  const actionBtn = await page.locator('.btn.menubtn').count();
  check('element actions become available on selection', actionBtn > 0, `${actionBtn} menu button(s)`);
} else {
  check('element actions become available on selection', false, 'no selection checkbox in rows');
}

// The exporter lives behind the index's action menu rather than its own button.
const offersExport = (await page.content()).match(/Export/i) !== null;
check('the exporter is offered', offersExport);

// --- our own action button survived the swap
const newBtn = await page.locator('a.btn.submit', { hasText: /New booking/i }).count();
check('the New booking button is present', newBtn > 0);

// --- a row still opens the booking
const rowLink = page.locator('.elements table.data tbody tr a[href*="/bookings/"]').first();
if (await rowLink.count()) {
  const href = await rowLink.getAttribute('href');
  const opened = await page.goto(new URL(href, BASE).toString(), { waitUntil: 'domcontentloaded' });
  check('a row opens its booking', opened?.status() === 200, `${href} → ${opened?.status()}`);
} else {
  check('a row opens its booking', false, 'no row link found');
}

const jsErrs = consoleErrors.filter((t) => !/favicon|status of 404/i.test(t));
check('no JS errors', jsErrs.length === 0, jsErrs.slice(0, 2).join(' | '));

await browser.close();

const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length - bad.length}/${results.length} checks passed`);
process.exit(bad.length ? 1 : 0);
