import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

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

test.describe('Organizer financial dashboard', () => {
  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('command center shows executive hero and KPI strip', async ({ page }) => {
      await page.goto('/organizer-dashboard/#overview');
      await expect(page.getByTestId('lnkup-crm-stats')).toBeVisible();
      await expect(page.locator('.lnkup-executive-hero').first()).toBeVisible();
      await expect(page.getByTestId('lnkup-organizer-actions')).toBeVisible();
    });

    test('sidebar shows Financial only and legacy payments hash redirects', async ({ page }) => {
      await page.goto('/organizer-dashboard/#payments');
      await expect(page).toHaveURL(/#financial$/);
      await expect(page.getByRole('link', { name: /^financial$/i })).toBeVisible();
      await expect(page.getByRole('link', { name: /^payments$/i })).toHaveCount(0);
    });

    test('financial panel has single treasury shell without duplicate payments panel', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await expect(page.locator('#financial')).toBeVisible();
      await expect(page.locator('#payments')).toHaveCount(0);
      await expect(page.locator('#lnkup-organizer-financial')).toHaveCount(1);
      await expect(page.getByRole('button', { name: /^wallet$/i })).toBeVisible();
      await expect(page.getByRole('button', { name: /^cash out$/i })).toBeVisible();
      await expect(page.locator('#lnkup-fin-org-overview')).not.toContainText('Could not load', { timeout: 15000 });
    });

    test('overview shows KPI cards and zero-state copy when empty', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      const overview = page.locator('#lnkup-fin-org-overview');
      await expect(overview).toContainText(/gross revenue/i, { timeout: 15000 });
      await expect(overview).toContainText(/available balance/i);
      await expect(overview).toContainText(/platform fees/i);
      await expect(overview).not.toContainText('Could not load financial overview');
    });

    test('financial REST dashboard and forecast return JSON', async ({ page, request }) => {
      await page.goto('/organizer-dashboard/#financial');
      const nonce = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
      const headers = { 'X-WP-Nonce': nonce };
      const dashboard = await request.get('/wp-json/lnkup/v1/financial/dashboard', { headers });
      expect(dashboard.ok()).toBeTruthy();
      expect(dashboard.headers()['content-type']).toMatch(/application\/json/);
      const dashBody = await dashboard.json();
      expect(dashBody).toHaveProperty('gross_revenue');
      expect(dashBody).toHaveProperty('wallet');

      const forecast = await request.get('/wp-json/lnkup/v1/financial/forecast?days=30', { headers });
      expect(forecast.ok()).toBeTruthy();
      expect(forecast.headers()['content-type']).toMatch(/application\/json/);
      const forecastBody = await forecast.json();
      expect(forecastBody).toHaveProperty('next_7_days');
      expect(forecastBody).toHaveProperty('confidence');
      expect(forecastBody).toHaveProperty('message');
    });

    test('forecast tab shows KPI cards without error', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await page.getByRole('button', { name: /^forecast$/i }).click();
      const panel = page.locator('#lnkup-fin-org-forecast');
      await expect(panel).toHaveClass(/is-active/);
      await expect(panel).toContainText(/next 7 days/i, { timeout: 15000 });
      await expect(panel).not.toContainText('Could not load financial data');
    });

    test('active tab uses pill styling', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      const overviewTab = page.locator('.lnkup-fin-tab[data-tab="overview"]');
      await expect(overviewTab).toHaveClass(/is-active/);
      await page.getByRole('button', { name: /^forecast$/i }).click();
      await expect(page.locator('.lnkup-fin-tab[data-tab="forecast"]')).toHaveClass(/is-active/);
      await expect(overviewTab).not.toHaveClass(/is-active/);
    });

    test('settings tab shows connect summary', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await page.getByRole('button', { name: /^settings$/i }).click();
      const panel = page.locator('#lnkup-fin-org-settings');
      await expect(panel).toHaveClass(/is-active/);
      await expect(panel).toContainText(/stripe connect/i, { timeout: 15000 });
    });

    test('wallet tab loads organizer treasury balances', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await page.getByRole('button', { name: /^wallet$/i }).click();
      await expect(page.locator('#lnkup-fin-org-wallet.is-active')).toBeVisible();
      await expect(page.locator('#lnkup-fin-org-wallet')).toContainText(/available/i, { timeout: 15000 });
      await expect(page.locator('#lnkup-fin-org-wallet')).toContainText(/payout setup/i);
    });

    test('reports tab shows revenue chart area', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await page.getByRole('button', { name: /^reports$/i }).click();
      await expect(page.locator('#lnkup-fin-org-reports.is-active')).toBeVisible();
      await expect(page.locator('#lnkup-fin-org-reports')).toContainText(/revenue|lifetime/i, { timeout: 15000 });
    });
  });
});
