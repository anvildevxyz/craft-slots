/** Loads the CP and front end and reports any visible "Booked" text. */
import { chromium } from '@playwright/test';
const BASE = process.env.SLOTS_CP_BASE ?? 'https://craft-plugin-dev.ddev.site';
const b = await chromium.launch({ executablePath: process.env.CHROME_PATH, args:['--ignore-certificate-errors'] });
const p = await (await b.newContext({ ignoreHTTPSErrors: true })).newPage();
await p.goto(`${BASE}/admin/login`, { waitUntil:'networkidle' });
await p.locator('input[name="username"]:visible').first().fill(process.env.SLOTS_CP_USER);
await p.locator('input[name="password"]:visible').first().fill(process.env.SLOTS_CP_PASS);
await p.locator('button[type="submit"], #submit, .submit').first().click();
await p.waitForURL(/\/admin(?!\/login)/, { timeout:30000 });
// This dev site also has the upstream Booked plugin installed, so the global
// CP chrome (nav, plugin list, permission tree) legitimately says "Booked".
// Only the Slots-owned content area is ours to keep clean.
const PAGES = [
  ['Dashboard','/admin/slots'], ['Bookings','/admin/slots/bookings'],
  ['Services','/admin/slots/services'], ['Employees','/admin/slots/employees'],
  ['Locations','/admin/slots/locations'], ['Schedules','/admin/slots/schedules'],
  ['Reports','/admin/slots/reports'], ['Settings','/admin/slots/settings'],
  ['Settings payments','/admin/slots/settings/payments'],
  ['Front-end wizard','/slots-smoke'],
];
let bad = 0;
for (const [name, path] of PAGES) {
  await p.goto(BASE + path, { waitUntil:'domcontentloaded', timeout:30000 });
  await p.waitForTimeout(1200);
  const hits = await p.evaluate(() => {
    const out = [];
    const scope = document.querySelector('#content, #main, main') || document.body;
    const w = document.createTreeWalker(scope, NodeFilter.SHOW_TEXT);
    for (let n = w.nextNode(); n; n = w.nextNode()) {
      const t = n.textContent.trim();
      // "Booked On" / "fully booked" are ordinary English, not the brand
      if (/\bBooked\b/.test(t) && !/Booked On|fully booked|already booked/i.test(t)) out.push(t.slice(0,70));
    }
    return [...new Set(out)].slice(0,4);
  });
  console.log(`${hits.length ? 'FAIL' : 'ok  '}  ${name.padEnd(18)} ${hits.join(' | ')}`);
  bad += hits.length ? 1 : 0;
}
console.log(`\n${PAGES.length - bad}/${PAGES.length} pages free of upstream branding`);
await b.close(); process.exit(bad ? 1 : 0);
