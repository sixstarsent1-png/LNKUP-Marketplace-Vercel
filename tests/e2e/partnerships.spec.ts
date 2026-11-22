import { expect, test, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const adminUser = process.env.PLAYWRIGHT_ADMIN_USER;
const adminPass = process.env.PLAYWRIGHT_ADMIN_PASS;
const connectorUser = process.env.PLAYWRIGHT_CONNECTOR_USER;
const connectorPass = process.env.PLAYWRIGHT_CONNECTOR_PASS;
const HUB = '/wp-admin/admin.php?page=lnkup-partnerships';
const run = Date.now().toString(36);

/**
 * Open a Partnerships & Referrals admin tab by its visible label.
 */
async function openHubTab(page: Page, name: RegExp) {
  await page.goto(HUB);
  await page.getByRole('link', { name }).click();
}

test.describe('Partnership OS — access control', () => {
  test('partner admin REST routes reject anonymous callers', async ({ request }) => {
    for (const path of ['/partners/overview', '/partners/ledger', '/partners/rules', '/partners/reviews', '/partners/territories']) {
      const res = await request.get(`/wp-json/lnkup/v1${path}`);
      expect(res.status(), path).toBe(401);
      expect(res.headers()['content-type']).toContain('application/json');
    }
  });

  test('ledger mutations reject anonymous callers', async ({ request }) => {
    const res = await request.post('/wp-json/lnkup/v1/partners/ledger/1/mark-paid', { data: { payout_reference: 'x' } });
    expect(res.status()).toBe(401);
  });

  test('connector lead routes reject anonymous callers', async ({ request }) => {
    expect((await request.get('/wp-json/lnkup/v1/connector/leads')).status()).toBe(401);
    expect((await request.post('/wp-json/lnkup/v1/connector/leads', { data: { name: 'x', website: 'https://x.test' } })).status()).toBe(401);
    expect((await request.get('/wp-json/lnkup/v1/connector/territories')).status()).toBe(401);
  });
});

test.describe('Partnerships & Referrals admin hub', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'wp-admin flow runs on desktop Chromium');
    test.skip(!adminUser || !adminPass, 'Set PLAYWRIGHT_ADMIN_USER / PLAYWRIGHT_ADMIN_PASS');
    await loginPage(page, adminUser as string, adminPass as string);
  });

  test('overview shows real ledger metrics and the attribution mode', async ({ page }) => {
    await page.goto(HUB);
    await expect(page.getByTestId('lnkup-partnerships-admin')).toBeVisible();
    await expect(page.getByTestId('lnkup-partners-overview')).toBeVisible();
    await expect(page.getByText('Active City Connectors')).toBeVisible();
    await expect(page.getByText(/Attribution mode/)).toBeVisible();
  });

  test('commission rules list the grandfathered GMV rule and the draft fee rule', async ({ page }) => {
    await openHubTab(page, /^Commission Rules$/);
    const rules = page.getByTestId('lnkup-partners-rules');
    await expect(rules).toBeVisible();
    await expect(rules.getByText(/of the booth price on qualifying bookings/).first()).toBeVisible();
    await expect(rules.getByText('draft').first()).toBeVisible();
    await expect(page.getByTestId('lnkup-partners-rule-form')).toBeVisible();
  });

  test('ledger, reviews, connectors and settings tabs render without errors', async ({ page }) => {
    for (const tab of ['ledger', 'reviews', 'connectors', 'settings', 'pipeline']) {
      await page.goto(`${HUB}&tab=${tab}`);
      await expect(page.getByTestId(`lnkup-partners-tab-${tab}`)).toHaveClass(/nav-tab-active/);
      await expect(page.getByText(/Fatal error|Warning:|Notice:/)).toHaveCount(0);
    }
    await expect(page.getByTestId('lnkup-partners-tab-pipeline')).toHaveClass(/nav-tab-active/);
    await page.goto(`${HUB}&tab=settings`);
    await expect(page.getByLabel('Attribution enforcement')).toHaveValue(/shadow|enforce|off/);
  });
});

test.describe('City Connector — Organizer Leads and My Cities', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeEach(async ({ page }) => {
    test.skip(!connectorUser || !connectorPass, 'Set PLAYWRIGHT_CONNECTOR_USER / PLAYWRIGHT_CONNECTOR_PASS (see scripts/e2e/php/partnerships-seed-connector.php)');
    await loginPage(page, connectorUser as string, connectorPass as string);
  });

  test('connector submits an organizer lead and sees it in their list', async ({ page }, testInfo) => {
    const name = `PW Lead ${run} ${testInfo.project.name}`;
    const site = `https://pw-lead-${run}-${testInfo.project.name}.example`;
    await page.goto('/connector-dashboard/?view=leads');
    const form = page.getByTestId('lnkup-connector-lead-form');
    await expect(form).toBeVisible();
    await form.getByLabel('Organizer or business name').fill(name);
    await form.getByLabel('Public website').fill(site);
    await form.getByLabel('City').fill('Charlotte');
    await form.getByRole('button', { name: 'Submit lead' }).click();
    await expect(form.getByRole('status')).toContainText(/LNKup will review this organizer/);
    await expect(page.getByTestId('lnkup-connector-leads').getByText(name)).toBeVisible();

    await form.getByLabel('Organizer or business name').fill(`${name} again`);
    await form.getByLabel('Public website').fill(site);
    await form.getByRole('button', { name: 'Submit lead' }).click();
    await expect(form.getByRole('status')).toContainText(/already known to LNKup/);
  });

  test('lead form requires a public contact route before submitting', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=leads');
    const form = page.getByTestId('lnkup-connector-lead-form');
    await form.getByLabel('Organizer or business name').fill(`PW No Contact ${run}`);
    await form.getByRole('button', { name: 'Submit lead' }).click();
    await expect(form.getByRole('status')).toContainText(/public website, Instagram or business email/);
  });

  test('My Cities explains territories without blocking referrals', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=cities');
    const cities = page.getByTestId('lnkup-connector-cities');
    await expect(cities).toBeVisible();
    await expect(cities.getByText(/referrals outside these cities count the same/)).toBeVisible();
  });

  test('leads view does not overflow on a phone-width viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/connector-dashboard/?view=leads');
    await expect(page.getByTestId('lnkup-connector-lead-form')).toBeVisible();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow).toBe(false);
  });
});
