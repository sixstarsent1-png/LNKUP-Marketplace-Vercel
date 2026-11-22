import { defineConfig, devices } from '@playwright/test';
import { loadSeedEnv } from './tests/support/seed-env';

loadSeedEnv(__dirname);

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 2 : 0,
  // One wp-env Apache container: more than two workers causes intermittent reauth=1 logins and timeouts.
  workers: process.env.PLAYWRIGHT_WORKERS ? Number(process.env.PLAYWRIGHT_WORKERS) : process.env.CI ? undefined : 2,
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
