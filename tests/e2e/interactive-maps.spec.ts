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

  test('split view places map beside the list at desktop', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto('/opportunities/');
    if (await page.getByTestId('lnkup-prelaunch-marketplace').isVisible().catch(() => false)) {
      test.skip(true, 'Marketplace is closed');
    }
    const splitToggle = page.getByRole('button', { name: /^split$/i });
    if (!(await splitToggle.count())) {
      test.skip(true, 'Split view requires marketplace phase 1b+');
    }

    const search = page.locator('#lnkup-search-input');
    await search.fill('Raleigh');
    await search.press('Enter');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 15000 });

    await splitToggle.click();
    const split = page.locator('#lnkup-split-layout');
    await expect(split).toHaveClass(/is-split/);
    await expect(split.locator('#lnkup-external-legal')).toHaveCount(0);

    const list = page.locator('#lnkup-grid-view');
    const map = page.locator('#lnkup-map-view');
    await expect(list).toBeVisible();
    await expect(map).toBeVisible();

    const listBox = await list.boundingBox();
    const mapBox = await map.boundingBox();
    expect(listBox).toBeTruthy();
    expect(mapBox).toBeTruthy();
    expect(Math.abs((mapBox?.y ?? 0) - (listBox?.y ?? 0))).toBeLessThan(160);
    expect(mapBox?.x ?? 0).toBeGreaterThan(listBox?.x ?? 0);
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
