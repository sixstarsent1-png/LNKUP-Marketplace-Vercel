import { test, expect } from '@playwright/test';
import { loginAs, loginPage, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? password;
const orgA = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const orgB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const eventA = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';
const VENDOR_LANDING = '/vendor-dashboard/';
const ORGANIZER_LANDING = '/organizer-dashboard/';

test.describe('LNKup Communications — REST contract and isolation', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!vendorUser || !orgA || !orgB || !eventA, 'Run npm run e2e:seed to create fixtures');

  const sessions: { vendor?: WpSession; orgA?: WpSession; orgB?: WpSession } = {};

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    sessions.vendor = await loginAs(browser, vendorUser, vendorPass, VENDOR_LANDING);
    sessions.orgA = await loginAs(browser, orgA, password, ORGANIZER_LANDING);
    sessions.orgB = await loginAs(browser, orgB, password, ORGANIZER_LANDING);
  });

  test.afterAll(async () => {
    await Promise.all(Object.values(sessions).map((s) => s?.context.close()));
  });

  test('vendor preferences list vendor categories with a locked account category', async () => {
    const response = await lnkupRest(sessions.vendor!, 'GET', '/comms/preferences');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    const keys = body.categories.map((c: { key: string }) => c.key);
    expect(keys).toContain('opportunities');
    expect(keys).not.toContain('recruitment');
    expect(body.categories.find((c: { key: string }) => c.key === 'account').locked).toBe(true);
  });

  test('vendor can switch a category to a weekly digest and back', async () => {
    const weekly = await lnkupRest(sessions.vendor!, 'POST', '/comms/preferences', { categories: [{ key: 'applications', email: 'weekly' }] });
    expect(weekly.ok()).toBeTruthy();
    expect((await weekly.json()).categories.find((c: { key: string }) => c.key === 'applications').email).toBe('weekly');
    const reset = await lnkupRest(sessions.vendor!, 'POST', '/comms/preferences', { categories: [{ key: 'applications', email: 'immediate' }] });
    expect(reset.ok()).toBeTruthy();
  });

  test('locked categories cannot be turned off', async () => {
    const response = await lnkupRest(sessions.vendor!, 'POST', '/comms/preferences', { categories: [{ key: 'account', email: 'off' }] });
    const body = await response.json();
    expect(body.categories.find((c: { key: string }) => c.key === 'account').email).toBe('immediate');
  });

  test('vendor inbox exposes vendor tabs and pages', async () => {
    const response = await lnkupRest(sessions.vendor!, 'GET', '/comms/inbox?role=vendor&tab=all&page=1');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.tabs).toEqual(['all', 'applications', 'payments', 'events', 'opportunities', 'invitations', 'account']);
    expect(Array.isArray(body.items)).toBeTruthy();
  });

  test('next steps come from live state', async () => {
    const response = await lnkupRest(sessions.vendor!, 'GET', '/comms/next-steps?role=vendor');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(Array.isArray(body.steps)).toBeTruthy();
    expect(Array.isArray(body.upcoming)).toBeTruthy();
  });

  test('vendors cannot reach admin or organizer communication routes', async () => {
    expect((await lnkupRest(sessions.vendor!, 'GET', '/comms/admin/overview')).status()).toBe(403);
    expect((await lnkupRest(sessions.vendor!, 'GET', `/comms/events/${eventA}/audiences`)).status()).toBe(403);
  });

  test('another organizer cannot read this event’s vendor audiences', async () => {
    expect((await lnkupRest(sessions.orgB!, 'GET', `/comms/events/${eventA}/audiences`)).status()).toBe(403);
  });

  test('the owning organizer gets audiences, or a clear "not enabled" response', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', `/comms/events/${eventA}/audiences`);
    if (response.status() === 403) {
      expect((await response.json()).code).toBe('lnkup_comms_disabled');
      return;
    }
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.groups.map((g: { key: string }) => g.key)).toContain('confirmed');
  });

  test('anonymous requests are rejected', async ({ request }) => {
    expect((await request.get('/wp-json/lnkup/v1/comms/inbox')).status()).toBe(401);
  });
});

test.describe('LNKup Communications — preferences page', () => {
  test.skip(!vendorUser, 'Run npm run e2e:seed to create fixtures');

  test('vendor manages notification settings on the web', async ({ page }) => {
    await loginPage(page, vendorUser, vendorPass);
    await page.goto('/notification-settings/');
    const form = page.getByTestId('lnkup-comms-preferences-form');
    await expect(form).toBeVisible();
    const select = form.getByLabel(/application updates — email/i);
    await select.selectOption('daily');
    await form.getByRole('button', { name: /save settings/i }).click();
    await expect(form.getByRole('status')).toHaveText(/settings saved/i);
    await select.selectOption('immediate');
    await form.getByRole('button', { name: /save settings/i }).click();
    await expect(form.getByRole('status')).toHaveText(/settings saved/i);
  });

  test('anonymous visitors are asked to sign in', async ({ page }) => {
    await page.goto('/notification-settings/');
    await expect(page.getByText(/sign in to manage your notifications/i)).toBeVisible();
  });
});
