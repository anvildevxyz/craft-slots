import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright config for the direct-payment browser E2E. Run from the plugin root:
 *   SLOTS_E2E_URL=https://your-site/wizard/service npx playwright test -c tests/e2e/playwright.config.ts
 */
export default defineConfig({
  testDir: '.',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  fullyParallel: false,
  // Every spec drives the one DDEV database and mutates shared fixtures
  // (schedules, services, reservations). Parallel workers would run those
  // mutations against each other, so the suite is single-threaded by design.
  workers: 1,
  retries: 0,
  reporter: 'list',
  use: {
    baseURL: process.env.SLOTS_E2E_URL,
    ignoreHTTPSErrors: true, // DDEV self-signed certs
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
  },
  projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
