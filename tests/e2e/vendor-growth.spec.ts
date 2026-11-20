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

test.describe('Vendor growth dashboard', () => {
  test('growth view requires login when unauthenticated', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=growth');
    await expect(page).toHaveURL(/wp-login|log-?in/);
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('growth dashboard renders shell and KPI strip', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=growth');
      await expect(page.getByRole('heading', { name: /^growth$/i })).toBeVisible();
      await expect(page.locator('#lnkup-vendor-growth')).toBeVisible();
      await expect(page.getByRole('button', { name: /^overview$/i })).toBeVisible();
      await expect(page.getByRole('button', { name: /^revenue$/i })).toBeVisible();
      await expect(page.getByRole('button', { name: /^markets$/i })).toBeVisible();
      await expect(page.locator('#lnkup-growth-kpis .lnkup-growth-kpi').first()).toBeVisible({ timeout: 15000 });
    });

    test('revenue tab loads on click', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=growth');
      await page.getByRole('button', { name: /^revenue$/i }).click();
      await expect(page.locator('#lnkup-growth-revenue.is-active')).toBeVisible();
      await expect(page.locator('#lnkup-growth-revenue')).not.toBeEmpty({ timeout: 15000 });
    });

    test('sidebar includes Growth nav link', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      await expect(page.getByRole('link', { name: /^growth$/i })).toBeVisible();
    });
  });
});
