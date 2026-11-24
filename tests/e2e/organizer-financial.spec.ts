import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';
const FINANCIAL_URL = '/organizer-dashboard/?view=financial';

test.describe('Organizer Financial Center', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
    await loginPage(page, organizerUser, organizerPass);
  });

  test('overview separates every money category with explanations', async ({ page }) => {
    await page.goto(FINANCIAL_URL);
    const grid = page.getByTestId('lnkup-fin-money-categories');
    await expect(grid).toBeVisible({ timeout: 15000 });
    for (const label of ['Gross collected', 'Platform fees', 'Refunds', 'Net earnings', 'Pending', 'Available to cash out', 'In transit', 'Paid out']) {
      await expect(grid.getByText(label, { exact: true })).toBeVisible();
    }
    await expect(grid).toContainText(/not available to cash out yet/i);
    await expect(page.locator('#lnkup-fin-org-overview')).not.toContainText(/could not load/i);
  });

  test('overview never shows payment-provider jargon', async ({ page }) => {
    await page.goto(FINANCIAL_URL);
    await expect(page.getByTestId('lnkup-fin-money-categories')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#lnkup-fin-org-overview')).not.toContainText(/stripe connect active|settlement: destination|syncing with stripe/i);
  });

  test('financial dashboard REST returns JSON with categories', async ({ page }) => {
    await page.goto(FINANCIAL_URL);
    const nonce = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
    const response = await page.request.get('/wp-json/lnkup/v1/financial/dashboard', { headers: { 'X-WP-Nonce': nonce } });
    expect(response.ok()).toBeTruthy();
    expect(response.headers()['content-type']).toMatch(/application\/json/);
    const body = await response.json();
    expect(Array.isArray(body.money_categories)).toBe(true);
    expect(body.money_categories).toHaveLength(8);
  });

  test('tabs switch without errors', async ({ page }) => {
    await page.goto(FINANCIAL_URL);
    const tabs = page.getByTestId('lnkup-money-tabs');
    for (const tab of ['wallet', 'payouts']) {
      const button = page.locator(`.lnkup-fin-tab[data-tab="${tab}"]`);
      if (!(await button.isVisible())) {
        await tabs.getByText('More', { exact: true }).click();
      }
      await button.click();
      const panel = page.locator(`#lnkup-fin-org-${tab}`);
      await expect(panel).toHaveClass(/is-active/);
      await expect(panel).not.toContainText(/could not load/i, { timeout: 15000 });
    }
  });
});
