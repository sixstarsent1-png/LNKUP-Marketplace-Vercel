import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

test.describe('LNKup app — Phase 5 mobile', () => {
  test.use({ baseURL: appBase });

  test('vendor bottom nav on mobile', async ({ page }) => {
    await page.goto('/vendor');
    await expect(page.getByRole('link', { name: 'Find' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Requests' })).toBeVisible();
  });

  test('organizer bottom nav on mobile', async ({ page }) => {
    await page.goto('/organizer');
    await expect(page.getByRole('link', { name: 'Vendors' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Money' })).toBeVisible();
  });

  test('login form usable on mobile', async ({ page }) => {
    await page.goto('/login');
    await expect(page.getByRole('button', { name: 'Sign in' })).toBeVisible();
  });
});
