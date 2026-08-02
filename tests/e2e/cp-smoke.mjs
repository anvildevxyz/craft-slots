/**
 * Drives every Slots control-panel screen in a real browser and reports any
 * that error. Static analysis cannot see runtime failures — an undefined Twig
 * variable, a bad query, a PHP error inside a controller — so this loads each
 * screen and inspects what actually came back.
 */
import { chromium } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const USER = process.env.SLOTS_CP_USER;
const PASS = process.env.SLOTS_CP_PASS;

const SCREENS = [
  ['Dashboard',            '/admin/slots'],
  ['Dashboard (explicit)', '/admin/slots/dashboard'],
  ['Bookings index',       '/admin/slots/bookings'],
  ['Customers index',      '/admin/slots/customers'],
  ['Bookings — new',       '/admin/slots/bookings/new'],
  ['Calendar — month',     '/admin/slots/calendar-view/month'],
  ['Calendar — week',      '/admin/slots/calendar-view/week'],
  ['Calendar — day',       '/admin/slots/calendar-view/day'],
  ['Services index',       '/admin/slots/services'],
  ['Services — new',       '/admin/slots/services/new'],
  ['Employees index',      '/admin/slots/employees'],
  ['Employees — new',      '/admin/slots/employees/new'],
  ['Locations index',      '/admin/slots/locations'],
  ['Locations — new',      '/admin/slots/locations/new'],
  ['Schedules index',      '/admin/slots/schedules'],
  ['Schedules — new',      '/admin/slots/schedules/new'],
  ['Blackout dates index', '/admin/slots/blackout-dates'],
  ['Blackout dates — new', '/admin/slots/blackout-dates/new'],
  ['Reports index',        '/admin/slots/reports'],
  ['Reports — revenue',    '/admin/slots/reports/revenue'],
  ['Settings — booking',   '/admin/slots/settings'],
  ['Settings — payments',  '/admin/slots/settings/payments'],
  ['Settings — security',  '/admin/slots/settings/security'],
  ['Settings — notifs',    '/admin/slots/settings/notifications'],
];

// Prefixes of real catalog keys ('calendar', 'labels', …). A screen that shows
// text shaped like `calendar.today` under one of these is rendering a key Craft
// could not resolve — which is what fifty missing keys looked like in the CP,
// with every screen still returning a clean 200.
const KEY_PREFIXES = [
  ...new Set(
    Array.from(
      readFileSync(
        join(dirname(fileURLToPath(import.meta.url)), '../../src/translations/en/slots.php'),
        'utf8',
      ).matchAll(/^\s*'([a-zA-Z][a-zA-Z0-9]*)\.[^']*'\s*=>/gm),
      (m) => m[1],
    ),
  ),
];

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

const consoleErrors = [];
page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });
page.on('pageerror', (e) => consoleErrors.push('pageerror: ' + e.message));

// --- login
await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
await page.locator('input[name="username"]:visible').first().fill(USER);
await page.locator('input[name="password"]:visible').first().fill(PASS);
await page.locator('button[type="submit"], #submit, .submit').first().click();
await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
console.log('logged in\n');

const results = [];
for (const [name, path] of SCREENS) {
  consoleErrors.length = 0;
  let status = 0, note = '';
  try {
    const res = await page.goto(BASE + path, { waitUntil: 'domcontentloaded', timeout: 30000 });
    status = res ? res.status() : 0;
    const body = await page.content();

    // Craft renders exceptions into the page with a 200 in some configs
    const exception = await page.locator('.exception-block, #exception, .error-details h1').first();
    if (await exception.count()) note = (await exception.innerText()).split('\n')[0].slice(0, 140);

    if (!note && /Fatal error|Uncaught \w*Exception|Twig\\Error|InvalidArgumentException|UnknownPropertyException|Undefined (variable|array key)/i.test(body)) {
      const m = body.match(/(Fatal error[^<]{0,140}|Uncaught [^<]{0,140}|Twig\\Error[^<]{0,140}|Undefined \w+[^<]{0,120})/i);
      note = m ? m[1].trim() : 'error text in body';
    }
    // an unstyled CP means the stylesheet failed to load
    if (!note) {
      const hasCss = await page.evaluate(() => !!document.querySelector('link[href*="slots-cp"], link[href*="cp.css"]'));
      if (!hasCss) note = 'no CP stylesheet link';
    }
    // an unresolved translation key renders as the key itself
    if (!note) {
      const raw = await page.evaluate((prefixes) => {
        const shape = /^[a-zA-Z][a-zA-Z0-9]*\.[a-zA-Z][a-zA-Z0-9.]*$/;
        const isKey = (s) => shape.test(s) && prefixes.includes(s.split('.')[0]);
        const found = new Set();

        const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
        for (let n = walker.nextNode(); n; n = walker.nextNode()) {
          const t = n.textContent.trim();
          if (isKey(t)) found.add(t);
        }
        for (const el of document.querySelectorAll('[title],[placeholder],[aria-label]')) {
          for (const a of ['title', 'placeholder', 'aria-label']) {
            const v = (el.getAttribute(a) || '').trim();
            if (isKey(v)) found.add(v);
          }
        }
        return [...found];
      }, KEY_PREFIXES);

      if (raw.length) note = `untranslated key(s): ${raw.slice(0, 4).join(', ')}`;
    }
  } catch (e) {
    note = 'navigation failed: ' + e.message.split('\n')[0].slice(0, 120);
  }

  const jsErrs = consoleErrors.filter((t) => !/favicon|Failed to load resource: the server responded with a status of 404/i.test(t));
  const ok = status === 200 && !note && jsErrs.length === 0;
  results.push({ name, path, status, note, jsErrs: jsErrs.slice(0, 2) });
  console.log(`${ok ? 'ok  ' : 'FAIL'}  ${String(status).padEnd(3)}  ${name.padEnd(22)} ${note || ''}${jsErrs.length ? '  js:' + jsErrs[0].slice(0, 100) : ''}`);
}

const bad = results.filter((r) => r.status !== 200 || r.note || r.jsErrs.length);
console.log(`\n${results.length - bad.length}/${results.length} screens clean`);
if (bad.length) {
  console.log('\nFAILURES:');
  for (const b of bad) console.log(`  ${b.path}\n    status=${b.status} ${b.note} ${b.jsErrs.join(' | ')}`);
}
await browser.close();
process.exit(bad.length ? 1 : 0);
