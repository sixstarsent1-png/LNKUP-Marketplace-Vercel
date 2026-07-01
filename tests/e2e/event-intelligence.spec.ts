import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Event Intelligence', () => {
  test('intelligence panel requires login', async ({ page }) => {
    await page.goto('/organizer-dashboard/#intelligence');
    await expect(page).toHaveURL(/wp-login|login/);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('organizer intelligence panel loads', async ({ page }) => {
      await page.goto('/organizer-dashboard/#intelligence');
      await expect(page.locator('#intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-organizer-intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-organizer-intelligence')).not.toContainText('Loading intelligence', { timeout: 15000 });
      await expect(page.locator('#lnkup-organizer-intelligence .lnkup-intel-dashboard--loading')).toHaveCount(0, { timeout: 15000 });
      await expect(page.locator('#lnkup-organizer-intelligence .lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
    });

    test('organizer intelligence expand button clears skeleton', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      await expect(page.locator('#intelligence')).toBeVisible();
      await page.locator('[data-expand-panel="intelligence"]').click();
      await expect(page.locator('#lnkup-organizer-expand-intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-organizer-intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-organizer-intelligence .lnkup-intel-dashboard--loading')).toHaveCount(0, { timeout: 15000 });
      await expect(page.locator('#lnkup-organizer-intelligence .lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
    });

    test('application intel button opens drawer', async ({ page }) => {
      await page.goto('/organizer-dashboard/#applications');
      const intelBtn = page.locator('.lnkup-intel-open').first();
      if (!(await intelBtn.count())) {
        test.skip(true, 'No applications with intel button');
        return;
      }
      await intelBtn.click();
      await expect(page.locator('#lnkup-intel-drawer')).toBeVisible();
      await expect(page.locator('#lnkup-intel-drawer-content')).not.toContainText('Loading application intelligence', { timeout: 15000 });
    });

    test('operations command center has insights tab', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await page.getByRole('button', { name: /^insights$/i }).click();
      await expect(page.locator('#lnkup-event-insights')).toBeVisible();
    });
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('vendor intelligence panel loads', async ({ page }) => {
      await page.goto('/vendor-dashboard/#intelligence');
      await expect(page.locator('#intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-vendor-intelligence')).toBeVisible();
    });
  });
});

test.describe('Event Intelligence regression', () => {
  test('marketplace and operations pages still load', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('body')).toBeVisible();
    await page.goto('/organizer-dashboard/');
    await expect(page).toHaveURL(/wp-login|login|organizer-dashboard/);
  });
});
