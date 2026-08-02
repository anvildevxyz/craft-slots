/**
 * Follows one booking through every surface that touches a reservation.
 *
 * The other suites each cover a slice; this one covers the life of a single
 * booking end to end, and — crucially — asserts the emails actually arrive.
 * Nothing had ever checked that, and every booking email was failing in the
 * queue without a single test noticing: a queued job that throws is invisible
 * to a browser looking at a success page.
 *
 * Covered: book, confirmation email, ICS download, reschedule (+ its email),
 * cancel (+ its email, and that the slot is released), the booking in the
 * control panel and under the cancelled source, the customer index, and trash
 * → restore through Craft's soft delete.
 *
 * Reminders and Mark as No-show get their own booking, seeded straight into the
 * database: a reminder only fires inside the configured window, and its send
 * flag is claimed before the send, so neither can be re-triggered on a booking
 * that has already had one.
 *
 * Payment is the one thing left out: it needs direct payments switched on, which
 * is a site-wide setting the other suites depend on being off. booking-payment
 * .spec.ts covers it and skips when payments are not enabled.
 *
 * Usage:
 *   SLOTS_CP_USER=… SLOTS_CP_PASS=… node tests/e2e/reservation-lifecycle-smoke.mjs
 */
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const WIZARD = process.env.SLOTS_SMOKE_URL ?? `${BASE}/slots-smoke`;
const MAILPIT = process.env.SLOTS_MAILPIT_URL ?? `${BASE}:8026`;
const USER = process.env.SLOTS_CP_USER;
const PASS = process.env.SLOTS_CP_PASS;
const ROOT = process.env.SLOTS_PROJECT_ROOT ?? '../..';
const EMAIL = `lifecycle+${Date.now().toString(36)}@example.test`;
// The employee-less service whose capacity comes from the schedule — the only
// kind that can hold more than one seat in a slot.
const GROUP_SERVICE_ID = process.env.SLOTS_GROUP_SERVICE_ID ?? '17787';

if (!USER || !PASS) {
  console.error('SLOTS_CP_USER and SLOTS_CP_PASS are required.');
  process.exit(2);
}

const results = [];
const check = (name, ok, detail = '') => {
  results.push({ name, ok });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

const sh = (cmd) => {
  try {
    return execSync(cmd, { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).trim();
  } catch (e) {
    return `ERROR: ${e.message.split('\n')[0]}`;
  }
};

/** Same, but a failure is a failure rather than a string nobody reads. */
const shStrict = (cmd) => {
  const out = sh(cmd);
  if (out.startsWith('ERROR:')) throw new Error(`${out}\n  while running: ${cmd}`);
  return out;
};

const LIFECYCLE = 'plugins/slots/tests/integration-live/reservation-lifecycle.php';
const lifecycle = (action, reservationId) =>
  JSON.parse(shStrict(`ddev exec php ${LIFECYCLE} ${action} ${reservationId}`));

/** Emails only leave the queue when a worker runs. */
const drainQueue = () => sh('ddev craft queue/run');
const sql = (q) => sh(`ddev mysql -N -e "${q.replace(/"/g, '\\"')}"`);

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH,
  args: ['--ignore-certificate-errors'],
});
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

// Mailpit is served over the same self-signed certificate as the site, which
// node's fetch refuses. The browser context already accepts it.
const mail = {
  clear: () => ctx.request.delete(`${MAILPIT}/api/v1/messages`).catch(() => {}),
  async to(address) {
    const res = await ctx.request.get(`${MAILPIT}/api/v1/messages?limit=50`, { failOnStatusCode: false });
    if (!res.ok()) return [];
    const body = await res.json();
    return (body.messages ?? []).filter((m) => (m.To ?? []).some((t) => t.Address === address));
  },
};

const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

const activeStep = () => page.evaluate(() => {
  const el = [...document.querySelectorAll('[data-slots-step]')].find((s) => !s.hasAttribute('hidden'));
  return el?.getAttribute('data-slots-step') ?? null;
});
const clickNext = async () => {
  const b = page.locator('[data-slots-action="next"]:visible').first();
  if (!(await b.count())) return false;
  await b.click();
  await page.waitForTimeout(800);
  return true;
};

await mail.clear();

// The booking rate limiter counts per IP and a full suite run exhausts it, at
// which point the booking POST 400s for a reason that has nothing to do with
// what is under test. Its counter lives in the data cache; wizard-battery
// clears it the same way between scenarios.
sh('ddev exec bash -c "cd /var/www/html && php craft clear-caches/data"');

// A soft lock outlives the page that took it and counts against capacity, so a
// previous suite's abandoned selection can make a slot look unavailable here.
sh('ddev mysql -e "DELETE FROM slots_soft_locks;"');

// ---------------------------------------------------------------- 1. book
await page.goto(WIZARD, { waitUntil: 'domcontentloaded' });

// Wait for the wizard to finish bootstrapping rather than for a fixed interval.
// The services request usually lands in well under a second, but not always —
// and a wait that expires first reports an empty service list as "no bookable
// slot", which looks exactly like a product fault.
await page.locator('[data-slots-action="select-service"], [data-slots-step="datetime"]:not([hidden])')
  .first()
  .waitFor({ state: 'attached', timeout: 30000 })
  .catch(() => {});

const card = page.locator('[data-slots-action="select-service"]:visible').first();
if (await card.count()) { await card.click(); await page.waitForTimeout(1500); }

for (let i = 0; i < 3 && (await activeStep()) !== 'datetime'; i++) {
  const opt = page.locator('[data-slots-action="select-location"]:visible, [data-slots-action="select-employee"]:visible').first();
  if (await opt.count()) { await opt.click(); await page.waitForTimeout(1200); }
  else if (!(await clickNext())) break;
}

// All 31 cells exist the moment the grid renders and stay aria-disabled until
// the month's availability lands — so waiting for a cell proves nothing. Wait
// for an *enabled* one. Clearing the data cache above makes this the slowest
// that request ever is, which is what made the loop below page past the month
// and give up.

// Book at least two days out. Reschedule and cancel are both governed by the
// cancellation policy (24 hours by default), so a booking tomorrow is correctly
// refused both — and the suite would be asserting against a booking it is not
// allowed to change.
const earliest = new Date();
earliest.setDate(earliest.getDate() + 2);
const pad = (n) => String(n).padStart(2, '0');
const EARLIEST = `${earliest.getFullYear()}-${pad(earliest.getMonth() + 1)}-${pad(earliest.getDate())}`;

const enabledDays = () => page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])');

/** Enabled cells on or after EARLIEST, in date order. */
const bookableDays = async () => {
  const all = await page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])').all();
  const keep = [];
  for (const cell of all) {
    const date = await cell.getAttribute('data-slots-date');
    if (date && date >= EARLIEST) keep.push(cell);
  }
  return keep;
};
await enabledDays().first().waitFor({ state: 'attached', timeout: 30000 }).catch(() => {});

let picked = false;
for (let month = 0; month < 2 && !picked; month++) {
  for (const day of await bookableDays()) {
    await day.click();
    await page.waitForTimeout(3000);
    const slots = page.locator('[data-slots-slots] button[data-slots-time]:visible');
    if (await slots.count()) { await slots.first().click(); await page.waitForTimeout(2000); picked = true; break; }
  }
  if (!picked) {
    const next = page.locator('[data-slots-cal="next"]:visible').first();
    if (await next.count()) {
      await next.click();
      await enabledDays().first().waitFor({ state: 'attached', timeout: 20000 }).catch(() => {});
    }
  }
}
check(
  'a slot can be picked',
  picked,
  picked ? '' : `step=${await activeStep()} clickableDays=${await page.locator('[data-slots-cal="grid"] td[data-slots-date]:not([aria-disabled="true"])').count()} allDays=${await page.locator('[data-slots-cal="grid"] td[data-slots-date]').count()}`,
);

if (!picked) {
  console.log('\ncannot continue without a bookable slot');
  await browser.close();
  process.exit(1);
}

if ((await activeStep()) !== 'info') await clickNext();
for (const [field, value] of [['name', 'Lifecycle Smoke'], ['email', EMAIL], ['phone', '+41791234567']]) {
  const el = page.locator(`[data-slots-step="info"] [data-slots-field="${field}"]`).first();
  if (await el.count()) await el.fill(value);
}
await clickNext();
await page.waitForTimeout(600);

if ((await activeStep()) === 'review') {
  await page.locator('[data-slots-action="submit"]:visible').first().click();

  // Wait for the step to change rather than for a fixed interval — the booking
  // POST, its response and the re-render take as long as they take, and a
  // timeout that expires first reports a successful booking as a failure.
  await page.waitForFunction(
    () => {
      const el = [...document.querySelectorAll('[data-slots-step]')].find((x) => !x.hasAttribute('hidden'));
      return el?.getAttribute('data-slots-step') === 'success';
    },
    { timeout: 30000 },
  ).catch(() => {});
}
check('booking reaches the success step', (await activeStep()) === 'success');

const id = Number(sql(`SELECT id FROM slots_reservations WHERE userEmail='${EMAIL}' LIMIT 1;`));
check('the booking is in the database', Number.isInteger(id) && id > 0, `id=${id}`);

// ------------------------------------------------------- 2. booking emails
drainQueue();
let inbox = await mail.to(EMAIL);
check(
  'the customer gets a confirmation email',
  inbox.some((m) => /confirm/i.test(m.Subject)),
  inbox.map((m) => m.Subject).join(' | ') || 'nothing delivered',
);
const failed = sql('SELECT COUNT(*) FROM queue WHERE fail=1;');
check('no email job failed', failed === '0', `${failed} failed job(s)`);

const token = sql(`SELECT confirmationToken FROM slots_reservations WHERE id=${id};`);

// --------------------------------------------------------------- 3. ICS
const ics = await page.request.get(`${BASE}/booking/ics/${token}`, { failOnStatusCode: false });
const icsBody = ics.ok() ? await ics.text() : '';
check(
  'the calendar file downloads and is valid',
  ics.ok() && icsBody.includes('BEGIN:VCALENDAR') && icsBody.includes('END:VCALENDAR'),
  `status=${ics.status()}`,
);

// --------------------------------------------------------- 4. reschedule
await mail.clear();
await page.goto(`${BASE}/booking/manage/${token}`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);

const before = sql(`SELECT CONCAT(bookingDate,' ',startTime) FROM slots_reservations WHERE id=${id};`);
let moved = false;

if (await page.locator('#slots-reschedule-slots').count()) {
  for (let i = 0; i < 10 && !moved; i++) {
    const free = page.locator('.slots-reschedule-slot:not(:disabled)');
    if (await free.count()) {
      await free.first().click();
      await page.locator('#slots-reschedule-submit').click();
      await page.waitForTimeout(3000);
      moved = sql(`SELECT CONCAT(bookingDate,' ',startTime) FROM slots_reservations WHERE id=${id};`) !== before;
      break;
    }
    const settled = page.waitForResponse((r) => r.url().includes('get-available-slots'), { timeout: 20000 }).catch(() => {});
    await page.evaluate(() => {
      const el = document.getElementById('slots-reschedule-date');
      const d = new Date(el.value + 'T00:00:00');
      d.setDate(d.getDate() + 1);
      const pad = (n) => String(n).padStart(2, '0');
      el.value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
      el.dispatchEvent(new Event('change'));
    });
    await settled;
    await page.waitForTimeout(300);
  }
}
check('the customer can reschedule', moved, `${before} → ${sql(`SELECT CONCAT(bookingDate,' ',startTime) FROM slots_reservations WHERE id=${id};`)}`);

drainQueue();
inbox = await mail.to(EMAIL);
check(
  'rescheduling emails the customer',
  inbox.some((m) => /reschedul/i.test(m.Subject)),
  inbox.map((m) => m.Subject).join(' | ') || 'nothing delivered',
);

// ------------------------------------------------------------- 5. cancel
await mail.clear();
await page.goto(`${BASE}/booking/manage/${token}`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);

const cancelLink = page.locator('a[href*="/booking/cancel/"], .slots-btn-danger-outline').first();
if (await cancelLink.count()) {
  await cancelLink.click();
  await page.waitForTimeout(1500);
  const confirm = page.locator('button[type="submit"]:visible, [data-slots-action="confirm-cancel"]').first();
  if (await confirm.count()) { await confirm.click(); await page.waitForTimeout(2500); }
}
const status = sql(`SELECT status FROM slots_reservations WHERE id=${id};`);
check('the customer can cancel', status === 'cancelled', `status=${status}`);

check(
  'a cancelled booking releases its slot',
  sql(`SELECT IFNULL(activeSlotKey,'NULL') FROM slots_reservations WHERE id=${id};`) === 'NULL',
);

drainQueue();
inbox = await mail.to(EMAIL);
check(
  'cancelling emails the customer',
  inbox.some((m) => /cancel/i.test(m.Subject)),
  inbox.map((m) => m.Subject).join(' | ') || 'nothing delivered',
);

// ------------------------------------------------- 6. control panel views
await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
await page.locator('input[name="username"]:visible').first().fill(USER);
await page.locator('input[name="password"]:visible').first().fill(PASS);
await page.locator('button[type="submit"], #submit, .submit').first().click();
await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });

const view = await page.goto(`${BASE}/admin/slots/bookings/${id}/view`, { waitUntil: 'domcontentloaded' });
check('the booking opens in the control panel', view?.status() === 200, `status=${view?.status()}`);

await page.goto(`${BASE}/admin/slots/bookings`, { waitUntil: 'domcontentloaded' });
await page.waitForLoadState('networkidle').catch(() => {});
await page.locator('a[data-key="cancelled"]').first().click();
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(800);
check(
  'it appears under the cancelled source',
  await page.locator(`.elements table.data tbody tr a[href*="/bookings/${id}"]`).count() > 0,
);

// the customer index counts it too
await page.goto(`${BASE}/admin/slots/customers?search=${encodeURIComponent(EMAIL)}`, { waitUntil: 'domcontentloaded' });
check(
  'the customer index lists the booking\'s customer',
  (await page.content()).includes(EMAIL),
);

// ------------------------------------------- 7. delete, trash and restore
const trashed = lifecycle('trash', id);
check(
  'trashing a booking keeps its data and frees the slot',
  trashed.row === 1 && trashed.trashed === 1 && trashed.slotKey === null,
  JSON.stringify(trashed),
);

const restored = lifecycle('restore', id);
check(
  'a trashed booking can be restored intact',
  restored.row === 1 && restored.trashed === 0,
  JSON.stringify(restored),
);

// ------------------------------------------------------- 8. reminder email
// A reminder only fires for a booking inside the reminder window, so this one is
// placed a couple of hours out rather than booked through the wizard.
const probeEmail = `reminder+${Date.now().toString(36)}@example.test`;
const probe = JSON.parse(shStrict(`ddev exec php ${LIFECYCLE} seed 2 ${probeEmail}`));
check('a booking can be placed inside the reminder window', probe.id > 0, `id=${probe.id} at ${probe.startTime}`);

await mail.clear();
const reminderRun = shStrict('ddev craft slots/reminders/send');
drainQueue();

inbox = await mail.to(probeEmail);
check(
  'a booking due soon gets a reminder email',
  inbox.some((m) => /remind/i.test(m.Subject)),
  inbox.map((m) => m.Subject).join(' | ') || reminderRun.split('\n').pop(),
);

check(
  'the reminder is recorded so it cannot be sent twice',
  lifecycle('state', probe.id).reminderSent === 1,
);

// --------------------------------------------- 9. Mark as No-show action
// The element action is registered on the element, but only the control panel
// actually invokes it — this drives the real menu.
await page.goto(`${BASE}/admin/slots/bookings`, { waitUntil: 'domcontentloaded' });
await page.waitForLoadState('networkidle').catch(() => {});

// The index remembers the source it was last left on, and an earlier phase left
// it on Cancelled — where a confirmed booking will never appear.
await page.locator('a[data-key="*"]').first().click();
await page.waitForLoadState('networkidle').catch(() => {});
await page.waitForTimeout(1200);

const probeRow = page.locator(`table.data tbody tr:has(a[href*="/bookings/${probe.id}"])`);
const foundRow = (await probeRow.count()) > 0;
check('the booking is listed in the control panel', foundRow);

if (foundRow) {
  await probeRow.locator('td.checkbox-cell').first().click();
  await page.waitForTimeout(1000);

  page.once('dialog', (d) => d.accept().catch(() => {}));
  await page.locator('button.btn.secondary.menubtn').first().click();
  await page.waitForTimeout(800);

  const item = page.locator('.menu li a, .menu li button').filter({ hasText: /no.?show/i }).first();
  check('the Mark as No-show action is offered', (await item.count()) > 0);

  if (await item.count()) {
    page.once('dialog', (d) => d.accept().catch(() => {}));
    await item.click();
    await page.waitForTimeout(2500);

    // Craft may confirm in a modal rather than a native dialog.
    const modalOk = page.locator('.modal .btn.submit, .modal button.submit').first();
    if (await modalOk.count()) {
      await modalOk.click();
      await page.waitForTimeout(2500);
    }
  }
}

check(
  'the action marks the booking as a no-show',
  lifecycle('state', probe.id).status === 'no_show',
  JSON.stringify(lifecycle('state', probe.id)),
);

sh(`ddev mysql -e "DELETE e FROM elements e JOIN slots_reservations r ON r.id = e.id WHERE r.userEmail = '${probeEmail}'; DELETE FROM slots_reservations WHERE userEmail = '${probeEmail}';"`);

// -------------------------------------------- 10. group booking quantity
// The quantity panel only appears on a booking that holds more than one seat,
// which the wizard will only produce for a group service with room — so this one
// is placed directly, on the employee-less service whose capacity comes from the
// schedule.
const groupEmail = `group+${Date.now().toString(36)}@example.test`;
const group = JSON.parse(shStrict(`ddev exec php ${LIFECYCLE} seed 72 ${groupEmail} 2 ${GROUP_SERVICE_ID}`));
check('a group booking of two seats can be placed', group.quantity === 2, JSON.stringify(group));

await mail.clear();
await page.goto(`${BASE}/booking/manage/${group.token}`, { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(1500);

const qtyInput = page.locator('#slots-new-quantity');
const qtyPanel = (await qtyInput.count()) > 0;
check('the quantity panel is offered on a multi-seat booking', qtyPanel);

if (qtyPanel) {
  await qtyInput.fill('1');
  await page.waitForTimeout(400);
  await page.locator('#slots-quantity-submit').click();
  await page.waitForTimeout(3000);
}

check(
  'reducing the quantity is saved',
  lifecycle('state', group.id).quantity === 1,
  JSON.stringify(lifecycle('state', group.id)),
);

drainQueue();
inbox = await mail.to(groupEmail);
check(
  'a quantity change emails the customer',
  inbox.some((m) => /quantit|updated|chang/i.test(m.Subject)),
  inbox.map((m) => m.Subject).join(' | ') || 'nothing delivered',
);

sh(`ddev mysql -e "DELETE e FROM elements e JOIN slots_reservations r ON r.id = e.id WHERE r.userEmail = '${groupEmail}'; DELETE FROM slots_reservations WHERE userEmail = '${groupEmail}';"`);

// --------------------------------------------------------------- cleanup
sh(`ddev mysql -e "DELETE e FROM elements e JOIN slots_reservations r ON r.id = e.id WHERE r.userEmail = '${EMAIL}'; DELETE FROM slots_reservations WHERE userEmail = '${EMAIL}';"`);
check('the booking is cleaned up', sql(`SELECT COUNT(*) FROM slots_reservations WHERE userEmail='${EMAIL}';`) === '0');

const jsErrs = consoleErrors.filter((t) => !/favicon|status of 404/i.test(t));
check('no JS errors', jsErrs.length === 0, jsErrs.slice(0, 2).join(' | '));

await browser.close();

const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length - bad.length}/${results.length} checks passed`);
process.exit(bad.length ? 1 : 0);
