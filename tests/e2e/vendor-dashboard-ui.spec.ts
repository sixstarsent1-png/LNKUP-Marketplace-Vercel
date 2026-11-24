import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? '';
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';

test.describe('Vendor dashboard UI', () => {
  test('vendor dashboard requires login', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page).toHaveURL(/log-?in/);
  });

  test('opportunities page shows the marketplace when public', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-marketplace')).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('searchbox').first()).toBeVisible();
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Run scripts/e2e/seed.php');
      await loginPage(page, vendorUser, vendorPass);
    });

    test('dashboard shell renders grouped navigation', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      const sidebar = page.locator('#lnkup-sidebar');
      await expect(sidebar).toBeVisible();
      for (const name of [/^home$/i, /^explore$/i, /bookings/i, /^wallet$/i]) {
        await expect(sidebar.getByRole('link', { name })).toBeVisible();
      }
      await expect(page.locator('.lnkup-dashboard__nav-more').first()).toBeVisible();
      const box = await sidebar.boundingBox();
      expect(box?.width ?? 0).toBeGreaterThanOrEqual(252);
      expect(box?.width ?? 0).toBeLessThanOrEqual(268);
      const iconBox = await page.locator('.lnkup-dashboard__nav-icon svg').first().boundingBox();
      expect(iconBox?.width ?? 99).toBeLessThanOrEqual(22);
      await expect(sidebar.getByRole('link', { name: /^home$/i })).toHaveClass(/is-active/);
    });

    test('home leads with welcome, next step, and linked metrics', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      await expect(page.getByTestId('lnkup-vendor-home-welcome')).toBeVisible();
      await expect(page.getByRole('heading', { level: 1, name: /get ready for your next event|your vendor business, all in one place|find your next opportunity/i })).toBeVisible();
      await expect(page.getByTestId('lnkup-action-center')).toBeVisible();
      const stats = page.getByTestId('lnkup-vendor-home-stats');
      await expect(stats.getByRole('link', { name: /payments due/i })).toHaveAttribute('href', /app_filter=pay-now/);
    });

    test('each view has its heading and highlights its nav link', async ({ page }) => {
      const views = [
        { query: 'applications', heading: /bookings/i, link: 'Bookings' },
        { query: 'documents', heading: /^documents$/i, link: 'Documents' },
        { query: 'payments', heading: /^wallet$/i, link: 'Wallet' },
        { query: 'operations', heading: /event operations/i, link: 'Event Operations' },
        { query: 'growth', heading: /^growth$/i, link: 'Growth' },
      ];
      for (const item of views) {
        await page.goto(`/vendor-dashboard/?view=${item.query}`);
        await expect(page.getByRole('heading', { level: 1, name: item.heading })).toBeVisible();
        await expect(page.locator('.lnkup-dashboard__nav-link.is-active')).toContainText(item.link);
      }
    });

    test('requests view has status tabs', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=applications');
      await expect(page.locator('.lnkup-dash-tabs').first()).toBeVisible();
      await expect(page.getByTestId('lnkup-vendor-request-card').first()).toBeVisible();
    });

    test('documents view lists document cards', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=documents');
      await expect(page.locator('.lnkup-doc-card').first()).toBeVisible();
    });

    test('wallet view loads the financial shell', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=payments');
      await expect(page.locator('#lnkup-fin-balance-hero')).toBeVisible();
      await expect(page.locator('#lnkup-vendor-financial')).toBeVisible();
    });

    test('find events uses the dashboard shell', async ({ page }) => {
      await page.goto('/opportunities/');
      await expect(page.locator('#lnkup-sidebar')).toBeVisible();
      await expect(page.locator('#lnkup-marketplace')).toBeVisible();
      await expect(page.locator('#lnkup-sidebar').getByRole('link', { name: /^explore$/i })).toHaveClass(/is-active/);
    });

    test('mobile bottom navigation and sidebar toggle work', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/vendor-dashboard/');
      await expect(page.locator('#lnkup-bottom-nav')).toBeVisible();
      for (let i = 0; i < 3; i += 1) {
        const onboarding = page.getByRole('dialog').filter({ visible: true }).first();
        if (!(await onboarding.isVisible())) break;
        await onboarding.getByRole('button', { name: /^close$|skip tour/i }).first().click();
        await expect(onboarding).toBeHidden();
      }
      const toggle = page.locator('#lnkup-menu-toggle');
      if (await toggle.isVisible()) {
        await toggle.click();
        await expect(page.locator('#lnkup-sidebar.is-open')).toBeVisible();
      }
    });
  });
});

test.describe('Organizer dashboard UI', () => {
  test('Requests view renders one page heading and one request list', async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
    await loginPage(page, organizerUser, organizerPass);
    await page.goto('/organizer-dashboard/?view=applications');
    await expect(page.locator('.lnkup-dashboard__content h1')).toHaveCount(1);
    await expect(page.getByRole('heading', { level: 1, name: /^requests$/i })).toBeVisible();
    await expect(page.locator('#lnkup-app-intel-grid')).toHaveCount(1);
  });
});
