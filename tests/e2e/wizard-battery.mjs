/**
 * Exercises the front-end booking wizard beyond the happy path.
 *
 * wizard-smoke.mjs proves a booking can be made. This proves the wizard behaves
 * when the user does something other than walk straight through: leaves fields
 * blank, goes back, hammers submit, loses the network, or arrives with a
 * keyboard instead of a mouse.
 *
 *   SLOTS_SMOKE_URL=… node tests/e2e/wizard-battery.mjs [scenario-name]
 *
 * Needs at least two bookable services so the service step is reachable, and
 * `paymentMode='none'` so the flow ends at the success step.
 *
 * It books real reservations (double-submit needs a real submit) and every slot
 * click takes a soft lock that outlives the page, so without a reset the
 * scenarios contend with each other and fail for reasons that have nothing to
 * do with the wizard. Between scenarios it clears its own rows via:
 *
 *   SLOTS_RESET_CMD='ddev mysql -e "…"'   (default; set to '' to disable)
 */
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

const URL = process.env.SLOTS_SMOKE_URL ?? 'https://craft-plugin-dev.ddev.site/slots-smoke';
const ONLY = process.argv[2] ?? null;

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH,
  args: ['--ignore-certificate-errors'],
});

const results = [];
const IGNORABLE = /favicon|status of 404|ERR_ABORTED/i;

// Reservations are Craft elements, so deleting only the slots_reservations row
// leaves its elements row orphaned — those accumulated silently across runs
// until an orphan count turned them up. Delete both halves.
const RESET_SQL =
  "DELETE e FROM elements e JOIN slots_reservations r ON r.id = e.id "
  + "WHERE r.userEmail LIKE 'battery+%'; "
  + "DELETE FROM slots_reservations WHERE userEmail LIKE 'battery+%'; "
  + "DELETE FROM slots_soft_locks;";
// The month calendar (which days have any availability) is cached for 300s and
// is not invalidated when reservations change, so clearing rows alone leaves
// the wizard looking at a calendar that still thinks those days are full.
const RESET_CMD = process.env.SLOTS_RESET_CMD
  ?? `ddev mysql -e "${RESET_SQL}" && ddev exec bash -c 'cd /var/www/html && php craft clear-caches/data'`;
let resetWorks = true;

/** Drop this run's bookings and every soft lock so scenarios start even. */
function resetState() {
  if (!RESET_CMD || !resetWorks) return;
  try {
    execSync(RESET_CMD, { stdio: 'ignore', cwd: process.env.SLOTS_PROJECT_ROOT ?? '../..' });
  } catch {
    resetWorks = false;
    console.log('  (state reset unavailable — scenarios may contend for slots)');
  }
}

/** Fresh page per scenario — wizard state is per-page and must not leak. */
async function withPage(fn) {
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1280, height: 1600 } });
  const page = await ctx.newPage();
  const jsErrors = [];
  page.on('pageerror', (e) => jsErrors.push(e.message));
  page.on('console', (m) => { if (m.type() === 'error' && !IGNORABLE.test(m.text())) jsErrors.push(m.text()); });
  try {
    return await fn(page, jsErrors);
  } finally {
    await ctx.close();
  }
}

function check(name, fn) {
  if (ONLY && ONLY !== name) return;
  results.push({ name, fn });
}

// --- shared drivers -------------------------------------------------------

const step = (page, n) => page.locator(`[data-slots-step="${n}"]`);
const visible = async (page, n) => (await step(page, n).count()) > 0 && await step(page, n).isVisible();

async function activeStep(page) {
  for (const n of ['service', 'location', 'employee', 'datetime', 'info', 'review', 'payment', 'success']) {
    if (await visible(page, n)) return n;
  }
  return '(none)';
}

async function open(page) {
  await page.goto(URL, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(2500);
}

/** Select the first service card, if the service step is showing. */
async function pickService(page, index = 0) {
  const cards = page.locator('[data-slots-action="select-service"]:visible');
  if (await cards.count()) {
    await cards.nth(index).click();
    await page.waitForTimeout(1500);
  }
}

/** Walk past whichever of location/employee are showing. */
async function pickScoping(page) {
  for (let i = 0; i < 3 && !(await visible(page, 'datetime')); i++) {
    const opt = page.locator('[data-slots-action="select-location"]:visible, [data-slots-action="select-employee"]:visible').first();
    if (await opt.count()) { await opt.click(); await page.waitForTimeout(1200); continue; }
    const next = page.locator('[data-slots-action="next"]:visible').first();
    if (await next.count()) { await next.click(); await page.waitForTimeout(600); continue; }
    break;
  }
}

/**
 * The calendar paints day cells first and only marks which are bookable once
 * the availability round trip lands. Counting before that races the network
 * and reports an empty month that is about to have 21 open days in it.
 */
async function waitForCalendar(page, timeout = 15000) {
  try {
    await page.waitForFunction(
      () => document.querySelectorAll('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])').length > 0,
      { timeout },
    );
    return true;
  } catch {
    return false;
  }
}

async function pickSlot(page) {
  await waitForCalendar(page);
  for (let month = 0; month < 3; month++) {
    const days = page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])');
    const n = await days.count();
    for (let i = 0; i < n; i++) {
      await days.nth(i).click();
      await page.waitForTimeout(2500);
      const slots = page.locator('[data-slots-slots] button[data-slots-time]:visible');
      if (await slots.count()) {
        await slots.first().click();
        await page.waitForTimeout(1500);
        return true;
      }
    }
    const next = page.locator('[data-slots-cal="next"]:visible').first();
    if (await next.count()) { await next.click(); await page.waitForTimeout(1500); }
  }
  return false;
}

async function toInfo(page) {
  await open(page);
  await pickService(page);
  await pickScoping(page);
  await pickSlot(page);
  if (!(await visible(page, 'info'))) {
    const next = page.locator('[data-slots-action="next"]:visible').first();
    if (await next.count()) { await next.click(); await page.waitForTimeout(800); }
  }
  return visible(page, 'info');
}

async function fillInfo(page, over = {}) {
  const vals = {
    name: 'Battery Test',
    email: `battery+${Date.now().toString(36)}@example.test`,
    phone: '+41791234567',
    ...over,
  };
  for (const [f, v] of Object.entries(vals)) {
    const el = page.locator(`[data-slots-step="info"] [data-slots-field="${f}"]`).first();
    if (await el.count()) await el.fill(v);
  }
  return vals;
}

// --- scenarios ------------------------------------------------------------

check('service-step-renders', async (page) => {
  await open(page);
  const cards = page.locator('[data-slots-action="select-service"]:visible');
  const n = await cards.count();
  if (n < 2) return `skip — only ${n} service(s) bookable; seed a second to cover this step`;

  const names = await page.locator('[data-slots-action="select-service"]:visible [data-slots-field="name"]').allTextContents();
  const prices = await page.locator('[data-slots-action="select-service"]:visible [data-slots-field="price"]').allTextContents();
  if (names.some((t) => !t.trim())) return `FAIL — a service card rendered no name: ${JSON.stringify(names)}`;
  if (prices.every((t) => !t.trim())) return 'FAIL — no service card rendered a price';

  // Cards mark the choice; the Next button is what advances. Selection has to
  // be visible in the accessibility tree, not just in wizard state, or a
  // keyboard/screen-reader user gets no confirmation their pick registered.
  await cards.first().click();
  await page.waitForTimeout(1200);
  const pressed = await page.locator('[data-slots-action="select-service"][aria-pressed="true"]').count();
  if (pressed !== 1) return `FAIL — ${pressed} card(s) marked aria-pressed after selecting one`;

  await page.locator('[data-slots-step="service"] [data-slots-action="next"]').first().click();
  await page.waitForTimeout(2000);
  const now = await activeStep(page);
  if (now === 'service') return 'FAIL — Next did not advance after a service was selected';
  return `ok — ${n} services, selection marked, advanced to ${now}`;
});

check('service-step-requires-selection', async (page) => {
  await open(page);
  if (!(await visible(page, 'service'))) return 'skip — service step auto-skipped (fewer than two services)';

  await page.locator('[data-slots-step="service"] [data-slots-action="next"]').first().click();
  await page.waitForTimeout(1500);

  if (!(await visible(page, 'service'))) return 'FAIL — advanced with no service chosen';
  const err = await page.evaluate(() => {
    const e = document.querySelector('[data-slots-error]:not([hidden])');
    return e ? e.textContent.trim() : null;
  });
  if (!err) return 'FAIL — blocked but told the user nothing';
  if (/^[a-z]+\.[a-zA-Z]+$/.test(err)) return `FAIL — untranslated key shown: ${err}`;
  return `ok — blocked with "${err}"`;
});

check('quantity-controls', async (page) => {
  // Per-slot capacity only exceeds 1 for employee-less services — with a
  // practitioner attached, capacity is that one person. Pick a group service.
  await open(page);
  const cards = page.locator('[data-slots-action="select-service"]:visible');
  let idx = 0;
  const names = await cards.allTextContents();
  const group = names.findIndex((t) => /group|class/i.test(t));
  if (group >= 0) idx = group;
  await pickService(page, idx);
  const nx = page.locator('[data-slots-step="service"] [data-slots-action="next"]:visible').first();
  if (await nx.count()) { await nx.click(); await page.waitForTimeout(1800); }
  await pickScoping(page);
  if (!(await visible(page, 'datetime'))) return 'FAIL — never reached the datetime step';

  if (!(await pickSlot(page))) return 'skip — no bookable slot on the group service';
  const inc = page.locator('[data-slots-action="qty-increment"]:visible').first();
  const dec = page.locator('[data-slots-action="qty-decrement"]:visible').first();
  if (!(await inc.count())) return 'FAIL — group service slot offered no quantity control';

  const read = () => page.evaluate(() => {
    const el = document.querySelector('[data-slots-slot-qty-value], [data-slots-qty-value], [data-slots-qty]');
    return el ? Number(String(el.value ?? el.textContent).trim()) : null;
  });

  // The bounds are enforced by disabling the buttons, so clicking a disabled
  // one would just hang. Assert the disabled state instead of the click.
  const start = await read();
  if (start !== 1) return `FAIL — quantity starts at ${start}, expected 1`;
  if (!(await dec.isDisabled())) return 'FAIL — decrement is live at the minimum of 1';

  await inc.click();
  await page.waitForTimeout(600);
  const up = await read();
  if (up !== 2) return `FAIL — increment produced ${up}, expected 2`;
  if (await dec.isDisabled()) return 'FAIL — decrement still disabled above the minimum';

  // Walk to the ceiling; increment must switch off rather than overshoot capacity.
  let guard = 0;
  while (!(await inc.isDisabled()) && guard++ < 10) {
    await inc.click();
    await page.waitForTimeout(400);
  }
  const max = await read();
  if (!(await inc.isDisabled())) return `FAIL — increment never stopped (reached ${max})`;

  await dec.click();
  await page.waitForTimeout(600);
  const down = await read();
  if (down !== max - 1) return `FAIL — decrement gave ${down}, expected ${max - 1}`;

  return `ok — 1 → ${max} (capped), both bounds disable correctly`;
});

check('validation-empty-info', async (page) => {
  if (!(await toInfo(page))) return 'skip — no bookable slot left; free capacity and re-run';

  await page.locator('[data-slots-action="next"]:visible').first().click();
  await page.waitForTimeout(800);

  if (await visible(page, 'review')) return 'FAIL — advanced past an empty required form';

  const errors = page.locator('[data-slots-step="info"] [data-slots-field-error]:visible');
  const count = await errors.count();
  if (!count) return 'FAIL — no field error shown for an empty form';

  const texts = (await errors.allTextContents()).map((t) => t.trim()).filter(Boolean);
  // A raw i18n key here means the message was never resolved.
  const raw = texts.filter((t) => /^[a-z]+\.[a-zA-Z]+$/.test(t));
  if (raw.length) return `FAIL — untranslated key shown to the user: ${raw.join(', ')}`;

  const invalid = await page.locator('[data-slots-step="info"] [aria-invalid="true"]').count();
  if (!invalid) return 'FAIL — no field marked aria-invalid';

  return `ok — ${count} field error(s), ${invalid} aria-invalid, messages resolved`;
});

check('validation-bad-email', async (page) => {
  if (!(await toInfo(page))) return 'skip — no bookable slot left; free capacity and re-run';

  await fillInfo(page, { email: 'not-an-email' });
  await page.locator('[data-slots-action="next"]:visible').first().click();
  await page.waitForTimeout(800);

  if (await visible(page, 'review')) return 'FAIL — accepted a malformed email address';
  const err = page.locator('[data-slots-step="info"] [data-slots-field-error="email"]:visible');
  if (!(await err.count())) return 'FAIL — no error on the email field';
  return `ok — rejected, message: ${(await err.first().innerText()).trim().slice(0, 60)}`;
});

check('back-preserves-input', async (page) => {
  if (!(await toInfo(page))) return 'skip — no bookable slot left; free capacity and re-run';

  const vals = await fillInfo(page);
  await page.locator('[data-slots-action="next"]:visible').first().click();
  await page.waitForTimeout(800);
  if (!(await visible(page, 'review'))) return 'FAIL — valid form did not advance to review';

  await page.locator('[data-slots-action="back"]:visible').first().click();
  await page.waitForTimeout(800);
  if (!(await visible(page, 'info'))) return 'FAIL — back did not return to the info step';

  const got = await page.locator('[data-slots-step="info"] [data-slots-field="name"]').first().inputValue();
  if (got !== vals.name) return `FAIL — name lost on back: expected "${vals.name}", got "${got}"`;
  return 'ok — values survive back navigation';
});

check('calendar-past-disabled', async (page) => {
  await open(page);
  await pickService(page);
  await pickScoping(page);
  if (!(await visible(page, 'datetime'))) return 'FAIL — never reached the datetime step';
  await waitForCalendar(page);

  const info = await page.evaluate(() => {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    let past = 0, pastEnabled = 0;
    for (const td of document.querySelectorAll('[data-slots-cal="grid"] td[data-slots-date]')) {
      const d = new Date(td.getAttribute('data-slots-date') + 'T00:00:00');
      if (d < today) { past++; if (td.getAttribute('aria-disabled') !== 'true') pastEnabled++; }
    }
    return { past, pastEnabled };
  });
  if (info.past === 0) return 'ok — calendar starts at the current month, no past days rendered';
  if (info.pastEnabled) return `FAIL — ${info.pastEnabled} past day(s) selectable`;
  return `ok — ${info.past} past day(s), all disabled`;
});

check('calendar-navigation', async (page) => {
  await open(page);
  await pickService(page);
  await pickScoping(page);
  if (!(await visible(page, 'datetime'))) return 'FAIL — never reached the datetime step';

  const label = () => page.locator('[data-slots-cal="label"], [data-slots-cal-label]').first().innerText().catch(() => '');
  const before = await label();
  const next = page.locator('[data-slots-cal="next"]:visible').first();
  if (!(await next.count())) return 'FAIL — no next-month control';
  await next.click();
  await page.waitForTimeout(1500);
  const after = await label();
  if (before && after && before === after) return `FAIL — next month did not change the calendar (${before})`;

  const prev = page.locator('[data-slots-cal="prev"]:visible').first();
  if (await prev.count()) {
    await prev.click();
    await page.waitForTimeout(1500);
    const back = await label();
    if (before && back !== before) return `FAIL — prev did not return to ${before} (got ${back})`;
  }
  return `ok — ${before || '(unlabelled)'} → ${after || '(unlabelled)'} → back`;
});

check('accessibility-contract', async (page) => {
  if (!(await toInfo(page))) return 'skip — no bookable slot left; free capacity and re-run';
  const a11y = await page.evaluate(() => {
    const root = document.querySelector('[data-slots-wizard]');
    const live = root.querySelector('[aria-live]');
    const heading = root.querySelector('[data-slots-step]:not([hidden]) [data-slots-step-heading]');
    return {
      rootRole: root.getAttribute('role'),
      hasLabel: !!(root.getAttribute('aria-label') || root.getAttribute('aria-labelledby')),
      livePoliteness: live ? live.getAttribute('aria-live') : null,
      headingFocusable: heading ? heading.getAttribute('tabindex') : null,
      headingIsFocused: heading ? document.activeElement === heading : false,
      requiredMarked: root.querySelectorAll('[data-slots-field][aria-required="true"]').length,
    };
  });
  const bad = [];
  if (!a11y.rootRole) bad.push('wizard root has no role');
  if (!a11y.hasLabel) bad.push('wizard root has no accessible name');
  if (!a11y.livePoliteness) bad.push('no aria-live region for step announcements');
  if (a11y.headingFocusable === null) bad.push('active step heading is not focusable');
  if (!a11y.requiredMarked) bad.push('no field marked aria-required');
  return bad.length ? `FAIL — ${bad.join('; ')}` : `ok — role=${a11y.rootRole}, aria-live=${a11y.livePoliteness}, ${a11y.requiredMarked} required fields marked`;
});

check('double-submit-guarded', async (page) => {
  if (!(await toInfo(page))) return 'skip — no bookable slot left; free capacity and re-run';
  const vals = await fillInfo(page);
  await page.locator('[data-slots-action="next"]:visible').first().click();
  await page.waitForTimeout(800);
  if (!(await visible(page, 'review'))) return 'FAIL — did not reach review';

  let creates = 0;
  page.on('request', (r) => { if (/\/api\/v1\/bookings?\b|reservations?\b/.test(r.url()) && r.method() === 'POST') creates++; });

  const submit = page.locator('[data-slots-action="submit"]:visible').first();
  await Promise.all([submit.click(), submit.click({ force: true }).catch(() => {})]);
  await page.waitForTimeout(5000);

  if (creates > 1) return `FAIL — ${creates} booking POSTs fired from a double click (${vals.email})`;
  return `ok — ${creates} booking POST for a double click, final step ${await activeStep(page)}`;
});

check('availability-failure-surfaces', async (page) => {
  await open(page);
  await pickService(page);
  await pickScoping(page);
  if (!(await visible(page, 'datetime'))) return 'FAIL — never reached the datetime step';

  // Kill the slots endpoint and make sure the wizard says so rather than hanging.
  await page.route('**/api/v1/availability/slots*', (route) => route.fulfill({ status: 500, body: '{"error":"boom"}' }));

  await waitForCalendar(page);
  const days = page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])');
  if (!(await days.count())) return 'skip — no selectable day to click';
  await days.first().click();
  await page.waitForTimeout(4000);

  const shown = await page.evaluate(() => {
    const el = document.querySelector('[data-slots-error]:not([hidden]), [role="alert"]:not([hidden])');
    return el ? el.textContent.trim() : null;
  });
  const spinning = await page.locator('[data-slots-loading]:visible').count();
  if (!shown) return `FAIL — a 500 from the slots endpoint surfaced nothing to the user (loading visible: ${spinning})`;
  if (/^[a-z]+\.[a-zA-Z]+$/.test(shown)) return `FAIL — untranslated key shown: ${shown}`;
  return `ok — surfaced "${shown.slice(0, 60)}"`;
});

check('soft-lock-acquired', async (page) => {
  await open(page);
  await pickService(page);
  await pickScoping(page);

  let lockCalls = 0;
  page.on('response', (r) => { if (/\/api\/v1\/locks?\b/.test(r.url())) lockCalls++; });

  const got = await pickSlot(page);
  if (!got) return 'FAIL — no slot could be picked';
  await page.waitForTimeout(1500);
  if (!lockCalls) return 'FAIL — picking a slot acquired no soft lock (double-booking window is open)';
  return `ok — ${lockCalls} lock call(s) on slot selection`;
});

check('lock-conflict-surfaces', async (page) => {
  await open(page);
  await pickService(page);
  const nx = page.locator('[data-slots-step="service"] [data-slots-action="next"]:visible').first();
  if (await nx.count()) { await nx.click(); await page.waitForTimeout(1800); }
  await pickScoping(page);
  if (!(await visible(page, 'datetime'))) return 'FAIL — never reached the datetime step';

  // Someone else grabbed the slot between render and click. The user must be
  // told; silently refusing to select looks like a broken button.
  await page.route('**/api/v1/locks/slot*', (route) => route.fulfill({
    status: 400,
    contentType: 'application/json',
    body: JSON.stringify({ success: false, message: 'This time slot is temporarily reserved. Please try again in 15 minutes.' }),
  }));

  await waitForCalendar(page);
  const days = page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])');
  let clicked = false;
  for (let i = 0; i < await days.count() && !clicked; i++) {
    await days.nth(i).click();
    await page.waitForTimeout(2500);
    const slots = page.locator('[data-slots-slots] button[data-slots-time]:visible');
    if (await slots.count()) { await slots.first().click(); await page.waitForTimeout(2500); clicked = true; }
  }
  if (!clicked) return 'skip — no slot to click';

  const selected = await page.locator('[data-slots-slots] [role="option"][aria-selected="true"]').count();
  const shown = await page.evaluate(() => {
    const e = document.querySelector('[data-slots-error]:not([hidden]), [role="alert"]:not([hidden])');
    return e && e.textContent.trim() ? e.textContent.trim() : null;
  });

  if (selected) return 'FAIL — slot marked selected even though the lock was refused';
  if (!shown) return 'FAIL — lock refused with no message; the slot button just does nothing';
  return `ok — refused, told the user "${shown.slice(0, 60)}"`;
});

check('no-console-errors-across-steps', async (page, jsErrors) => {
  if (!(await toInfo(page))) return 'skip — no bookable slot left; free capacity and re-run';
  await fillInfo(page);
  await page.locator('[data-slots-action="next"]:visible').first().click();
  await page.waitForTimeout(1000);
  await page.locator('[data-slots-action="back"]:visible').first().click();
  await page.waitForTimeout(800);
  if (jsErrors.length) return `FAIL — ${jsErrors.length} console error(s): ${jsErrors[0].slice(0, 120)}`;
  return 'ok — clean console walking forward and back';
});

// --- run ------------------------------------------------------------------

console.log(`wizard battery — ${URL}\n`);
let failed = 0;
for (const { name, fn } of results) {
  resetState();
  let out;
  try {
    out = await withPage((page, errs) => fn(page, errs));
  } catch (e) {
    out = `FAIL — threw: ${e.message.split('\n')[0].slice(0, 120)}`;
  }
  const bad = out.startsWith('FAIL');
  if (bad) failed++;
  console.log(`${bad ? 'FAIL' : out.startsWith('skip') ? 'skip' : 'ok  '}  ${name.padEnd(32)} ${out.replace(/^(FAIL|ok|skip)\s*—?\s*/, '')}`);
}
console.log(`\n${results.length - failed}/${results.length} scenarios passed`);
await browser.close();
process.exit(failed ? 1 : 0);
