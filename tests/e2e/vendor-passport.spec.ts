import { test, expect, type Page } from '@playwright/test';

/**
 * Phase 27 — Vendor Passport & Reputation OS.
 *
 * Seed a public demo Passport first (local wp-env only):
 *   npx.cmd -y @wordpress/env@10 run cli wp eval-file wp-content/lnkup-e2e/php/vendor-passport-seed.php
 * Then: PLAYWRIGHT_PASSPORT_SLUG=demo-tacos npx playwright test tests/e2e/vendor-passport.spec.ts
 * Signed-in checks also need PLAYWRIGHT_PASSPORT_VENDOR_USER / PLAYWRIGHT_PASSPORT_VENDOR_PASS.
 */
const slug = process.env.PLAYWRIGHT_PASSPORT_SLUG || 'demo-tacos';
const vendorUser = process.env.PLAYWRIGHT_PASSPORT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_PASSPORT_VENDOR_PASS;

/**
 * Log into WordPress and open the vendor Passport dashboard view.
 */
async function openVendorPassport(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
  await page.goto('/vendor-dashboard/?view=passport');
}

test.describe('Public Passport', () => {
  test('pretty URL renders transparent indicators', async ({ page }) => {
    const response = await page.goto(`/vendors/${slug}/`);
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('lnkup-public-vendor-profile')).toBeVisible();
    await expect(page.getByTestId('lnkup-passport-indicators')).toBeVisible();
    await expect(page.getByText(/LNKup Score/i)).toHaveCount(0);
  });

  test('legacy query URL redirects permanently to the pretty URL', async ({ request }) => {
    const response = await request.get(`/vendors/?vendor=${slug}`, { maxRedirects: 0 });
    expect(response.status()).toBe(301);
    expect(response.headers()['location']).toContain(`/vendors/${slug}/`);
  });

  test('numeric IDs never resolve a public profile', async ({ page }) => {
    await page.goto('/vendors/?vendor=1');
    await expect(page.getByText(/private or no longer available/i)).toBeVisible();
  });

  test('page source has structured data but no private contact details', async ({ request }) => {
    const html = await (await request.get(`/vendors/${slug}/`)).text();
    expect(html).toContain('application/ld+json');
    expect(html).not.toMatch(/private-demo@example\.test|555-0199/);
  });
});

test.describe('Passport REST security', () => {
  test('public Passport JSON has no internal score or contact data', async ({ request }) => {
    const response = await request.get(`/wp-json/lnkup/v1/passport/vendors/${slug}`);
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.business_name).toBeTruthy();
    const text = JSON.stringify(body);
    expect(text).not.toMatch(/"score"|"breakdown"|private-demo@example\.test/);
  });

  test('anonymous numeric reputation routes are refused', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/reputation/vendors/1');
    expect([401, 403, 404]).toContain(response.status());
  });

  test('anonymous owner and organizer routes are refused', async ({ request }) => {
    expect((await request.get('/wp-json/lnkup/v1/passport/me')).status()).toBe(401);
    expect([401, 403]).toContain((await request.get('/wp-json/lnkup/v1/passport/vendors/1/organizer')).status());
    expect([401, 403]).toContain((await request.get('/wp-json/lnkup/v1/documents/shares/1/file')).status());
  });

  test('unknown slugs return JSON 404, never HTML', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/passport/vendors/does-not-exist-404');
    expect(response.status()).toBe(404);
    expect(response.headers()['content-type']).toContain('application/json');
  });
});

test.describe('Vendor Passport dashboard', () => {
  test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_PASSPORT_VENDOR_USER/PASS to run signed-in checks');

  test('loads overview and switches tabs without infinite loaders', async ({ page }) => {
    await openVendorPassport(page);
    await expect(page.getByTestId('lnkup-passport')).toBeVisible();
    await expect(page.getByRole('heading', { name: /profile completion/i })).toBeVisible();
    for (const tab of ['Portfolio', 'Verification', 'Event History', 'Reviews', 'Documents', 'Settings']) {
      await page.getByRole('tab', { name: tab }).click();
      await expect(page.locator('[data-passport-panel] [aria-busy="true"]')).toHaveCount(0, { timeout: 15000 });
    }
  });

  test('privacy toggle hides the public Passport', async ({ page, request }) => {
    await openVendorPassport(page);
    await page.getByRole('tab', { name: 'Settings' }).click();
    const publicToggle = page.getByLabel(/public passport page/i);
    await publicToggle.uncheck();
    await page.getByRole('button', { name: /save privacy settings/i }).click();
    await expect(page.getByRole('status')).toContainText(/saved/i);
    expect((await request.get(`/wp-json/lnkup/v1/passport/vendors/${slug}`)).status()).toBe(404);
    await publicToggle.check();
    await page.getByRole('button', { name: /save privacy settings/i }).click();
    await expect(page.getByRole('status')).toContainText(/saved/i);
  });
});
