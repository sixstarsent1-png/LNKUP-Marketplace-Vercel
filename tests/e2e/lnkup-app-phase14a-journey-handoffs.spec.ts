import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

test.describe('Phase 14A — Journey handoffs', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${appBase}/?role=vendor`);
  });

  test('shows payment success banner on vendor requests return', async ({ page }) => {
    await page.goto(`${appBase}/vendor/requests?applicationId=req-1&payment=success`);
    await expect(page.getByTestId('payment-return-success')).toBeVisible();
    await expect(page.getByText(/booked for/i)).toBeVisible();
    await expect(page.getByRole('button', { name: 'View pass' })).toBeVisible();
  });

  test('shows payment cancelled banner with retry CTA', async ({ page }) => {
    await page.goto(`${appBase}/vendor/requests?applicationId=req-2&payment=cancelled`);
    await expect(page.getByTestId('payment-return-cancelled')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try payment again' })).toBeVisible();
  });

  test('highlights deep-linked opportunity on find', async ({ page }) => {
    await page.goto(`${appBase}/vendor/find?opportunityId=charlotte-night-market`);
    await expect(page.getByTestId('opportunity-card-highlighted')).toBeVisible();
  });

  test('mock checkout handoff returns to app success URL', async ({ page }) => {
    await page.goto(`${appBase}/vendor/requests?pay=req-2`);
    await expect(page.getByTestId('vendor-payment-detail-drawer')).toBeVisible();
    await page.getByRole('button', { name: /continue payment/i }).click();
    await page.waitForURL(/payment=success/);
    await expect(page.getByTestId('payment-return-success')).toBeVisible({ timeout: 15000 });
  });

  test('wallet shows payment success banner from return URL', async ({ page }) => {
    await page.goto(`${appBase}/vendor/wallet?applicationId=req-1&payment=success`);
    await expect(page.getByTestId('payment-return-success')).toBeVisible();
    await expect(page.getByTestId('vendor-wallet')).toBeVisible();
  });
});
