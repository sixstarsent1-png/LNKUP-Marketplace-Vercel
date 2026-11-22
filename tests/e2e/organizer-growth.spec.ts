import { expect, test, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const adminUser = process.env.PLAYWRIGHT_ADMIN_USER;
const adminPass = process.env.PLAYWRIGHT_ADMIN_PASS;
const ADMIN_PAGE = '/wp-admin/admin.php?page=lnkup-organizer-growth';
const run = Date.now().toString(36);
const prefix = `PW OGT ${run}`;
const spendDate = '2002-02-02';
const spendChannel = `pw_${run}`;

interface GrowthConfig {
  root: string;
  nonce: string;
}

/**
 * Call lnkup/v1/growth with the nonce the admin page exposes.
 */
async function growthRest(page: Page, method: string, path: string, body?: unknown) {
  return page.evaluate(
    async ({ method: m, path: p, body: b }) => {
      const cfg = (window as unknown as { lnkupGrowth: GrowthConfig }).lnkupGrowth;
      const res = await fetch(cfg.root + p, {
        method: m,
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
        body: b ? JSON.stringify(b) : undefined,
      });
      return { status: res.status, json: await res.json() };
    },
    { method, path, body },
  );
}

/**
 * Open the Organizer Growth admin page on a given tab.
 */
async function openTab(page: Page, tab: RegExp) {
  await page.goto(ADMIN_PAGE);
  await page.getByRole('tab', { name: tab }).click();
}

function sampleCsv(): string {
  return [
    'Organizer name,Public email,Website,City,State,Event frequency,Event categories,Estimated booths,Application process,Verification date',
    `"${prefix} Charlotte Night Market",hello@cnm-${run}.test,https://cnm-${run}.test,Charlotte,NC,Monthly,Food,60,Google Form,${new Date().toISOString().slice(0, 10)}`,
    `"${prefix} Charlotte Night Market Dup",info@cnm-${run}.test,,Charlotte,NC,,,,,`,
    `"${prefix} Atlanta Makers Fair",team@amf-${run}.test,amf-${run}.test,Atlanta,GA,Annual,Art,25,Email,`,
  ].join('\n');
}

test.describe('Organizer Growth admin', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });
  let savedSettings: Record<string, unknown> | null = null;
  let importId = 0;

  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'wp-admin flow runs on desktop Chromium');
    test.skip(!adminUser || !adminPass, 'Set PLAYWRIGHT_ADMIN_USER / PLAYWRIGHT_ADMIN_PASS');
    await loginPage(page, adminUser as string, adminPass as string);
    await page.goto(ADMIN_PAGE);
    if (savedSettings === null) {
      const current = await growthRest(page, 'GET', '/settings');
      expect(current.status).toBe(200);
      savedSettings = current.json.settings;
    }
    const enabled = await growthRest(page, 'POST', '/settings', { organizer_growth_enabled: true });
    expect(enabled.status).toBe(200);
  });

  test.afterAll(async ({ browser }, testInfo) => {
    if (testInfo.project.name !== 'chromium' || !adminUser || !adminPass || savedSettings === null) {
      return;
    }
    const page = await browser.newPage();
    await loginPage(page, adminUser, adminPass);
    await page.goto(ADMIN_PAGE);
    if (importId) {
      await growthRest(page, 'POST', `/import/${importId}/rollback`);
    }
    await growthRest(page, 'POST', '/settings', savedSettings);
    await page.close();
  });

  test('administrator imports organizer leads with a dry run first', async ({ page }) => {
    await openTab(page, /^import$/i);
    const form = page.getByTestId('og-import-form');
    await form.getByLabel('CSV file').setInputFiles({ name: `organizers-${run}.csv`, mimeType: 'text/csv', buffer: Buffer.from(sampleCsv()) });
    await form.getByLabel('Source label').fill(`Playwright ${run}`);
    await form.getByRole('button', { name: /upload & map columns/i }).click();

    const step = page.getByTestId('og-import-step');
    await expect(step.getByRole('combobox', { name: 'Map Organizer name' })).toHaveValue('name');
    await step.getByTestId('og-import-dry-run').click();
    await expect(page.getByTestId('og-dry-run-summary')).toContainText('Will create 2');
    await expect(page.getByTestId('og-dry-run-summary')).toContainText('skip duplicates 1');

    await step.getByTestId('og-import-run').click();
    await expect(page.getByTestId('og-import-result')).toContainText('created 2', { timeout: 60_000 });
    await expect(page.getByTestId('og-import-result')).toContainText('duplicates 1');

    const history = await growthRest(page, 'GET', '/import');
    importId = history.json.items.find((i: { source_label: string }) => i.source_label === `Playwright ${run}`)?.id ?? 0;
    expect(importId).toBeGreaterThan(0);
  });

  test('duplicate lead is detected and the existing lead opens', async ({ page }) => {
    await openTab(page, /organizer leads/i);
    await page.getByRole('button', { name: 'Add lead' }).click();
    const form = page.getByTestId('og-lead-form');
    await form.getByLabel('Organizer or business name').fill(`${prefix} Someone Else`);
    await form.getByLabel('Public business email').fill(`HELLO@cnm-${run}.test`);
    await form.getByRole('button', { name: 'Save lead' }).click();
    await expect(page.getByRole('status')).toContainText(/already exists/i);
    await expect(page.getByTestId('og-drawer').getByRole('heading', { name: `${prefix} Charlotte Night Market` })).toBeVisible();
  });

  test('assigned teammate logs outreach and moves the pipeline', async ({ page }) => {
    await openTab(page, /organizer leads/i);
    const filters = page.getByTestId('og-lead-filters');
    await filters.getByLabel('Search').fill(`${prefix} Charlotte`);
    await filters.getByRole('button', { name: 'Apply filters' }).click();
    await page.getByTestId('og-leads-table').getByRole('button', { name: `${prefix} Charlotte Night Market` }).click();

    const drawer = page.getByTestId('og-drawer');
    const contact = drawer.getByTestId('og-contact-form');
    await contact.getByLabel('Channel').selectOption('instagram_dm');
    await contact.getByLabel('Outcome').selectOption('replied');
    await contact.getByLabel('Summary').fill('Replied to our Instagram DM');
    await contact.getByRole('button', { name: 'Log activity' }).click();
    await expect(drawer.getByTestId('og-activity')).toContainText('Replied to our Instagram DM');
    await expect(drawer.getByTestId('og-activity')).toContainText('Discovered → Responded');

    await drawer.getByRole('combobox', { name: 'Pipeline stage' }).selectOption('interested');
    await drawer.getByTestId('og-stage-save').click();
    await expect(drawer.getByTestId('og-activity')).toContainText('Responded → Interested');

    const stageOptions = await drawer.getByRole('combobox', { name: 'Pipeline stage' }).locator('option').allTextContents();
    expect(stageOptions).not.toContain('First Paid Booking');
  });

  test('milestone stages cannot be set by hand over REST', async ({ page }) => {
    const leads = await growthRest(page, 'GET', `/leads?search=${encodeURIComponent(prefix + ' Atlanta')}`);
    const id = leads.json.items[0].id;
    const res = await growthRest(page, 'POST', `/leads/${id}/stage`, { stage: 'first_paid_booking' });
    expect(res.status).toBe(409);
    expect(res.json.code).toBe('lnkup_stage_milestone_only');
  });

  test('pipeline board shows imported leads and verified columns', async ({ page }) => {
    await openTab(page, /acquisition pipeline/i);
    const board = page.getByTestId('og-board');
    await expect(board.getByRole('button', { name: new RegExp(`${prefix} Atlanta Makers Fair`) })).toBeVisible();
    await expect(board.getByText('First Paid Booking')).toBeVisible();
  });

  test('suppression list accepts a manual entry and invalid unsubscribe links are rejected', async ({ page }) => {
    await openTab(page, /outreach campaigns/i);
    await page.getByLabel('Email or domain to suppress').fill(`nobody-${run}@example.test`);
    await page.getByRole('button', { name: 'Add to suppression' }).click();
    await expect(page.getByRole('status')).toContainText('Suppressed.');

    await page.goto('/?lnkup_org_unsub=r1.forged');
    await expect(page.getByRole('heading', { name: /not valid/i })).toBeVisible();
  });

  test('overview shows real counts and marks acquisition cost unavailable', async ({ page }) => {
    await openTab(page, /^overview$/i);
    await expect(page.getByTestId('og-kpis')).toContainText('Total prospects');
    await expect(page.getByText(/no marketing spend or commission records/i).first()).toBeVisible();
  });

  test('analytics shows empty states for a period with no organizers', async ({ page }) => {
    await openTab(page, /^analytics$/i);
    await applyAnalyticsWindow(page, '2001-01-01', '2001-01-31');
    const body = page.getByTestId('og-analytics-body');
    await expect(body.getByTestId('og-time-to')).toContainText('no data yet');
    await expect(body.getByText('No registered organizers or cost records in this period.')).toBeVisible();
    await expect(body.getByText(/No registered organizers yet/)).toBeVisible();
  });

  test('recorded spend makes acquisition cost available for its channel', async ({ page }) => {
    await openTab(page, /^spend$/i);
    const form = page.getByTestId('og-spend-form');
    await form.getByLabel('Date spent').fill(spendDate);
    await form.getByLabel('Channel').fill(spendChannel);
    await form.getByLabel('Amount (USD)').fill('125');
    await form.getByLabel('Description').fill(`${prefix} flyers`);
    await form.getByRole('button', { name: 'Add spend' }).click();
    await expect(page.getByRole('status')).toContainText('Spend recorded.');
    await expect(page.getByTestId('og-spend-list')).toContainText(`${prefix} flyers`);

    await openTab(page, /^analytics$/i);
    await applyAnalyticsWindow(page, spendDate, spendDate);
    const cost = page.getByTestId('og-cac');
    await expect(cost).toContainText('$125.00');
  });

  test('spend entries are voided with a reason, never deleted', async ({ page }) => {
    await openTab(page, /^spend$/i);
    const row = page.getByTestId('og-spend-list').getByRole('row').filter({ hasText: `${prefix} flyers` });
    page.once('dialog', (dialog) => dialog.accept('Playwright cleanup'));
    await row.getByRole('button', { name: 'Void' }).click();
    await expect(page.getByRole('status')).toContainText('Entry voided.');
    await expect(page.getByTestId('og-spend-list').getByRole('row').filter({ hasText: `${prefix} flyers` })).toContainText('Voided: Playwright cleanup');
  });
});

/**
 * Narrow the analytics tab to a date window and wait for it to reload.
 */
async function applyAnalyticsWindow(page: Page, from: string, to: string) {
  const filters = page.getByTestId('og-analytics-filters');
  await filters.getByLabel('From').fill(from);
  await filters.getByLabel('To').fill(to);
  await filters.getByRole('button', { name: 'Apply' }).click();
  await expect(page.getByTestId('og-time-to')).toBeVisible();
}
