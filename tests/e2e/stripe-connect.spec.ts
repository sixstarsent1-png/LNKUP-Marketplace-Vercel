import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

/**
 * Log into WordPress with the given credentials.
 */
async function loginUser(
  page: import('@playwright/test').Page,
  username: string,
  password: string
) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(username);
  await page.getByLabel(/^password$/i).fill(password);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Stripe Connect marketplace', () => {
  test.describe('organizer Connect REST', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginUser(page, organizerUser!, organizerPass!);
    });

    test('connect status returns JSON with settlement_mode', async ({ page }) => {
      const response = await page.request.get('/wp-json/lnkup/v1/financial/connect/status', {
        headers: { Accept: 'application/json' },
      });
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('status');
      expect(body).toHaveProperty('settlement_mode');
      expect(['destination', 'platform_treasury']).toContain(body.settlement_mode);
    });

    test('onboarding link returns structured JSON when Connect unavailable', async ({ page }) => {
      const response = await page.request.post('/wp-json/lnkup/v1/financial/connect/onboarding-link', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: {},
      });
      expect(response.status()).toBeLessThan(500);
      const body = await response.json();
      expect(typeof body).toBe('object');
      if (body.success === false) {
        expect(body).toHaveProperty('code');
        expect(body).toHaveProperty('message');
      }
    });

    test('organizer financial shell shows Connect banner area', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await expect(page.locator('#lnkup-fin-connect-banner')).toBeAttached();
      await expect(page.locator('#lnkup-organizer-financial')).toBeVisible();
    });

    test('connect refresh endpoint returns JSON', async ({ page }) => {
      const response = await page.request.post('/wp-json/lnkup/v1/financial/connect/refresh', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: {},
      });
      expect(response.status()).toBeLessThan(500);
      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toMatch(/json/);
    });
  });

  test.describe('vendor isolation from Connect', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginUser(page, vendorUser!, vendorPass!);
    });

    test('vendor financial shell has no Connect onboarding CTA', async ({ page }) => {
      await page.goto('/vendor-dashboard/#financial');
      await expect(page.locator('#lnkup-vendor-financial')).toBeVisible();
      await expect(page.getByRole('button', { name: /connect stripe/i })).toHaveCount(0);
      await expect(page.locator('#lnkup-fin-connect-banner')).toHaveCount(0);
    });

    test('vendor cannot access organizer connect onboarding route', async ({ page }) => {
      const response = await page.request.post('/wp-json/lnkup/v1/financial/connect/onboarding-link', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: {},
      });
      expect([401, 403]).toContain(response.status());
    });
  });
});
