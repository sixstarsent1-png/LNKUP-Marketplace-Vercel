import { test, expect } from '@playwright/test';

test.describe('Interactive Maps', () => {
  test('opportunities page has grid map and split view toggles when phase 1c', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.getByRole('heading', { name: /find events/i })).toBeVisible();
    const mapToggle = page.locator('#lnkup-map-toggle');
    const splitToggle = page.locator('#lnkup-split-toggle');
    if (!(await mapToggle.count()) || !(await splitToggle.count())) {
      test.skip(true, 'Map/split view requires marketplace phase 1c');
    }
    await expect(mapToggle).toBeVisible();
    await expect(splitToggle).toBeVisible();
    await mapToggle.click();
    await expect(page.locator('#lnkup-map-view')).toBeVisible();
  });

  test('marketplace regression still loads results grid', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-results-grid')).toBeVisible({ timeout: 15000 });
  });
});

test.describe('Interactive Maps authenticated', () => {
  const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
  const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(organizerUser!);
    await page.getByLabel(/^password$/i).fill(organizerPass!);
    await page.getByRole('button', { name: /log in/i }).click();
    await expect(page).not.toHaveURL(/wp-login/);
  });

  test('operations command center has map builder and live map tabs', async ({ page }) => {
    await page.goto('/organizer-dashboard/?event=1&ops=1');
    if (!(await page.locator('#lnkup-event-ops').count())) {
      test.skip(true, 'No ops view for event 1');
    }
    await expect(page.getByRole('button', { name: /map builder/i })).toBeVisible();
    await expect(page.getByRole('button', { name: /live map/i })).toBeVisible();
    await page.getByRole('button', { name: /map builder/i }).click();
    await expect(page.locator('#lnkup-map-shell')).toBeVisible();
  });
});
