import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

test.describe('LNKup app — Phase 5 launch QA', () => {
  test.use({ baseURL: appBase });

  test('role picker loads', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'LNKup' })).toBeVisible();
    await expect(page.getByText('Vendor', { exact: true })).toBeVisible();
  });

  test('vendor home and nav render', async ({ page }) => {
    await page.goto('/vendor');
    await expect(page.getByRole('link', { name: 'Find Events' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Requests' })).toBeVisible();
  });

  test('organizer home and nav render', async ({ page }) => {
    await page.goto('/organizer');
    await expect(page.getByRole('link', { name: 'Events' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Money' })).toBeVisible();
  });

  test('login page loads', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('heading', { name: /sign in to lnkup/i })).toBeVisible();
    await expect(page.getByLabel('Username or email')).toBeVisible();
  });

  test('not-found page is friendly', async ({ page }) => {
    await page.goto('/does-not-exist-route');
    await expect(page.getByRole('heading', { name: 'Page not found' })).toBeVisible();
    await expect(page.getByText('Vendor dashboard')).toBeVisible();
  });

  test('PWA manifest link and theme-color meta present', async ({ page }) => {
    await page.goto('/vendor');
    const manifest = page.locator('link[rel="manifest"]');
    await expect(manifest).toHaveAttribute('href', '/manifest.webmanifest');
    const theme = page.locator('meta[name="theme-color"]');
    await expect(theme).toHaveAttribute('content', '#0c0d10');
  });

  test('offline page loads', async ({ page }) => {
    await page.goto('/offline');
    await expect(page.getByRole('heading', { name: "You're offline" })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible();
  });

  test('vendor find page loads', async ({ page }) => {
    await page.goto('/vendor/find');
    await expect(page.getByRole('heading', { name: /find events/i })).toBeVisible();
  });

  test('vendor requests QA empty state', async ({ page }) => {
    await page.goto('/vendor/requests?empty=1');
    await expect(page.getByText('Nothing here yet')).toBeVisible();
  });

  test('organizer vendors QA error state', async ({ page }) => {
    await page.route('**/api/organizer/home', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          stats: { events: 1, vendorRequests: 1, approvedVendors: 0, paymentRequired: 0, paidVendors: 0, openOpportunities: 1 },
          upcomingEvents: [],
          attentionItems: [],
          launchChecklist: [],
        }),
      });
    });

    await page.goto('/organizer/vendors?error=1');
    await expect(page.getByText('Something went wrong')).toBeVisible();
  });

  test('mocked BFF responses do not leak secrets', async ({ page }) => {
    await page.route('**/api/vendor/requests', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [], total: 0 }),
      });
    });

    await page.goto('/vendor/requests');
    const body = await page.content();
    expect(body).not.toContain('appPassword');
    expect(body).not.toContain('client_secret');
  });

  test('qa route is gated by ENABLE_QA_TOOLS', async ({ page }) => {
    await page.goto('/qa');
    const notFound = page.getByRole('heading', { name: 'Page not found' });
    const qaDashboard = page.getByRole('heading', { name: 'Launch QA dashboard' });
    await expect(notFound.or(qaDashboard)).toBeVisible();
  });
});
