import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Vendor Event Pass', () => {
  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('locked pass hides sensitive fields for payment-required application', async ({ page, request }) => {
      await page.goto('/vendor-dashboard/?view=applications');
      const viewPass = page.getByRole('link', { name: /view pass/i }).first();
      if (!(await viewPass.count())) {
        test.skip(true, 'No payment-required applications in test environment');
        return;
      }
      await viewPass.click();
      await expect(page.getByTestId('lnkup-vendor-event-pass')).toBeVisible({ timeout: 15000 });
      await expect(page.getByTestId('lnkup-pass-locked')).toBeVisible();
      await expect(page.locator('[data-testid="lnkup-pass-qr"]')).toHaveCount(0);
      await expect(page.locator('.lnkup-vendor-pass__qr-id code')).toHaveCount(0);

      const appId = await page.locator('.lnkup-vendor-pass').getAttribute('data-app-id');
      if (appId) {
        const cookies = await page.context().cookies();
        const nonceCookie = cookies.find((c) => c.name.includes('wp_rest') || c.name === 'wp_rest_nonce');
        const restRes = await request.get(`/wp-json/lnkup/v1/packets/pass/${appId}`, {
          headers: nonceCookie ? { 'X-WP-Nonce': nonceCookie.value } : {},
        });
        if (restRes.ok()) {
          const body = await restRes.json();
          expect(body.is_unlocked).toBeFalsy();
          expect(body.booth).toBeUndefined();
          expect(body.qr_pass).toBeUndefined();
        }
      }
    });

    test('unlocked pass shows hero when confirmed booking exists', async ({ page }) => {
      await page.goto('/vendor-dashboard/?view=operations');
      const ops = page.locator('#lnkup-vendor-ops');
      if (!(await ops.count())) {
        test.skip(true, 'No pass-viewable vendor bookings');
        return;
      }
      await page.getByRole('tab', { name: /event pass/i }).click();
      const locked = page.getByTestId('lnkup-pass-locked');
      if (await locked.isVisible().catch(() => false)) {
        test.skip(true, 'Only locked pass in environment — need paid booking for unlocked hero');
        return;
      }
      await expect(page.getByTestId('lnkup-pass-hero')).toBeVisible();
    });

    test('mobile pass layout has no horizontal overflow', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/vendor-dashboard/?view=operations&tab=pass');
      const pass = page.getByTestId('lnkup-vendor-event-pass');
      if (!(await pass.count())) {
        test.skip(true, 'No pass view available');
        return;
      }
      await expect(pass).toBeVisible();
      const overflow = await page.evaluate(() => {
        return document.documentElement.scrollWidth > document.documentElement.clientWidth + 2;
      });
      expect(overflow).toBeFalsy();
    });
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('vendor pass builder tab loads in command center', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await expect(page.locator('#lnkup-event-ops')).toBeVisible({ timeout: 15000 });
      await page.getByRole('button', { name: /build vendor pass/i }).click();
      await expect(page.getByTestId('lnkup-ops-vendor-pass-builder')).toBeVisible();
      await expect(page.locator('#lnkup-ops-pass-completeness')).toBeVisible();
      await expect(page.locator('#lnkup-ops-vendor-pass-form')).toBeVisible();
    });

    test('organizer can save arrival pass details', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await expect(page.locator('#lnkup-event-ops')).toBeVisible({ timeout: 15000 });
      await page.getByRole('button', { name: /build vendor pass/i }).click();
      const gateField = page.locator('#lnkup-ops-vendor-pass-form input[name="vendor_gate"]');
      await gateField.fill('Gate E2E ' + Date.now());
      await page.locator('#lnkup-ops-vendor-pass-form button[type="submit"]').click();
      await expect(page.locator('#lnkup-ops-pass-save-status')).toContainText(/saved/i, { timeout: 10000 });
    });
  });
});
