import { defineConfig, devices } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

/**
 * Seeded record IDs written by scripts/e2e/seed.php. Only IDs, users and the pass code are read;
 * URLs come from this config so a seed run against another port can't redirect the tests.
 * Explicit environment variables still win.
 */
const seedEnv = path.join(__dirname, 'scripts', 'e2e', '.env.e2e');
if (fs.existsSync(seedEnv)) {
  for (const line of fs.readFileSync(seedEnv, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^(PLAYWRIGHT_[A-Z0-9_]*(?:_ID|_USER|_PASS_CODE))=(.*)$/);
    if (match && process.env[match[1]] === undefined) process.env[match[1]] = match[2];
  }
}

const appUrl = process.env.PLAYWRIGHT_MOBILE_APP_URL || 'http://localhost:8081';

/**
 * Mobile app (Expo web build of lnk-up-marketplace-mobile) against local wp-env.
 * Chromium with iPhone viewports, touch, and user agents; device-only behaviour (push, camera,
 * universal links, the native in-app browser) still needs a device check.
 */
export default defineConfig({
  testDir: './tests/mobile',
  fullyParallel: false,
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  retries: 0,
  reporter: [['line']],
  use: {
    baseURL: appUrl,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  webServer: {
    command:
      'npx expo start --web --port 8081',
    cwd: './lnk-up-marketplace-mobile',
    url: appUrl,
    reuseExistingServer: true,
    timeout: 240_000,
    env: {
      EXPO_PUBLIC_WORDPRESS_API_URL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8888',
      EXPO_PUBLIC_LNKUP_LIVE_API: 'true',
      EXPO_PUBLIC_LNKUP_API_TIMEOUT_MS: '45000',
      BROWSER: 'none',
      CI: '1',
    },
  },
  projects: [
    { name: 'iphone-15', use: { ...devices['iPhone 15'], browserName: 'chromium' } },
    { name: 'iphone-se', use: { ...devices['iPhone SE'], browserName: 'chromium' } },
    { name: 'iphone-15-pro-max', use: { ...devices['iPhone 15 Pro Max'], browserName: 'chromium' } },
  ],
});
