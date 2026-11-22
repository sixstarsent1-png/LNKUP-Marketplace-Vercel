import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { defineConfig, devices } from '@playwright/test';

/**
 * Load scripts/e2e/.env.e2e (written by seed.php) so a plain `npx playwright test` targets the
 * seeded wp-env and its fixture IDs. Variables already set in the shell win.
 */
function loadSeedEnv(): void {
  const file = join(__dirname, 'scripts', 'e2e', '.env.e2e');
  if (!existsSync(file)) return;
  for (const line of readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = /^([A-Z0-9_]+)=(.*)$/.exec(line);
    if (!match) continue;
    const [, name, value] = match;
    if (process.env[name] === undefined) {
      process.env[name] = value;
    } else if (name === 'PLAYWRIGHT_BASE_URL' && process.env[name] !== value) {
      console.warn(
        `PLAYWRIGHT_BASE_URL=${process.env[name]} but fixtures were seeded on ${value}; fixture IDs will not match.`,
      );
    }
  }
}

loadSeedEnv();

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
