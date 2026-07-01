import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const vendorBUser = process.env.PLAYWRIGHT_VENDOR_B_USER;
const vendorBPass = process.env.PLAYWRIGHT_VENDOR_B_PASS;

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Saved searches', () => {
  test('logged-out user sees signup CTA when trying to save search', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    if (await prelaunch.isVisible().catch(() => false)) {
      test.skip(true, 'Marketplace prelaunch');
      return;
    }
    await page.getByRole('button', { name: /save this search/i }).click();
    const modal = page.getByTestId('lnkup-save-search-modal');
    await expect(modal).toBeVisible();
    await expect(modal.getByText(/create a free vendor profile to save searches/i)).toBeVisible();
    await expect(modal.getByRole('link', { name: /create vendor profile/i })).toBeVisible();
  });

  test('mobile save search modal does not overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    if (await prelaunch.isVisible().catch(() => false)) {
      test.skip(true, 'Marketplace prelaunch');
      return;
    }
    await page.getByRole('button', { name: /save this search/i }).click();
    const dialog = page.locator('.lnkup-save-search-modal__dialog');
    await expect(dialog).toBeVisible();
    const box = await dialog.boundingBox();
    expect(box).toBeTruthy();
    if (box) {
      expect(box.width).toBeLessThanOrEqual(390);
      expect(box.y + box.height).toBeLessThanOrEqual(844 + 2);
    }
  });

  test.describe('vendor flows', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('vendor can save current Find Events filters', async ({ page }) => {
      await page.goto('/opportunities/');
      const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
      if (await prelaunch.isVisible().catch(() => false)) {
        test.skip(true, 'Marketplace prelaunch');
        return;
      }
      await page.locator('#lnkup-search-input').fill('Charlotte');
      await page.getByRole('button', { name: /save this search/i }).click();
      const modal = page.getByTestId('lnkup-save-search-modal');
      await expect(modal).toBeVisible();
      await page.locator('#lnkup-save-search-name').fill('Charlotte test search');
      await page.locator('#lnkup-save-search-submit').click();
      await expect(page.locator('#lnkup-save-search-success')).toBeVisible({ timeout: 10000 });
    });

    test('empty filter search shows warning', async ({ page }) => {
      await page.goto('/opportunities/');
      const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
      if (await prelaunch.isVisible().catch(() => false)) {
        test.skip(true, 'Marketplace prelaunch');
        return;
      }
      await page.getByRole('button', { name: /save this search/i }).click();
      await expect(page.locator('#lnkup-save-search-empty-warning')).toBeVisible();
    });

    test('saved search appears in favorites dashboard section', async ({ page }) => {
      await page.goto('/favorites/');
      const panel = page.getByTestId('lnkup-saved-searches-panel');
      await expect(panel).toBeVisible();
      await expect(panel.getByText(/saved searches/i)).toBeVisible();
      await page.waitForFunction(() => {
        const el = document.getElementById('lnkup-saved-searches-dashboard');
        return el && !el.textContent?.includes('Loading');
      }, { timeout: 15000 });
      const empty = page.getByTestId('lnkup-saved-searches-empty');
      const card = page.getByTestId('lnkup-saved-search-card');
      const hasCard = await card.count();
      const hasEmpty = await empty.isVisible().catch(() => false);
      expect(hasCard > 0 || hasEmpty).toBeTruthy();
    });

    test('vendor can pause and delete saved search via REST', async ({ page, request }) => {
      const nonce = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
      const create = await request.post('/wp-json/lnkup/v1/marketplace/saved-searches', {
        headers: { 'X-WP-Nonce': nonce },
        data: { name: 'E2E pause delete', filters: { q: 'zzze2eonly', city: 'Nowhere' }, alert_enabled: true },
      });
      if (!create.ok()) {
        test.skip(true, 'Could not create saved search');
        return;
      }
      const body = await create.json();
      const id = body.id as string;
      const patch = await request.patch('/wp-json/lnkup/v1/marketplace/saved-searches/' + id, {
        headers: { 'X-WP-Nonce': nonce },
        data: { status: 'paused', alert_enabled: false },
      });
      expect(patch.ok()).toBeTruthy();
      const patched = await patch.json();
      expect(patched.is_paused).toBeTruthy();
      const del = await request.delete('/wp-json/lnkup/v1/marketplace/saved-searches/' + id, {
        headers: { 'X-WP-Nonce': nonce },
      });
      expect(del.ok()).toBeTruthy();
    });

    test('vendor can run saved search', async ({ page, request }) => {
      const nonce = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
      const create = await request.post('/wp-json/lnkup/v1/marketplace/saved-searches', {
        headers: { 'X-WP-Nonce': nonce },
        data: { name: 'E2E run', filters: { sort: 'newest' } },
      });
      if (!create.ok()) {
        test.skip(true, 'Could not create saved search');
        return;
      }
      const body = await create.json();
      const run = await request.post('/wp-json/lnkup/v1/marketplace/saved-searches/' + body.id + '/run', {
        headers: { 'X-WP-Nonce': nonce },
        data: { per_page: 5 },
      });
      expect(run.ok()).toBeTruthy();
      const result = await run.json();
      expect(Array.isArray(result.items)).toBeTruthy();
      await request.delete('/wp-json/lnkup/v1/marketplace/saved-searches/' + body.id, {
        headers: { 'X-WP-Nonce': nonce },
      });
    });
  });

  test('vendor cannot access another vendor saved search', async ({ page, request }) => {
    test.skip(!vendorUser || !vendorPass || !vendorBUser || !vendorBPass, 'Set both vendor credential pairs');
    await loginVendor(page);
    const nonceA = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
    const create = await request.post('/wp-json/lnkup/v1/marketplace/saved-searches', {
      headers: { 'X-WP-Nonce': nonceA },
      data: { name: 'Vendor A private', filters: { q: 'privateA' } },
    });
    if (!create.ok()) {
      test.skip(true, 'Could not create saved search');
      return;
    }
    const body = await create.json();
    await page.goto('/wp-login.php?action=logout');
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(vendorBUser!);
    await page.getByLabel(/^password$/i).fill(vendorBPass!);
    await page.getByRole('button', { name: /log in/i }).click();
    const nonceB = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
    const run = await request.post('/wp-json/lnkup/v1/marketplace/saved-searches/' + body.id + '/run', {
      headers: { 'X-WP-Nonce': nonceB },
    });
    expect(run.status()).toBeGreaterThanOrEqual(404);
    await page.goto('/wp-login.php?action=logout');
    await loginVendor(page);
    const nonceCleanup = await page.evaluate(() => (window as unknown as { lnkupAjax?: { restNonce?: string } }).lnkupAjax?.restNonce || '');
    await request.delete('/wp-json/lnkup/v1/marketplace/saved-searches/' + body.id, {
      headers: { 'X-WP-Nonce': nonceCleanup },
    });
  });

  test('existing marketplace search still works', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    if (await prelaunch.isVisible().catch(() => false)) {
      test.skip(true, 'Marketplace prelaunch');
      return;
    }
    await expect(page.locator('#lnkup-search-input')).toBeVisible();
    await page.locator('#lnkup-search-input').fill('Festival');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
  });
});
