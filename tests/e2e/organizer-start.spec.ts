import { expect, test, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const START_URL = '/organizer-dashboard/?view=start';

/**
 * Call lnkup/v1 with the nonce the Get started view exposes.
 */
async function startRest(page: Page, method: string, path: string, body?: unknown) {
  return page.evaluate(
    async ({ method: m, path: p, body: b }) => {
      const cfg = (window as unknown as { lnkupStart: { root: string; nonce: string } }).lnkupStart;
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
 * Finished steps start collapsed; open them so their content can be checked.
 */
async function expandFinishedSteps(page: Page) {
  const show = page.getByTestId('lnkup-organizer-start').getByRole('button', { name: 'Show', exact: true });
  while ((await show.count()) > 0) {
    await show.first().click();
  }
}

test.describe('Organizer Get started', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });

  test.beforeEach(async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'chromium', 'Runs once on desktop Chromium');
    test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER / PLAYWRIGHT_ORGANIZER_PASS');
    await loginPage(page, organizerUser as string, organizerPass as string);
  });

  test('shows six setup stages with progress and a next step', async ({ page }) => {
    await page.goto(START_URL);
    const start = page.getByTestId('lnkup-organizer-start');
    await expect(start).toBeVisible();
    await expect(start.getByRole('heading', { name: 'Get your first vendor booking' })).toBeVisible();
    for (const key of ['organization', 'payouts', 'first_event', 'publication', 'first_applications', 'first_paid_booking']) {
      await expect(page.getByTestId(`lnkup-start-stage-${key}`)).toBeVisible();
    }
    await expect(page.getByTestId('lnkup-start-next')).toBeVisible();
    await expect(page.getByTestId('lnkup-start-import')).toHaveAttribute('href', /view=vendors/);
  });

  test('organization step saves through the profile API', async ({ page }) => {
    await page.goto(START_URL);
    await expandFinishedSteps(page);
    const form = page.getByTestId('lnkup-start-profile');
    const nameInput = form.getByLabel('Organization name');
    const cityInput = form.getByLabel('City');
    const stateInput = form.getByLabel('State');
    const original = { name: await nameInput.inputValue(), city: await cityInput.inputValue(), state: await stateInput.inputValue() };
    const stamp = `E2E Org ${Date.now().toString(36)}`;

    await nameInput.fill(stamp);
    await cityInput.fill('Charlotte');
    await stateInput.fill('NC');
    await form.getByRole('button', { name: 'Save organization' }).click();
    await expect(form.getByRole('status')).toHaveText('Saved.', { timeout: 20_000 });

    await page.reload();
    await expect(page.getByTestId('lnkup-start-profile').getByLabel('Organization name')).toHaveValue(stamp);
    await expect(page.getByTestId('lnkup-start-stage-organization')).toHaveClass(/is-complete/);

    const restored = await startRest(page, 'POST', '/organizer/profile', { public_name: original.name, city: original.city, state: original.state });
    expect(restored.status).toBe(200);
  });

  test('published event shows a copyable apply link and points to Marketing for the embed', async ({ page }) => {
    await page.goto(START_URL);
    if ((await page.getByTestId('lnkup-start-share').count()) === 0) {
      const date = new Date(Date.now() + 45 * 86_400_000).toISOString().slice(0, 10);
      const deadline = new Date(Date.now() + 30 * 86_400_000).toISOString().slice(0, 10);
      const published = await startRest(page, 'POST', '/organizer/launch/publish', {
        event_name: `E2E Get started market ${Date.now().toString(36)}`,
        event_date: date,
        city: 'Charlotte',
        state: 'NC',
        categories: ['Art'],
        vendor_fee: 0,
        spots_total: 3,
        application_deadline: deadline,
        client_token: `e2e-start-${Date.now()}`,
      });
      expect(published.status).toBe(200);
      expect(published.json.application_url).toContain('apply=');
      await page.reload();
    }
    await expandFinishedSteps(page);
    const share = page.getByTestId('lnkup-start-share');
    await expect(share).toBeVisible();
    await expect(share.getByLabel('Your vendor apply link')).toHaveValue(/[?&]apply=\d+/);
    await expect(share.getByRole('link', { name: 'Get an Apply button for your website' })).toHaveAttribute('href', /view=marketing|tab=marketing/);
    await expect(share.getByRole('button', { name: 'Copy link' }).first()).toBeVisible();
  });

  test('saved setups panel loads for the organizer', async ({ page }) => {
    await page.goto(START_URL);
    await expandFinishedSteps(page);
    const setups = page.getByTestId('lnkup-start-setups');
    await expect(setups.getByRole('heading', { name: 'Saved event setups' })).toBeVisible();
  });
});
