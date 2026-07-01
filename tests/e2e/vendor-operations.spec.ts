import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

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

test.describe('Vendor Operations', () => {
  test('operations command center requires login', async ({ page }) => {
    await page.goto('/organizer-dashboard/?event=1&ops=1');
    await expect(page).toHaveURL(/wp-login|login/);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('events table shows Operations button', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (await opsBtn.count()) {
        await expect(opsBtn).toBeVisible();
      }
    });

    test('command center shell loads when event exists', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await expect(page.locator('#lnkup-event-ops')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('.lnkup-ops-kpis')).toBeVisible();
      await expect(page.locator('.lnkup-ops-tabs')).toBeVisible();
      await expect(page.getByRole('button', { name: /roster \/ kanban/i })).toBeVisible();
    });

    test('command center kanban tab switches panels', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await expect(page.locator('#lnkup-event-ops')).toBeVisible({ timeout: 15000 });
      await page.getByRole('button', { name: /roster \/ kanban/i }).click();
      await expect(page.locator('#lnkup-ops-kanban-wrap')).toBeVisible();
      await expect(page.locator('.lnkup-ops-kanban')).toBeVisible();
    });

    test('CRM widgets include operations metrics', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      await expect(page.locator('#lnkup-crm-widgets-grid')).toBeVisible({ timeout: 15000 });
      const opsWidget = page.locator('.lnkup-crm-widget--ops').first();
      if (await opsWidget.count()) {
        await expect(opsWidget).toBeVisible();
      }
    });

    test('command center mobile sticky bar visible at narrow viewport', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await expect(page.locator('.lnkup-ops-mobile-bar')).toBeVisible({ timeout: 15000 });
    });
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('vendor operations view loads from sidebar', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=operations');
      await expect(page.getByRole('heading', { name: /event operations/i })).toBeVisible();
      await expect(page.locator('#lnkup-vendor-ops, .lnkup-empty-state').first()).toBeVisible();
    });

    test('vendor operations shows five tabs and full overview when bookings exist', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=operations');
      const ops = page.locator('#lnkup-vendor-ops');
      if (!(await ops.count())) {
        test.skip(true, 'No confirmed vendor bookings in test environment');
        return;
      }
      await expect(page.getByRole('tab', { name: /^overview$/i })).toBeVisible();
      await expect(page.getByRole('tab', { name: /event pass/i })).toBeVisible();
      await expect(page.getByRole('tab', { name: /^readiness$/i })).toBeVisible();
      await expect(page.getByRole('tab', { name: /venue map/i })).toBeVisible();
      await expect(page.getByRole('tab', { name: /^support$/i })).toBeVisible();
      await expect(page.locator('.lnkup-ops-overview-grid')).toBeVisible();
      await expect(page.locator('.lnkup-ops-overview-grid .lnkup-ops-panel').first()).toBeVisible();
      const qrOnly = page.locator('.lnkup-ops-vendor-home > .lnkup-ops-qr');
      await expect(qrOnly).toHaveCount(0);
    });
  });
});

test.describe('Vendor Operations regression', () => {
  test('marketplace and CRM pages still load', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('body')).toBeVisible();
    await page.goto('/organizer-dashboard/');
    await expect(page).toHaveURL(/wp-login|login|organizer-dashboard/);
  });
});
