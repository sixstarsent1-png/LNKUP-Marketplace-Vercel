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

test.describe('Vendor profile strength', () => {
  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('dashboard shows profile strength card or empty state', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      const card = page.getByTestId('lnkup-vendor-strength-card');
      await expect(card).toBeVisible();
      await expect(
        card.getByText(/build your vendor profile|your vendor profile|booking ready/i)
      ).toBeVisible();
    });

    test('profile editor shows sectioned resume layout', async ({ page }) => {
      await page.goto('/vendor-profile/');
      await expect(page.getByTestId('lnkup-vendor-profile-editor')).toBeVisible();
      await expect(page.locator('[data-profile-section="business_basics"]')).toBeVisible();
      await expect(page.locator('[data-profile-section="documents"]')).toBeVisible();
    });

    test('business basics save updates profile strength preview', async ({ page }) => {
      await page.goto('/vendor-profile/');
      const section = page.locator('[data-profile-section="business_basics"]');
      await section.getByLabel(/business name/i).fill(`E2E Vendor ${Date.now()}`);
      await section.getByLabel(/about your business/i).fill(
        'We are a test vendor serving live events with great food, drinks, and an amazing booth experience for guests.'
      );
      await section.getByRole('button', { name: /save section/i }).click();
      await expect(section.getByText(/section saved/i)).toBeVisible({ timeout: 15000 });
      await expect(page.locator('#lnkup-preview-strength')).toContainText(/%/);
    });

    test('documents section shows status labels', async ({ page }) => {
      await page.goto('/vendor-profile/');
      const docs = page.locator('[data-profile-section="documents"]');
      await expect(docs.getByText(/missing|verified|pending/i).first()).toBeVisible();
    });

    test('mobile profile editor does not overflow horizontally', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/vendor-profile/');
      const editor = page.getByTestId('lnkup-vendor-profile-editor');
      await expect(editor).toBeVisible();
      const box = await editor.boundingBox();
      expect(box && box.width <= 390).toBeTruthy();
    });
  });

  test.describe('organizer application scan', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('application cards may show profile strength badge', async ({ page }) => {
      await page.goto('/organizer-dashboard/#applications');
      const cards = page.locator('.lnkup-crm-app-card');
      if (!(await cards.count())) {
        test.skip(true, 'No applications for organizer');
      }
      const strength = page.locator('.lnkup-crm-app-card__strength').first();
      if (await strength.count()) {
        await expect(strength).toContainText(/%|started|good|strong/i);
      }
    });
  });
});
