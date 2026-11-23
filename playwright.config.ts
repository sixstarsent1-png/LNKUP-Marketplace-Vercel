import { defineConfig, devices } from '@playwright/test';
import { loadSeedEnv } from './tests/support/seed-env';

loadSeedEnv(__dirname);

const startAppServer = !!process.env.CI && !process.env.PLAYWRIGHT_APP_URL;

export default defineConfig({
  testDir: './tests/e2e',
  webServer: startAppServer
    ? {
        command: 'npm --prefix lnk-up-marketplace-ui run start -- -p 3000',
        url: 'http://localhost:3000',
        reuseExistingServer: false,
        timeout: 120_000,
      }
    : undefined,
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // One wp-env Apache container: more than two workers causes intermittent reauth=1 logins and timeouts.
  workers: process.env.PLAYWRIGHT_WORKERS ? Number(process.env.PLAYWRIGHT_WORKERS) : process.env.CI ? undefined : 2,
  // Shared CI runners hydrate the production build more slowly than dev machines.
  expect: { timeout: process.env.CI ? 10_000 : 5_000 },
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8888',
    trace: 'on-first-retry',
  },
  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'mobile', use: { ...devices['iPhone 13'] } },
    {
      name: 'lnkup-app',
      use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000',
      },
      testMatch: /lnkup-app-.*\.spec\.ts/,
      testIgnore: /lnkup-app-phase5-mobile\.spec\.ts/,
    },
    {
      name: 'lnkup-app-mobile',
      use: {
        ...devices['iPhone 13'],
        browserName: 'chromium',
        baseURL: process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000',
      },
      testMatch: /lnkup-app-phase5-mobile\.spec\.ts/,
    },
  ],
});
