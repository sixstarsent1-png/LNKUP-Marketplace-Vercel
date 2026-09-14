import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

test.describe('Phase 14C — Wallet polish', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${appBase}/?role=vendor`);
  });

  test('vendor wallet activity rows are clickable and open payment drawer', async ({ page }) => {
    await page.goto(`${appBase}/vendor/wallet`);
    const clickableActivity = page.getByTestId('payment-activity-item-clickable').first();
    await expect(clickableActivity).toBeVisible();
    await clickableActivity.click();
    await expect(page.getByTestId('vendor-payment-detail-drawer')).toBeVisible();
  });

  test('organizer money activity rows are clickable and route to event page', async ({ page }) => {
    await page.goto(`${appBase}/organizer/money`);
    await page.getByRole('tab', { name: 'Activity' }).click();
    const clickableActivity = page.getByTestId('payment-activity-item-clickable').first();
    await expect(clickableActivity).toBeVisible();
    await clickableActivity.click();
    await page.waitForURL('**/organizer/events/**');
    await expect(page.getByTestId('organizer-event-workspace')).toBeVisible();
  });

  test('organizer vendor request cards show payment status context copy', async ({ page }) => {
    await page.goto(`${appBase}/organizer/vendors`);
    await page.getByRole('tab', { name: /Approved/i }).click();
    const card = page.getByTestId('organizer-vendor-request-card').first();
    await expect(card).toBeVisible();
    await expect(card).toContainText(/Payment required|Paid/i);
  });

  test('wallet invoice and receipt rows remain accessible', async ({ page }) => {
    await page.goto(`${appBase}/vendor/wallet`);
    await page.getByRole('tab', { name: 'Invoices & receipts' }).click();
    const invoiceOrReceiptCard = page.locator('[data-testid^="invoice-card-"], [data-testid^="receipt-card-"]').first();
    await expect(invoiceOrReceiptCard).toBeVisible();
    await expect(invoiceOrReceiptCard.getByRole('button', { name: /View|Unavailable/i })).toBeVisible();
  });
});
