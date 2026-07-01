import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Embedded checkout', () => {
  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('pay now opens checkout modal with summary step', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=payments');
      const payBtn = page.locator('.lnkup-pay-btn').first();
      const hasPay = await payBtn.isVisible().catch(() => false);
      test.skip(!hasPay, 'No pending payments to test checkout');
      await payBtn.click();
      await expect(page.locator('#lnkup-checkout-modal.is-open')).toBeVisible();
      await expect(page.getByText(/summary/i)).toBeVisible();
      await expect(page.getByText(/booth reservation/i)).toBeVisible();
      await expect(page.getByText(/marketplace fee/i)).toHaveCount(0);
      await expect(page.getByRole('button', { name: /continue to payment/i })).toBeVisible();
    });

    test('checkout summary API returns bundled booth total', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=payments');
      const payBtn = page.locator('.lnkup-pay-btn').first();
      const hasPay = await payBtn.isVisible().catch(() => false);
      test.skip(!hasPay, 'No pending payments to test checkout');
      const paymentId = await payBtn.getAttribute('data-payment-id');
      test.skip(!paymentId, 'Pay button missing payment id');
      const response = await page.request.get(`/wp-json/lnkup/v1/financial/checkout/${paymentId}/summary`, {
        headers: { Accept: 'application/json' },
      });
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      const breakdown = body.breakdown || {};
      expect(breakdown).toHaveProperty('booth_reservation_total');
      expect(breakdown.booth_reservation_total).toBeCloseTo(
        Number(breakdown.vendor_fee || 0) + Number(breakdown.vendor_checkout_fee || 0),
        2
      );
      expect(breakdown.platform_fee).toBeUndefined();
    });
  });
});
