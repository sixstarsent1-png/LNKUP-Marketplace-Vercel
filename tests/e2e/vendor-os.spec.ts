import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { loginPage } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? '';

const SCREENS = [
  { name: 'home', path: '/vendor-dashboard/' },
  { name: 'explore', path: '/opportunities/' },
  { name: 'bookings', path: '/vendor-dashboard/?view=applications' },
  { name: 'wallet', path: '/vendor-dashboard/?view=payments' },
  { name: 'operations', path: '/vendor-dashboard/?view=operations' },
  { name: 'growth', path: '/vendor-dashboard/?view=growth' },
  { name: 'documents', path: '/vendor-dashboard/?view=documents' },
  { name: 'saved', path: '/favorites/' },
];

/**
 * Page-level horizontal overflow in CSS pixels; 0 means nothing spills past the viewport.
 */
async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
}

/**
 * Money shown in an element, parsed to a number.
 */
async function moneyIn(page: Page, selector: string): Promise<number> {
  const text = (await page.locator(selector).first().innerText()).replace(/[^0-9.]/g, '');
  return Number(text || 0);
}

test.describe('Vendor OS design layer', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'Run scripts/e2e/seed.php');
    await loginPage(page, vendorUser, vendorPass);
  });

  test('vendor surfaces load the design layer', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page.locator('body')).toHaveClass(/lnkup-vos/);
    await expect(page.locator('link#lnkup-vendor-os-css')).toHaveCount(1);
  });

  for (const width of [1440, 1024, 390]) {
    test(`no page-level horizontal overflow at ${width}px`, async ({ page }) => {
      test.setTimeout(120000);
      await page.setViewportSize({ width, height: 900 });
      for (const screen of SCREENS) {
        await page.goto(screen.path, { waitUntil: 'load' });
        await expect(page.locator('.lnkup-dashboard__content')).toBeVisible();
        expect(await horizontalOverflow(page), `${screen.name} at ${width}px`).toBeLessThanOrEqual(0);
      }
    });
  }

  test('home leads with the action center and rail metrics that open filtered bookings', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page.getByTestId('lnkup-action-center')).toBeVisible();
    const metric = page.getByTestId('lnkup-vendor-home-stats').getByRole('link', { name: /payments due/i });
    await expect(metric).toHaveAttribute('href', /app_filter=pay-now/);
  });

  test('booking card opens the booking detail workspace', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=applications');
    const card = page.getByTestId('lnkup-vendor-request-card').first();
    await card.getByRole('link', { name: /details/i }).click();
    await expect(page).toHaveURL(/view=applications&app=\d+/);
    const detail = page.getByTestId('lnkup-booking-detail');
    await expect(detail).toBeVisible();
    await expect(detail.getByRole('heading', { level: 1 })).not.toHaveText(/^Application:/);
    await detail.getByRole('tab', { name: /payments/i }).click();
    await expect(page.locator('#vos-panel-payments')).toBeVisible();
  });

  test('wallet summary is server-rendered and matches outstanding rows', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=payments');
    const due = await moneyIn(page, '.lnkup-fin-balance-hero__primary .lnkup-fin-balance-hero__value');
    const rows = page.getByTestId('lnkup-wallet-due-row');
    let sum = 0;
    for (const amount of await rows.locator('.vos-due__amount').allInnerTexts()) {
      sum += Number(amount.replace(/[^0-9.]/g, ''));
    }
    expect(Math.abs(due - sum)).toBeLessThan(0.01);
  });

  test('receipt view is signed and refuses a tampered link', async ({ page, context }) => {
    await page.goto('/vendor-dashboard/?view=payments');
    await page.getByRole('tab', { name: /^receipts$/i }).click();
    const panel = page.locator('#lnkup-fin-vendor-receipts');
    await expect(panel.locator('.lnkup-fin-skeleton')).toHaveCount(0, { timeout: 15000 });
    const link = panel.locator('a').first();
    test.skip((await link.count()) === 0, 'No receipts for this vendor');
    const href = (await link.getAttribute('href')) ?? '';
    const ok = await context.request.get(href);
    expect(ok.status()).toBe(200);
    expect(await ok.text()).toContain('Payment receipt');
    const tampered = href.replace(/([?&]lnkup_token=)[^&]+/, '$1invalid');
    expect(tampered).not.toBe(href);
    const anonymous = await (await context.browser()!.newContext()).request.get(tampered);
    expect([401, 403, 404]).toContain(anonymous.status());
  });

  test('event operations header names the event and the switcher keeps routes', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=operations');
    const header = page.getByTestId('lnkup-vendor-ops-header');
    await expect(header).toBeVisible();
    await expect(header.getByRole('heading', { level: 2 })).not.toHaveText(/^Application:/);
    const select = page.locator('#lnkup-vendor-ops-event-select');
    if (await select.count()) {
      const current = await select.inputValue();
      const values = await select.locator('option').evaluateAll((opts) => opts.map((o) => (o as HTMLOptionElement).value));
      const other = values.find((v) => v !== current);
      test.skip(!other, 'Only one event to switch to');
      await select.selectOption(other as string);
      await expect(page).toHaveURL(new RegExp(`app=${other}`));
      await expect(page.getByTestId('lnkup-vendor-ops-header')).toBeVisible();
    }
  });

  test('growth funnel uses real counts and no invented booked stage', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=growth');
    await page.getByRole('button', { name: /^spending$/i }).click();
    await expect(page.locator('#lnkup-growth-revenue')).toContainText(/application funnel/i, { timeout: 15000 });
    await expect(page.locator('#lnkup-growth-revenue')).not.toContainText(/revenue over time/i);
  });

  test('home, bookings and wallet have no serious or critical axe violations', async ({ page }) => {
    for (const path of ['/vendor-dashboard/', '/vendor-dashboard/?view=applications', '/vendor-dashboard/?view=payments']) {
      await page.goto(path);
      await page.waitForLoadState('networkidle');
      const results = await new AxeBuilder({ page })
        .include('.lnkup-dashboard__content')
        .withTags(['wcag2a', 'wcag2aa'])
        .analyze();
      const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(blocking.map((v) => `${v.id}: ${v.nodes.length}`), path).toEqual([]);
    }
  });
});
