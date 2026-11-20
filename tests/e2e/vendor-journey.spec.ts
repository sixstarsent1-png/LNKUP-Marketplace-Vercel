import { test, expect, type Page } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const multiSlotOpportunityId = process.env.PLAYWRIGHT_MULTI_SLOT_OPPORTUNITY_ID;

/**
 * Log into WordPress as the test vendor.
 */
async function loginVendor(page: Page) {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(vendorUser!);
    await page.getByLabel(/^password$/i).fill(vendorPass!);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForLoadState('domcontentloaded');
    if (!/wp-login/.test(page.url())) break;
  }
  await expect(page).not.toHaveURL(/wp-login/);
}

/**
 * Open Find Events and skip when the prelaunch gate is active.
 */
async function openFind(page: Page, query = '') {
  await page.goto(`/opportunities/${query}`);
  if (await page.getByTestId('lnkup-prelaunch-marketplace').isVisible().catch(() => false)) {
    test.skip(true, 'Marketplace prelaunch gate active');
  }
  await expect(page.locator('#lnkup-marketplace')).toBeVisible({ timeout: 15000 });
}

test.describe('Vendor journey — logged out', () => {
  test('dashboard deep links survive the login redirect', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=payments&fin_tab=receipts');
    await expect(page).toHaveURL(/log-?in/);
    const url = decodeURIComponent(page.url());
    expect(url).toContain('view=payments');
    expect(url).toContain('fin_tab=receipts');
  });

  test('apply from a card keeps the opportunity in the login return URL', async ({ page }) => {
    await openFind(page);
    const card = page.locator('#lnkup-results-grid .lnkup-event-card--v2').first();
    await expect(card).toBeVisible({ timeout: 15000 });
    const apply = card.getByRole('link', { name: /^apply$/i });
    test.skip((await apply.count()) === 0, 'First card has no open slot');
    await apply.click();
    const login = page.getByTestId('lnkup-apply-modal').getByRole('link', { name: /log in/i });
    await expect(login).toBeVisible({ timeout: 10000 });
    expect(decodeURIComponent((await login.getAttribute('href')) || '')).toMatch(/apply=\d+/);
  });

  test('document filters are restored from the URL after reload', async ({ page }) => {
    await openFind(page, '?doc_required=coi');
    const coi = page.locator('[data-filter-doc="coi"]');
    test.skip((await coi.count()) === 0, 'Document filters not rendered in this phase');
    await expect(coi).toBeChecked();
    await page.reload();
    await expect(page.locator('[data-filter-doc="coi"]')).toBeChecked();
  });

  test('typing a search does not add a history entry per keystroke', async ({ page }) => {
    await openFind(page);
    const search = page.locator('#lnkup-search-input');
    const before = await page.evaluate(() => window.history.length);
    await search.pressSequentially('market', { delay: 120 });
    await expect(page).toHaveURL(/q=market/, { timeout: 10000 });
    const after = await page.evaluate(() => window.history.length);
    expect(after - before).toBeLessThanOrEqual(1);
  });

  test('event card images lazy-load without inline error handlers', async ({ page }) => {
    await openFind(page);
    const img = page.locator('#lnkup-results-grid .lnkup-event-card__media img').first();
    test.skip((await img.count()) === 0, 'No card images rendered');
    await expect(img).toHaveAttribute('loading', 'lazy');
    await expect(img).toHaveAttribute('decoding', 'async');
    await expect(img).not.toHaveAttribute('onerror', /.+/);
  });

  test('a failing search clears skeletons and shows the error state', async ({ page }) => {
    await page.route('**/wp-json/lnkup/v1/marketplace/search**', (route) => route.fulfill({ status: 500, body: '<html>fatal</html>', contentType: 'text/html' }));
    await page.route('**/admin-ajax.php', (route) => route.fulfill({ status: 500, body: '<html>fatal</html>', contentType: 'text/html' }));
    await openFind(page, '?q=forced-error');
    await expect(page.locator('#lnkup-error-state')).toBeVisible({ timeout: 15000 });
    await expect(page.locator('#lnkup-results-grid .lnkup-skeleton')).toHaveCount(0);
    await expect(page.locator('#lnkup-result-count')).not.toHaveText('Searching…');
  });
});

test.describe('Vendor journey — signed in', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
    await loginVendor(page);
  });

  test('request tabs follow the app_filter query and survive reload', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=applications&app_filter=pay-now');
    const tabs = page.getByRole('navigation', { name: /filter requests/i });
    test.skip((await tabs.count()) === 0, 'Request tabs not rendered');
    await expect(tabs.getByRole('button', { name: /needs action/i })).toHaveClass(/is-active/);
    await tabs.getByRole('button', { name: /past events/i }).click();
    await expect(page).toHaveURL(/app_filter=completed/);
    await page.reload();
    await expect(page.getByRole('navigation', { name: /filter requests/i }).getByRole('button', { name: /past events/i })).toHaveClass(/is-active/);
  });

  test('request timelines never mark future steps as done', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=applications');
    const lists = page.getByRole('list', { name: /request progress/i });
    test.skip((await lists.count()) === 0, 'Vendor has no open requests');
    const first = lists.first();
    const states = await first.locator('li').evaluateAll((items) =>
      items.map((li) => (li.className.match(/is-(done|current|pending)/) || [])[1] || ''),
    );
    const currentIndex = states.indexOf('current');
    if (currentIndex >= 0) {
      expect(states.slice(currentIndex + 1).every((s) => s === 'pending')).toBeTruthy();
    }
  });

  test('home shows a single next step and linked metrics', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page.getByTestId('lnkup-vendor-home-welcome')).toBeVisible();
    if (await page.getByText('No applications yet').isVisible()) {
      return;
    }
    await expect(page.getByTestId('lnkup-action-center')).toHaveCount(1);
    await expect(page.getByTestId('lnkup-vendor-home-stats').getByRole('link')).toHaveCount(4);
  });

  test('wallet balance header never stays on a skeleton when the API fails', async ({ page }) => {
    await page.route('**/wp-json/lnkup/v1/financial/**', (route) => route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"boom","message":"x"}' }));
    await page.goto('/vendor-dashboard/?view=payments');
    const hero = page.locator('#lnkup-fin-balance-hero');
    test.skip((await hero.count()) === 0, 'Balance hero not rendered');
    await expect(hero.locator('.lnkup-fin-balance-hero__unavailable')).toBeVisible({ timeout: 15000 });
  });

  test('events with several open slots let the vendor choose a booth', async ({ page }) => {
    test.skip(!multiSlotOpportunityId, 'Set PLAYWRIGHT_MULTI_SLOT_OPPORTUNITY_ID');
    await openFind(page, `?apply=${multiSlotOpportunityId}`);
    const booths = page.getByTestId('lnkup-apply-booths');
    await expect(booths).toBeVisible({ timeout: 10000 });
    const options = booths.getByRole('button');
    expect(await options.count()).toBeGreaterThan(1);
    await expect(booths.getByRole('button', { pressed: true })).toHaveCount(1);
    await booths.getByRole('button', { pressed: false }).first().click();
    await expect(page).toHaveURL(/apply=\d+/);
    await expect(page.getByTestId('lnkup-apply-booths').getByRole('button', { pressed: true })).toHaveCount(1);
  });
});
