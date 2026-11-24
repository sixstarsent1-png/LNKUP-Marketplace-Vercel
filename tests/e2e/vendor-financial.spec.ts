import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

/**
 * Log into WordPress with vendor credentials.
 */
async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Vendor financial dashboard', () => {
  test('payments view requires login when unauthenticated', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=payments');
    await expect(page).toHaveURL(/wp-login|log-?in/);
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('payments hub renders tabs and balance hero without connect onboarding on load', async ({ page }) => {
      const connectRequests: string[] = [];
      page.on('request', (req) => {
        if (req.url().includes('/financial/connect/onboarding-link')) {
          connectRequests.push(req.url());
        }
      });

      await page.goto('/vendor-dashboard/?view=payments');
      await expect(page.getByRole('heading', { level: 1, name: /^wallet$/i })).toBeVisible();
      await expect(page.locator('#lnkup-vendor-financial')).toBeVisible();
      await expect(page.locator('#lnkup-fin-balance-hero')).toBeVisible();
      for (const name of [/^overview$/i, /^invoices$/i, /^receipts$/i, /^payment methods$/i, /^reports$/i]) {
        await expect(page.getByRole('tab', { name })).toBeVisible();
      }
      await expect(page.locator('#lnkup-fin-vendor-overview')).not.toContainText('Could not load', { timeout: 15000 });
      expect(connectRequests).toHaveLength(0);
    });

    test('credits and refunds live under More', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=payments');
      await page.locator('.vos-tab-more > summary').click();
      await expect(page.getByRole('tab', { name: /^credits$/i })).toBeVisible();
      await page.getByRole('tab', { name: /^refunds$/i }).click();
      await expect(page.locator('#lnkup-refund-form')).toBeVisible();
    });
  });
});
