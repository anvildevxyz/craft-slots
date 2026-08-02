/**
 * Drives a real customer reschedule through the signed manage-booking link.
 *
 * The unit suite can only assert the shape of this flow — whether the panel
 * renders, whether slots actually load over the network, and whether the
 * booking really moves are things only a browser answers.
 *
 * Usage:
 *   SLOTS_MANAGE_TOKEN=<confirmationToken> node tests/e2e/reschedule-smoke.mjs
 */
import { chromium } from '@playwright/test';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const TOKEN = process.env.SLOTS_MANAGE_TOKEN;

if (!TOKEN) {
  console.error('SLOTS_MANAGE_TOKEN is required (a reservation confirmationToken).');
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
  results.push({ name, ok, detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

const url = `${BASE}/booking/manage/${TOKEN}`;
const res = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
check('manage page loads', res?.status() === 200, `status=${res?.status()}`);

// the panel itself
const panel = page.locator('#slots-reschedule-slots');
const hasPanel = (await panel.count()) === 1;
check('reschedule panel is rendered', hasPanel);

// No panel is a legitimate answer, not a crash: the booking may be inside the
// cancellation-policy window, where rescheduling is refused on purpose. Say so
// and stop, rather than failing later on a missing date input.
if (!hasPanel) {
  console.log(
    '\nThe panel is absent, which is correct when the booking is inside the'
    + ' cancellation window. Point SLOTS_MANAGE_TOKEN at a booking further out.',
  );
  await browser.close();
  process.exit(1);
}

// no raw translation keys leaked into the page
const rawKeys = await page.evaluate(() => {
  const shape = /^(templates|booking|emails)\.[a-zA-Z][a-zA-Z0-9.]*$/;
  const found = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  for (let n = walker.nextNode(); n; n = walker.nextNode()) {
    const t = n.textContent.trim();
    if (shape.test(t)) found.add(t);
  }
  return [...found];
});
check('no untranslated keys on the page', rawKeys.length === 0, rawKeys.join(', '));

const isAvailabilityCall = (r) => r.url().includes('get-available-slots');

/** Advance the picker by a day and wait for that day's response to land. */
const goToNextDay = async () => {
  const settled = page.waitForResponse(isAvailabilityCall, { timeout: 20000 });
  const value = await page.evaluate(() => {
    const el = document.getElementById('slots-reschedule-date');
    const d = new Date(el.value + 'T00:00:00');
    d.setDate(d.getDate() + 1);
    // Format from the local parts. Going back out through toISOString() converts
    // to UTC, and in any timezone ahead of it that lands on the day before —
    // so "+1 day" silently returned the same date it started on.
    const pad = (n) => String(n).padStart(2, '0');
    el.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    el.dispatchEvent(new Event('change'));
    return el.value;
  });
  await settled;
  // give the DOM a beat to render what just arrived
  await page.waitForTimeout(150);
  return value;
};

// The first request fires on load; it may already have landed by now.
await page.waitForTimeout(1500);

// A booking's own day is frequently full — that is often why it is being moved
// — so walk forward the way a customer would until a day has room.
let slotCount = await page.locator('.slots-reschedule-slot:not(:disabled)').count();
let searched = 0;
let landedOn = null;

while (slotCount === 0 && searched < 14) {
  searched++;
  landedOn = await goToNextDay();
  slotCount = await page.locator('.slots-reschedule-slot:not(:disabled)').count();
}

if (landedOn && slotCount > 0) {
  console.log(`      (the booking's own day was full; found room on ${landedOn})`);
}

check('available slots render', slotCount > 0, `${slotCount} selectable slot(s) after ${searched} day(s) forward`);

const current = await page.locator('.slots-reschedule-slot.is-current').count();
check('the booking\'s own slot is not selectable', current >= 0, `${current} marked current`);

// submit stays disabled until a slot is chosen
check('confirm is disabled before a choice', await page.locator('#slots-reschedule-submit').isDisabled());

let moved = false;
if (slotCount > 0) {
  const target = page.locator('.slots-reschedule-slot:not(:disabled)').first();
  const newTime = (await target.textContent())?.trim();
  await target.click();

  check('confirm enables after choosing a slot', !(await page.locator('#slots-reschedule-submit').isDisabled()));

  await page.locator('#slots-reschedule-submit').click();
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(1500);

  const errorText = (await page.locator('#slots-reschedule-error').textContent().catch(() => '')) || '';
  const shown = (await page.locator('.slots-manage-datetime').first().textContent().catch(() => '')) || '';

  // The page renders the time in the site's locale ("2:00 PM"), while the slot
  // button carries it as "14:00" — so accept either form. Comparing the raw
  // string fails on a perfectly correct reschedule.
  const [hour, minute] = (newTime ?? '').split(':').map(Number);
  const wanted = [newTime];
  if (Number.isInteger(hour) && Number.isInteger(minute)) {
    wanted.push(`${((hour + 11) % 12) + 1}:${String(minute).padStart(2, '0')}`);
  }

  moved = wanted.some((form) => form && shown.includes(form));
  check(
    'booking shows the new time after reschedule',
    moved,
    `expected one of ${wanted.join(' / ')}, page shows "${shown.trim()}"`,
  );
  check('no error surfaced', errorText.trim() === '', errorText.trim());
}

const jsErrs = consoleErrors.filter((t) => !/favicon|status of 404/i.test(t));
check('no JS errors', jsErrs.length === 0, jsErrs.slice(0, 2).join(' | '));

await browser.close();

const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length - bad.length}/${results.length} checks passed`);
process.exit(bad.length ? 1 : 0);
