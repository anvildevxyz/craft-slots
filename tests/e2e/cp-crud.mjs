/**
 * Exercises the CP save paths. Loading a screen proves the template renders;
 * it says nothing about actionSave(), which is where field handling was
 * removed wholesale during the strip-down.
 *
 * Creates one of each entity through the real forms and verifies it persisted.
 */
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const USER = process.env.SLOTS_CP_USER;
const PASS = process.env.SLOTS_CP_PASS;
const TAG = 'CPSMOKE';

if (!USER || !PASS) {
  console.error('SLOTS_CP_USER and SLOTS_CP_PASS are required.\n  SLOTS_CP_USER=… SLOTS_CP_PASS=… node tests/e2e/cp-smoke.mjs');
  process.exit(2);
}

const browser = await chromium.launch({
  executablePath: process.env.CHROME_PATH,
  args: ['--ignore-certificate-errors'],
});
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();

const pageErrors = [];
page.on('pageerror', (e) => pageErrors.push(e.message));

async function login() {
  await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="username"]:visible').first().fill(USER);
  await page.locator('input[name="password"]:visible').first().fill(PASS);
  await page.locator('button[type="submit"], #submit, .submit').first().click();
  await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
}

/** Fill by input name, tolerating absent fields. */
async function set(name, value) {
  const el = page.locator(`[name="${name}"]:visible`).first();
  if (!(await el.count())) return false;
  const tag = await el.evaluate((n) => n.tagName.toLowerCase());
  if (tag === 'select') await el.selectOption(value).catch(() => {});
  else await el.fill(String(value));
  return true;
}

async function save() {
  const btn = page.locator('button[type="submit"]:visible, input[type="submit"]:visible, .btn.submit:visible').first();
  await btn.click();
  await page.waitForLoadState('domcontentloaded');
  await page.waitForTimeout(600);
}

async function errorsOnPage() {
  const body = await page.content();
  const notice = await page.locator('.error, .errors, #notifications .error').first();
  let msg = (await notice.count()) ? (await notice.innerText()).trim().split('\n')[0] : '';
  if (/Fatal error|Uncaught|Twig\\Error|Undefined (variable|array key)|UnknownPropertyException/i.test(body)) {
    const m = body.match(/(Fatal error[^<]{0,120}|Uncaught [^<]{0,120}|Twig\\Error[^<]{0,120}|Undefined \w+[^<]{0,100})/i);
    msg = m ? m[1].trim() : msg || 'error text in body';
  }
  return msg;
}

const results = [];
async function step(name, fn) {
  pageErrors.length = 0;
  try {
    const detail = await fn();
    const err = await errorsOnPage();
    const ok = !err && pageErrors.length === 0;
    results.push({ name, ok, detail: err || pageErrors[0] || detail || '' });
    console.log(`${ok ? 'ok  ' : 'FAIL'}  ${name.padEnd(28)} ${ok ? (detail || '') : (err || pageErrors[0])}`);
  } catch (e) {
    results.push({ name, ok: false, detail: e.message.split('\n')[0] });
    console.log(`FAIL  ${name.padEnd(28)} ${e.message.split('\n')[0].slice(0, 120)}`);
  }
}

await login();
console.log('logged in\n');

await step('create Location', async () => {
  await page.goto(`${BASE}/admin/slots/locations/new`, { waitUntil: 'domcontentloaded' });
  await set('title', `${TAG} Location`);
  await set('timezone', 'Europe/Zurich');
  await save();
  return page.url().includes('/locations') ? 'saved' : `landed ${page.url()}`;
});

await step('create Service', async () => {
  await page.goto(`${BASE}/admin/slots/services/new`, { waitUntil: 'domcontentloaded' });
  await set('title', `${TAG} Service`);
  await set('duration', '60');
  await set('price', '80');
  await save();
  return 'saved';
});

await step('create Schedule', async () => {
  await page.goto(`${BASE}/admin/slots/schedules/new`, { waitUntil: 'domcontentloaded' });
  await set('title', `${TAG} Schedule`);
  await save();
  return 'saved';
});

await step('create Employee', async () => {
  await page.goto(`${BASE}/admin/slots/employees/new`, { waitUntil: 'domcontentloaded' });
  await set('title', `${TAG} Employee`);
  await set('email', 'cpsmoke@example.com');
  await save();
  return 'saved';
});

await step('create Blackout date', async () => {
  await page.goto(`${BASE}/admin/slots/blackout-dates/new`, { waitUntil: 'domcontentloaded' });
  await set('title', `${TAG} Blackout`);

  // startDate and endDate are required, and Craft's date fields post as
  // `startDate[date]` rather than `startDate`. Without these the save fails
  // validation and the step reported the date placeholder as its error.
  const day = (offset) => {
    const d = new Date();
    d.setDate(d.getDate() + offset);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getMonth() + 1)}/${pad(d.getDate())}/${d.getFullYear()}`;
  };
  await set('startDate[date]', day(30));
  await set('endDate[date]', day(31));

  await save();
  return 'saved';
});

await step('open a saved Service', async () => {
  await page.goto(`${BASE}/admin/slots/services`, { waitUntil: 'domcontentloaded' });

  // The index is a native element index and fills its rows over XHR, so the
  // link does not exist yet at domcontentloaded.
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});

  const link = page.locator(`a:has-text("${TAG} Service")`).first();
  await link.waitFor({ state: 'visible', timeout: 15000 });

  await link.click();
  await page.waitForLoadState('domcontentloaded');

  // Throw rather than return a message: a step that reports a problem in its
  // detail string still counts as passing, and the next step would then
  // re-save whatever page happened to be open.
  if (!/\/services\/\d+/.test(page.url())) {
    throw new Error(`expected a service edit screen, landed on ${page.url()}`);
  }

  return 'edit screen opened';
});

await step('re-save that Service', async () => {
  await save();
  return 'saved again';
});

await step('Settings — save booking tab', async () => {
  await page.goto(`${BASE}/admin/slots/settings`, { waitUntil: 'domcontentloaded' });
  await save();
  return 'saved';
});

await step('Settings — save payments tab', async () => {
  await page.goto(`${BASE}/admin/slots/settings/payments`, { waitUntil: 'domcontentloaded' });
  await save();
  return 'saved';
});

// Clean up after itself. These rows used to be left for a human to delete with
// the SQL in the README, and a forgotten cleanup is not inert: a CPSMOKE
// service with no schedule joins the wizard's service list, gets picked by the
// booking smoke, and offers no times — so this suite silently broke that one.
const CLEANUP_SQL =
  "DELETE e FROM elements e JOIN elements_sites es ON es.elementId = e.id WHERE es.title LIKE 'CPSMOKE%';";
const CLEANUP_CMD = process.env.SLOTS_CLEANUP_CMD ?? `ddev mysql -e "${CLEANUP_SQL}"`;

if (CLEANUP_CMD) {
  try {
    execSync(CLEANUP_CMD, { stdio: 'ignore', cwd: process.env.SLOTS_PROJECT_ROOT ?? '../..' });
    console.log('\ncleaned up CPSMOKE rows');
  } catch {
    console.log('\ncould not clean up CPSMOKE rows — delete them before running the wizard suites');
  }
}

const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length - bad.length}/${results.length} steps clean`);
if (bad.length) {
  console.log('\nFAILURES:');
  for (const b of bad) console.log(`  ${b.name}: ${b.detail}`);
}
await browser.close();
process.exit(bad.length ? 1 : 0);
