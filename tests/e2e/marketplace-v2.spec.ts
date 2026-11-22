import { test, expect } from '@playwright/test';

test.describe('Marketplace V2', () => {
  test('opportunities page loads search and results grid', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    if (await prelaunch.isVisible().catch(() => false)) {
      await expect(prelaunch).toBeVisible();
      await expect(page.getByRole('heading', { name: /find events opens on launch day/i })).toBeVisible();
      return;
    }
    await expect(page.getByRole('heading', { name: /^explore$/i })).toBeVisible();
    await expect(page.locator('#lnkup-search-input')).toBeVisible();
    await expect(page.locator('#lnkup-results-grid')).toBeVisible();
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i);
  });

  test('SSR renders opportunity cards without JavaScript', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-results-grid .lnkup-event-card--v2').first()).toBeVisible({ timeout: 15000 });
    await context.close();
  });

  test('search input debounces and updates results', async ({ page }) => {
    await page.goto('/opportunities/');
    const search = page.locator('#lnkup-search-input');
    await search.fill('Charlotte');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
  });

  test('explore layout keeps quick chips and the result count out of the results header', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-results-grid')).toBeVisible();
    await expect(page.locator('.lnkup-mp-v2__quick-chips')).toBeHidden();
    await expect(page.locator('.lnkup-mp-v2__count')).toBeHidden();
  });

  test('filter drawer opens on mobile', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/opportunities/');
    await page.locator('#lnkup-filter-fab').click();
    await expect(page.locator('#lnkup-filter-drawer')).toBeVisible();
    await expect(page.getByRole('button', { name: /apply filters/i })).toBeVisible();
  });

  test('clear all filters from empty state', async ({ page }) => {
    await page.goto('/opportunities/?q=zzznomatchxyz123');
    await expect(page.locator('#lnkup-empty-state')).toBeVisible({ timeout: 10000 });
    await page.locator('#lnkup-empty-clear').click();
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
  });

  test('phase 1A hides map toggle, compare tray, and intelligence rails', async ({ page }) => {
    await page.goto('/opportunities/');
    const phase = await page.locator('#lnkup-marketplace').getAttribute('data-phase');
    test.skip(phase !== '1a', `Site is on marketplace phase ${phase}; seed.php sets 1c`);
    await expect(page.locator('#lnkup-map-toggle')).toHaveCount(0);
    await expect(page.locator('#lnkup-compare-tray')).toHaveCount(0);
    await expect(page.locator('#lnkup-explore-more')).toHaveCount(0);
    await expect(page.locator('.lnkup-compare-add')).toHaveCount(0);
  });

  test('saved search restore applies filters from query string', async ({ page }) => {
    const filters = btoa(JSON.stringify({ q: 'Charlotte', city: 'Charlotte', sort: 'newest' }));
    await page.goto('/opportunities/?lnkup_restore=' + encodeURIComponent(filters));
    await expect(page.locator('#lnkup-search-input')).toHaveValue('Charlotte');
    await expect(page.locator('[data-filter="city"]')).toHaveValue('Charlotte');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
  });

  test('search button is visible and submits on Enter', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.getByRole('button', { name: /^search$/i })).toBeVisible();
    const search = page.locator('#lnkup-search-input');
    await search.fill('Charlotte');
    await search.press('Enter');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
    await expect(page.locator('#lnkup-active-filters')).toContainText(/search:/i);
  });

  test('apply opens modal dialog instead of inline panel', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-results-grid .lnkup-event-card--v2').first()).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#lnkup-apply-panel')).toHaveCount(0);
    const applyBtn = page.locator('#lnkup-results-grid .lnkup-event-card--v2').first().getByRole('link', { name: /^apply$/i });
    await applyBtn.click();
    const modal = page.locator('#lnkup-apply-modal');
    await expect(modal).toBeVisible();
    await expect(modal).toHaveAttribute('role', 'dialog');
    await expect(page.locator('#lnkup-apply-modal-body')).not.toBeEmpty({ timeout: 10000 });
    await expect(page.getByRole('heading', { name: /apply with your lnkup profile/i })).toBeVisible();
  });

  test('page loads without JavaScript parse errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', function (err) {
      errors.push(err.message);
    });
    await page.goto('/opportunities/');
    await expect(page.locator('#lnkup-marketplace')).toBeVisible();
    expect(errors.filter(function (m) { return /unexpected token/i.test(m); })).toHaveLength(0);
  });

  test('explore more card sits below results with tabbed layout on phase 1b', async ({ page }) => {
    await page.goto('/opportunities/');
    const phase = await page.locator('#lnkup-marketplace').getAttribute('data-phase');
    if (phase !== '1b' && phase !== '1c') {
      test.skip(true, 'Explore more requires marketplace phase 1b+');
      return;
    }
    await expect(page.locator('#lnkup-results-grid')).toBeVisible();
    await page.waitForFunction(() => {
      const loading = document.getElementById('lnkup-explore-loading');
      return !loading || loading.hidden;
    }, { timeout: 15000 });

    const explore = page.locator('#lnkup-explore-more');
    const resultsBox = await page.locator('#lnkup-results-grid').boundingBox();
    const isExploreVisible = await explore.isVisible();

    if (isExploreVisible) {
      await expect(explore.getByText(/explore more opportunities/i)).toBeVisible();
      await expect(explore.locator('h2')).toHaveCount(0);
      if ((await explore.getAttribute('open')) === null) {
        await explore.getByText(/explore more opportunities/i).click();
      }
      await expect(page.locator('#lnkup-explore-tablist')).toBeVisible();
      const exploreBox = await explore.boundingBox();
      if (resultsBox && exploreBox) {
        expect(exploreBox.y).toBeGreaterThan(resultsBox.y);
      }
    }
  });

  test('explore more shows collection chips when collections tab is present', async ({ page }) => {
    await page.goto('/opportunities/');
    const phase = await page.locator('#lnkup-marketplace').getAttribute('data-phase');
    if (phase !== '1b' && phase !== '1c') {
      test.skip(true, 'Explore more requires marketplace phase 1b+');
      return;
    }
    await page.waitForFunction(() => {
      const loading = document.getElementById('lnkup-explore-loading');
      return !loading || loading.hidden;
    }, { timeout: 15000 });

    const collectionsTab = page.getByRole('tab', { name: /^collections$/i });
    if (await collectionsTab.count() === 0) {
      test.skip(true, 'No collections tab in this environment');
      return;
    }
    await collectionsTab.click();
    await expect(page.locator('.lnkup-collection-chip').first()).toBeVisible();
  });

  test('active search hides explore more rails', async ({ page }) => {
    await page.goto('/opportunities/');
    const phase = await page.locator('#lnkup-marketplace').getAttribute('data-phase');
    if (phase !== '1b' && phase !== '1c') {
      test.skip(true, 'Explore more requires marketplace phase 1b+');
      return;
    }
    await page.locator('#lnkup-search-input').fill('Festival');
    await page.locator('#lnkup-search-submit').click();
    await expect(page.locator('#lnkup-explore-more')).toBeHidden({ timeout: 10000 });
  });
});
