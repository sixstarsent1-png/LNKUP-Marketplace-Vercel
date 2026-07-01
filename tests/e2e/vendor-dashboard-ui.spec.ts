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

test.describe('Vendor dashboard UI', () => {
  test('vendor dashboard requires login when unauthenticated', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page).toHaveURL(/wp-login|login/);
  });

  test('opportunities page shows marketplace shell when public', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-marketplace')).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('searchbox').first()).toBeVisible();
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('dashboard shell renders with grouped navigation links', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      await expect(page.locator('.lnkup-dashboard')).toBeVisible();
      await expect(page.locator('#lnkup-sidebar')).toBeVisible();
      await expect(page.getByRole('link', { name: /^home$/i })).toBeVisible();
      await expect(page.getByRole('link', { name: /find events/i })).toBeVisible();
      await expect(page.getByRole('link', { name: /event operations/i })).toBeVisible();
      await expect(page.getByRole('link', { name: /applications/i })).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__nav-section').first()).toBeVisible();

      const sidebar = page.locator('#lnkup-sidebar');
      const sidebarBox = await sidebar.boundingBox();
      expect(sidebarBox?.width).toBeGreaterThanOrEqual(252);
      expect(sidebarBox?.width).toBeLessThanOrEqual(268);

      const navIcon = page.locator('.lnkup-dashboard__nav-icon svg').first();
      await expect(navIcon).toBeVisible();
      const iconBox = await navIcon.boundingBox();
      expect(iconBox?.width).toBeLessThanOrEqual(20);
      expect(iconBox?.height).toBeLessThanOrEqual(20);

      const homeLink = page.getByRole('link', { name: /^home$/i });
      await expect(homeLink).toHaveClass(/is-active/);

      await expect(page.locator('.lnkup-dashboard__workspace-chevron').first()).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__sidebar-footer')).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__profile')).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__user-card')).toHaveCount(0);
    });

    test('home view shows welcome header and week strip', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      await expect(page.getByRole('heading', { name: /welcome back/i })).toBeVisible();
      await expect(page.locator('.lnkup-vendor-week-strip')).toBeVisible();
      await expect(page.locator('.lnkup-stat-card').first()).toBeVisible();
      const icon = page.locator('.lnkup-stat-card__icon').first();
      await expect(icon).toBeVisible();
      const box = await icon.boundingBox();
      expect(box?.width).toBeLessThanOrEqual(48);
      expect(box?.height).toBeLessThanOrEqual(48);
      const svg = icon.locator('svg').first();
      const svgBox = await svg.boundingBox();
      expect(svgBox?.width).toBeLessThanOrEqual(20);
      expect(svgBox?.height).toBeLessThanOrEqual(20);
    });

    test('view navigation renders correct headings and active nav', async ({ page }) => {
      const views = [
        { query: 'applications', heading: /my applications/i, link: /applications/i },
        { query: 'documents', heading: /documents & compliance/i, link: /documents/i },
        { query: 'payments', heading: /^payments$/i, link: /payments/i },
        { query: 'operations', heading: /event operations/i, link: /event operations/i },
        { query: 'growth', heading: /^growth$/i, link: /^growth$/i },
      ];
      for (const item of views) {
        await page.goto(`/vendor-dashboard/?view=${item.query}`);
        await expect(page.getByRole('heading', { name: item.heading })).toBeVisible();
        await expect(page.getByRole('link', { name: item.link })).toHaveClass(/is-active/);
      }
    });

    test('applications view shows table headers', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=applications');
      await expect(page.getByRole('heading', { name: /my applications/i })).toBeVisible();
      await expect(page.locator('.lnkup-dash-tabs')).toBeVisible();
      const table = page.locator('#lnkup-vendor-apps-table');
      if (await table.count()) {
        await expect(table.getByRole('columnheader', { name: /event/i })).toBeVisible();
        await expect(table.getByRole('columnheader', { name: /status/i })).toBeVisible();
      }
    });

    test('documents view shows compliance score', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=documents');
      await expect(page.getByRole('heading', { name: /documents & compliance/i })).toBeVisible();
      await expect(page.locator('.lnkup-doc-score')).toBeVisible();
      await expect(page.locator('.lnkup-doc-card').first()).toBeVisible();
    });

    test('payments view loads financial shell', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=payments');
      await expect(page.getByRole('heading', { name: /^payments$/i })).toBeVisible();
      await expect(page.locator('#payments')).toBeVisible();
      await expect(page.locator('#lnkup-fin-balance-hero')).toBeVisible();
      await expect(page.locator('#lnkup-vendor-financial')).toBeVisible();
    });

    test('find events shows breadcrumb in vendor shell', async ({ page }) => {
      await page.goto('/opportunities/');
      await expect(page.locator('.lnkup-dash-breadcrumb')).toBeVisible();
      await expect(page.getByRole('searchbox').first()).toBeVisible();
    });

    test('mobile bottom navigation is visible', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/vendor-dashboard/');
      await expect(page.locator('#lnkup-bottom-nav')).toBeVisible();
    });

    test('find events uses dashboard shell for vendor', async ({ page }) => {
      await page.goto('/opportunities/');
      await expect(page.locator('.lnkup-dashboard')).toBeVisible();
      await expect(page.locator('#lnkup-sidebar')).toBeVisible();
      await expect(page.locator('#lnkup-marketplace')).toBeVisible();
      await expect(page.getByRole('link', { name: /find events/i })).toHaveClass(/is-active/);
    });

    test('mobile sidebar toggle opens navigation', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/vendor-dashboard/');
      await page.locator('#lnkup-menu-toggle').click();
      await expect(page.locator('#lnkup-sidebar.is-open')).toBeVisible();
    });
  });
});

test.describe('Organizer dashboard UI cleanup', () => {
  const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
  const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(organizerUser!);
    await page.getByLabel(/^password$/i).fill(organizerPass!);
    await page.getByRole('button', { name: /log in/i }).click();
  });

  test('applications section appears once', async ({ page }) => {
    await page.goto('/organizer-dashboard/#applications');
    await expect(page.getByRole('heading', { name: /review applications/i })).toHaveCount(1);
    await expect(page.locator('#lnkup-app-intel-grid .lnkup-crm-app-grid')).toHaveCount(1);
  });
});
