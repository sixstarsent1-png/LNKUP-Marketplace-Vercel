import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { appLogin, organizerUser, trackApiRequests, trackPageErrors, vendorUser } from './helpers/app-session';

const vendorRoutes = [
  '/(vendor)',
  '/(vendor)/find',
  '/(vendor)/requests',
  '/(vendor)/wallet',
  '/(vendor)/more',
  '/(vendor)/profile',
  '/(vendor)/documents',
  '/(vendor)/favorites',
  '/(vendor)/growth',
  '/(vendor)/reviews',
  '/(vendor)/operations',
  '/(vendor)/notifications',
  '/(vendor)/saved-searches',
  '/(vendor)/referrals',
];

const organizerRoutes = [
  '/(organizer)',
  '/(organizer)/events',
  '/(organizer)/vendors',
  '/(organizer)/money',
  '/(organizer)/more',
  '/(organizer)/launch',
  '/(organizer)/profile',
  '/(organizer)/notifications',
  '/(organizer)/referrals',
];

type RouteMetric = {
  route: string;
  ms: number;
  settled: boolean;
  apiCalls: number;
  calls: string[];
  pending: string[];
  errors: string[];
};

const label = process.env.MOBILE_METRICS_LABEL || 'baseline';
const reportDir = path.join(__dirname, '..', '..', 'scripts', 'e2e', 'reports');

/**
 * Visit each route after login and record time until the network settles, API calls, and errors.
 */
async function measure(page: import('@playwright/test').Page, routes: string[], shotPrefix: string) {
  const api = trackApiRequests(page);
  const errors = trackPageErrors(page);
  const inFlight = new Map<import('@playwright/test').Request, string>();
  page.on('request', (r) => {
    if (r.url().includes('/wp-json/')) inFlight.set(r, `${r.method()} ${r.url().replace(/^.*\/wp-json/, '')}`);
  });
  page.on('requestfinished', (r) => inFlight.delete(r));
  page.on('requestfailed', (r) => inFlight.delete(r));
  const metrics: RouteMetric[] = [];
  for (const route of routes) {
    api.reset();
    errors.length = 0;
    inFlight.clear();
    const started = Date.now();
    await page.goto(route);
    const settled = await page
      .waitForLoadState('networkidle', { timeout: 20_000 })
      .then(() => true)
      .catch(() => false);
    const ms = Date.now() - started;
    metrics.push({
      route,
      ms,
      settled,
      apiCalls: api.requests.length,
      calls: [...api.requests],
      pending: [...inFlight.values()],
      errors: [...errors],
    });
    const file = `${shotPrefix}-${route.replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '') || 'home'}.png`;
    await page.screenshot({ path: path.join(reportDir, 'mobile-shots', label, file) });
  }
  return metrics;
}

test.describe('Mobile baseline metrics', () => {
  test.setTimeout(600_000);

  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'iphone-15', 'Metrics run on one device');
  });

  test('vendor routes', async ({ page }) => {
    const started = Date.now();
    await appLogin(page, vendorUser);
    const loginMs = Date.now() - started;
    const metrics = await measure(page, vendorRoutes, 'vendor');
    fs.mkdirSync(reportDir, { recursive: true });
    fs.writeFileSync(path.join(reportDir, `mobile-${label}-vendor.json`), JSON.stringify({ loginMs, metrics }, null, 2));
    expect(metrics.length).toBe(vendorRoutes.length);
  });

  test('organizer routes', async ({ page }) => {
    const started = Date.now();
    await appLogin(page, organizerUser);
    const loginMs = Date.now() - started;
    const metrics = await measure(page, organizerRoutes, 'organizer');
    fs.mkdirSync(reportDir, { recursive: true });
    fs.writeFileSync(path.join(reportDir, `mobile-${label}-organizer.json`), JSON.stringify({ loginMs, metrics }, null, 2));
    expect(metrics.length).toBe(organizerRoutes.length);
  });
});
