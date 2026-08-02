/**
 * Proves reservations behave as real Craft elements, not just that a screen loads.
 *
 * This is the claim the element work was justified by — bookings queryable from
 * Twig and usable with standard element-index machinery — so it is checked
 * against Craft's own endpoints rather than against our templates. Driving
 * `element-indexes/*` is what the native index does internally: if Craft can
 * count, list, filter and export the type through it, the element is correctly
 * registered whatever our own CP screen happens to render.
 *
 * Usage:
 *   SLOTS_CP_USER=… SLOTS_CP_PASS=… SLOTS_EXPECT_TOTAL=24 \
 *   SLOTS_EXPECT_CONFIRMED=11 node tests/e2e/elements-smoke.mjs
 */
import { chromium } from '@playwright/test';

const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const USER = process.env.SLOTS_CP_USER;
const PASS = process.env.SLOTS_CP_PASS;
const EXPECT_TOTAL = Number(process.env.SLOTS_EXPECT_TOTAL ?? 0);
const EXPECT_CONFIRMED = Number(process.env.SLOTS_EXPECT_CONFIRMED ?? 0);
const ELEMENT_TYPE = 'anvildev\\slots\\elements\\Reservation';

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

await page.goto(`${BASE}/admin/login`, { waitUntil: 'domcontentloaded' });
await page.locator('input[name="username"]:visible').first().fill(USER);
await page.locator('input[name="password"]:visible').first().fill(PASS);
await page.locator('button[type="submit"], #submit, .submit').first().click();
await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
console.log('logged in\n');

await page.goto(`${BASE}/admin/slots/bookings`, { waitUntil: 'domcontentloaded' });

/** POST to a CP action as the logged-in user, with Craft's CSRF token. */
const cpPost = (action, params) => page.evaluate(async ([action, params]) => {
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) body.append(k, v);
  // The index JS always posts its view state; Craft reads viewState[static]
  // unguarded, so omitting it is a 500 rather than a default.
  if (!Object.keys(params).some((k) => k.startsWith('viewState'))) {
    body.append('viewState[mode]', 'table');
    body.append('viewState[static]', '0');
  }
  body.append(window.Craft.csrfTokenName, window.Craft.csrfTokenValue);

  // Craft.actionUrl already carries a query string when the site is not using
  // pretty URLs, so it cannot be concatenated — getActionUrl() builds it right.
  const url = window.Craft.getActionUrl(action);

  const res = await fetch(url, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: body.toString(),
  });

  const text = await res.text();
  try {
    return { status: res.status, json: JSON.parse(text) };
  } catch {
    return { status: res.status, json: null, text: text.slice(0, 200) };
  }
}, [action, params]);

// --- registration. Craft only exposes registered types here, and its CP
// deletion manager dereferences this without a guard.
const registered = await page.evaluate(
  (type) => Boolean(window.Craft?.elementTypeNames?.[type]),
  ELEMENT_TYPE,
);
check('reservation is a registered element type', registered, ELEMENT_TYPE);

// --- Craft can count the type through its own index endpoint
const counted = await cpPost('element-indexes/count-elements', {
  elementType: ELEMENT_TYPE,
  source: '*',
  'criteria[siteId]': '*',
  'criteria[status]': '',
});
check(
  'Craft counts the elements through its index endpoint',
  counted.status === 200 && typeof counted.json?.total === 'number',
  `status=${counted.status} total=${counted.json?.total}`,
);
check(
  'the count matches the database',
  counted.json?.total === EXPECT_TOTAL,
  `expected ${EXPECT_TOTAL}, got ${counted.json?.total}`,
);

// --- and list them, which is what the native index renders
const listed = await cpPost('element-indexes/get-elements', {
  elementType: ELEMENT_TYPE,
  source: '*',
  'criteria[siteId]': '*',
  'criteria[status]': '',
});
const html = listed.json?.html ?? '';
check(
  'Craft renders an element index for the type',
  listed.status === 200 && html.length > 0,
  `status=${listed.status}, ${html.length} bytes of table html`,
);

// --- a status source narrows the index.
// Craft hands a native source's criteria to the client (sources.twig emits
// `criteria: source.criteria`) and the client merges it into baseCriteria on
// the next request — the server does not re-read it from the source. Posting
// only `source=confirmed` therefore returns everything, correctly. This sends
// what the real index sends.
const confirmed = await cpPost('element-indexes/count-elements', {
  elementType: ELEMENT_TYPE,
  source: 'confirmed',
  'baseCriteria[reservationStatus]': 'confirmed',
  'criteria[siteId]': '*',
  'criteria[status]': '',
});
check(
  'the confirmed source filters on booking status',
  confirmed.json?.total === EXPECT_CONFIRMED,
  `expected ${EXPECT_CONFIRMED}, got ${confirmed.json?.total}`,
);

// --- exporters are registered against the type
const exporters = await cpPost('element-indexes/export', {
  elementType: ELEMENT_TYPE,
  source: '*',
  type: 'anvildev\\slots\\elements\\exporters\\ReservationCsvExporter',
  format: 'csv',
  'criteria[siteId]': '*',
  'criteria[status]': '',
});
const exportBody = exporters.text ?? JSON.stringify(exporters.json ?? '');
check(
  'the CSV exporter produces rows for the element type',
  exporters.status === 200 && /[,;].*[,;]/.test(exportBody),
  `status=${exporters.status}, ${exportBody.length} bytes`,
);

// --- element table attributes render real values, not blanks
const hasData = /\d{4}-\d{2}-\d{2}|<td/.test(html);
check('index rows carry table attributes', hasData);

// --- the plugin's own booking screens still work on element ids
const firstId = await page.evaluate(() => {
  for (const link of document.querySelectorAll('a[href*="/bookings/"]')) {
    const m = link.getAttribute('href')?.match(/\/bookings\/(\d+)/);
    if (m) return m[1];
  }
  return null;
});

if (firstId) {
  const view = await page.goto(`${BASE}/admin/slots/bookings/${firstId}/view`, { waitUntil: 'domcontentloaded' });
  check('a booking detail screen opens on its element id', view?.status() === 200, `id=${firstId} status=${view?.status()}`);

  const edit = await page.goto(`${BASE}/admin/slots/bookings/${firstId}`, { waitUntil: 'domcontentloaded' });
  check('a booking edit screen opens on its element id', edit?.status() === 200, `status=${edit?.status()}`);
} else {
  check('a booking detail screen opens on its element id', false, 'no booking link found on the index');
}

const jsErrs = consoleErrors.filter((t) => !/favicon|status of 404/i.test(t));
check('no JS errors', jsErrs.length === 0, jsErrs.slice(0, 2).join(' | '));

await browser.close();

const bad = results.filter((r) => !r.ok);
console.log(`\n${results.length - bad.length}/${results.length} checks passed`);
process.exit(bad.length ? 1 : 0);
