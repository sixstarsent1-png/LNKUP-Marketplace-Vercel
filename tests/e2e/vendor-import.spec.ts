import { test, expect } from '@playwright/test';
import path from 'path';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const sampleCsv = path.join(__dirname, '../fixtures/vendor-import-sample.csv');

async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Organizer vendor CSV import', () => {
  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('import button opens wizard on vendors panel', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      await page.getByTestId('lnkup-import-vendors-btn').click();
      await expect(page.getByTestId('lnkup-vendor-import-wizard')).toBeVisible();
      await expect(page.getByTestId('lnkup-import-step-upload')).toBeVisible();
    });

    test('upload CSV advances through map and review steps', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/organizer-dashboard/#vendors');
      await page.getByTestId('lnkup-import-vendors-btn').click();

      await page.locator('#lnkup-import-file').setInputFiles(sampleCsv);
      await expect(page.locator('#lnkup-import-upload-status')).toContainText(/rows ready/i, { timeout: 15000 });

      await page.getByRole('button', { name: /continue/i }).click();
      await expect(page.getByTestId('lnkup-import-step-map')).toBeVisible();
      await expect(page.locator('#lnkup-import-mapping select[data-map-field="email"]')).toBeVisible();

      await page.getByRole('button', { name: /continue/i }).click();
      await expect(page.getByTestId('lnkup-import-step-review')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('#lnkup-import-summary')).not.toBeEmpty();
    });

    test('import without invites completes and shows results', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      await page.getByTestId('lnkup-import-vendors-btn').click();
      await page.locator('#lnkup-import-file').setInputFiles(sampleCsv);
      await expect(page.locator('#lnkup-import-upload-status')).toContainText(/rows ready/i, { timeout: 15000 });

      await page.getByRole('button', { name: /continue/i }).click();
      await page.getByRole('button', { name: /continue/i }).click();
      await expect(page.getByTestId('lnkup-import-step-review')).toBeVisible({ timeout: 15000 });

      await expect(page.locator('#lnkup-import-send-invites')).not.toBeChecked();
      await page.getByRole('button', { name: /run import/i }).click();
      await expect(page.getByTestId('lnkup-import-step-results')).toBeVisible({ timeout: 30000 });
      await expect(page.locator('#lnkup-import-results')).toContainText(/imported/i);
    });

    test('imported scope tab loads in vendor directory', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      await page.getByRole('button', { name: /^imported$/i }).click();
      await expect(page.locator('#lnkup-crm-directory')).not.toContainText('Loading vendors', { timeout: 15000 });
    });
  });
});
