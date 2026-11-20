import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

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

/**
 * Log into WordPress with organizer credentials.
 */
async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Vendor Reputation', () => {
  test('vendors page without a slug shows the public vendor directory', async ({ page }) => {
    const response = await page.goto('/vendors/');
    if (!response || response.status() === 404) {
      test.skip(true, 'Vendors public profile page not deployed on this environment');
      return;
    }
    expect(response.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1, name: /vendors/i })).toBeVisible();
  });

  test('unknown vendor slug shows vendor not found', async ({ page }) => {
    await page.goto('/vendors/?vendor=zz-no-such-vendor');
    await expect(page.getByText(/vendor not found/i)).toBeVisible();
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('vendor dashboard shows reputation panel', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=reviews');
      await expect(page.locator('#reviews')).toBeVisible();
      await expect(page.locator('.lnkup-rep-score, .lnkup-rep-score--new').first()).toBeVisible();
    });

    test('reputation panel shows reliability meter or tips', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=reviews');
      const meter = page.locator('.lnkup-rep-meter');
      const tips = page.locator('.lnkup-rep-tips');
      await expect(meter.or(tips)).toBeVisible();
    });
  });

  test.describe('authenticated organizer reputation intel', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('vendor directory has reputation sort control', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      await expect(page.locator('#lnkup-crm-vendor-sort')).toBeVisible();
      await expect(page.locator('#lnkup-crm-vendor-sort')).toContainText(/score|rating/i);
    });

    test('application cards show score intel when applications exist', async ({ page }) => {
      await page.goto('/organizer-dashboard/#applications');
      const cards = page.locator('.lnkup-crm-app-card');
      const count = await cards.count();
      if (count === 0) {
        await expect(page.getByText(/no applications/i)).toBeVisible();
        return;
      }
      await expect(cards.first().locator('.lnkup-crm-app-card__intel')).toBeVisible();
      await expect(cards.first().locator('.lnkup-crm-app-card__intel')).toContainText(/score/i);
    });
  });
});

test.describe('Vendor Reputation regression', () => {
  test('opportunities marketplace still loads', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.getByRole('heading', { name: /find events/i })).toBeVisible();
    await expect(page.locator('#lnkup-results-grid')).toBeVisible();
  });

  test('vendor dashboard route responds', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page).toHaveURL(/vendor-dashboard|wp-login|log-?in/);
  });
});
