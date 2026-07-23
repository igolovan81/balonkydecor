import { defineConfig, devices } from '@playwright/test';

const PORT = 8080;
const BASE_URL = process.env.E2E_BASE_URL ?? `http://localhost:${PORT}`;
const isLocal = /^https?:\/\/(localhost|127\.0\.0\.1)(:|\/|$)/.test(BASE_URL);

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  reporter: [['html', { open: 'never' }]],
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
  ],
  // Only manage a local PHP server when targeting localhost — a prod run
  // (E2E_BASE_URL pointing at balonkydecor.cz) must never spin one up.
  webServer: isLocal
    ? {
        command: `php -S localhost:${PORT} -t www`,
        url: `${BASE_URL}/cs/`,
        reuseExistingServer: !process.env.CI,
        cwd: __dirname,
      }
    : undefined,
});
