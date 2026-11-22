import { expect, test, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const adminUser = process.env.PLAYWRIGHT_ADMIN_USER;
const adminPass = process.env.PLAYWRIGHT_ADMIN_PASS;
const connectorUser = process.env.PLAYWRIGHT_CONNECTOR_USER;
const connectorPass = process.env.PLAYWRIGHT_CONNECTOR_PASS;
const affiliateUser = process.env.PLAYWRIGHT_AFFILIATE_USER;
const affiliatePass = process.env.PLAYWRIGHT_AFFILIATE_PASS;
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

test.describe('Public partner program pages', () => {
  test('affiliate page shows pending terms with no percentage and an application form', async ({ page }) => {
    const res = await page.goto('/affiliate-program/');
    expect(res?.status()).toBe(200);
    const terms = page.getByTestId('lnkup-partner-terms');
    await expect(terms).toBeVisible();
    await expect(terms).toHaveAttribute('data-published', '0');
    await expect(terms).not.toContainText('%');
    await expect(page.getByTestId('lnkup-partner-apply-form')).toBeVisible();
    await expect(page.getByText(/Approval and earnings are not guaranteed/)).toBeVisible();
  });

  test('partner page asks for an organization', async ({ page }) => {
    await page.goto('/partner-with-lnkup/');
    const form = page.getByTestId('lnkup-partner-apply-form');
    await expect(form).toBeVisible();
    await expect(form.getByLabel('Organization', { exact: true })).toBeVisible();
    await expect(form.getByLabel('Organization type')).toBeVisible();
  });

  test('City Connector page shows terms from the published rule', async ({ page }) => {
    await page.goto('/connector-interest/');
    const terms = page.getByTestId('lnkup-partner-terms');
    await expect(terms).toHaveAttribute('data-published', '1');
    await expect(terms).toContainText(/of the booth price/);
    await expect(page.getByText('You receive 10% commission on the booking for 12 months.')).toHaveCount(0);
  });

  test('application form validates disclosures before submitting', async ({ page }) => {
    await page.goto('/affiliate-program/');
    const form = page.getByTestId('lnkup-partner-apply-form');
    await form.getByLabel('Your name').fill('PW Validation');
    await form.getByLabel('Business email').fill(`pw-val-${run}@example.test`);
    await form.getByLabel('City').fill('Charlotte');
    await form.getByRole('button', { name: 'Submit application' }).click();
    await expect(form.getByRole('status')).toContainText(/agree to the program terms/);
  });
});

test.describe('Affiliate application to approval', () => {
  test.describe.configure({ mode: 'serial' });
  const email = `pw-affiliate-${run}@example.test`;

  test('visitor submits an affiliate application', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'one submission per run');
    await page.goto('/affiliate-program/');
    const form = page.getByTestId('lnkup-partner-apply-form');
    await form.getByLabel('Your name').fill(`PW Affiliate ${run}`);
    await form.getByLabel('Business email').fill(email);
    await form.getByLabel('City').fill('Charlotte');
    await form.getByLabel('Which organizers would you introduce?').fill('Night markets in Charlotte');
    await form.getByRole('checkbox').check();
    await form.getByRole('button', { name: 'Submit application' }).click();
    await expect(form.getByRole('status')).toContainText(/We received your application/);
  });

  test('admin approves the application from the Applications tab', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'wp-admin flow runs on desktop Chromium');
    test.skip(!adminUser || !adminPass, 'Set PLAYWRIGHT_ADMIN_USER / PLAYWRIGHT_ADMIN_PASS');
    await loginPage(page, adminUser as string, adminPass as string);
    await page.goto(`${HUB}&tab=applications&program=affiliate&status=pending`);
    const table = page.getByTestId('lnkup-partners-applications');
    const row = table.getByRole('row').filter({ hasText: email });
    await expect(row).toBeVisible();
    await row.getByLabel('Application action').selectOption('approve');
    page.once('dialog', (dialog) => dialog.accept());
    await row.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText('Application updated.')).toBeVisible();
    await page.goto(`${HUB}&tab=applications&program=affiliate&status=active`);
    await expect(page.getByTestId('lnkup-partners-applications').getByRole('row').filter({ hasText: email })).toContainText('Approved');
  });

  test('strategic partners tab renders the pipeline', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'wp-admin flow runs on desktop Chromium');
    test.skip(!adminUser || !adminPass, 'Set PLAYWRIGHT_ADMIN_USER / PLAYWRIGHT_ADMIN_PASS');
    await loginPage(page, adminUser as string, adminPass as string);
    await page.goto(`${HUB}&tab=partners`);
    await expect(page.getByTestId('lnkup-partners-tab-partners')).toHaveClass(/nav-tab-active/);
    await expect(page.getByTestId('lnkup-partners-org-create')).toBeVisible();
    await expect(page.getByText(/Fatal error|Warning:|Notice:/)).toHaveCount(0);
  });
});

test.describe('Affiliate dashboard', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!affiliateUser || !affiliatePass, 'Set PLAYWRIGHT_AFFILIATE_USER / PLAYWRIGHT_AFFILIATE_PASS (see scripts/e2e/php/partnerships-seed-connector.php)');
    await loginPage(page, affiliateUser as string, affiliatePass as string);
  });

  test('affiliate dashboard hides Ranks, Studio and My Cities', async ({ page }) => {
    await page.goto('/connector-dashboard/');
    const dashboard = page.getByTestId('lnkup-connector-dashboard');
    await expect(dashboard).toHaveAttribute('data-program', 'affiliate');
    const nav = page.getByRole('navigation', { name: 'Connector views' });
    await expect(nav.getByRole('link', { name: 'Organizer Leads' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Ranks' })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'Content Studio' })).toHaveCount(0);
    await expect(nav.getByRole('link', { name: 'My Cities' })).toHaveCount(0);
  });

  test('affiliate earnings explain pending terms instead of rates', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=earnings');
    await expect(page.getByTestId('lnkup-connector-terms-pending')).toBeVisible();
    await expect(page.getByTestId('lnkup-connector-calculator')).toHaveCount(0);
  });

  test('ranks URL falls back to the overview', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=ranks');
    await expect(page.getByTestId('lnkup-connector-dashboard')).toHaveAttribute('data-view', 'home');
    await expect(page.getByTestId('lnkup-connector-roadmap')).toHaveCount(0);
  });
});
