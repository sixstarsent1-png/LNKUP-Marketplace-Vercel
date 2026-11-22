import { test, expect } from '@playwright/test';
import { loginAs, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const users = {
  admin: process.env.PLAYWRIGHT_ADMIN_USER ?? '',
  orgA: process.env.PLAYWRIGHT_ORGANIZER_USER ?? '',
  orgB: process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '',
  vendorB: process.env.PLAYWRIGHT_VENDOR_B_USER ?? '',
};
const ORGANIZER_LANDING = '/organizer-dashboard/';

test.describe('Trust & Safety access control', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!users.orgA || !users.orgB, 'Run scripts/e2e/seed.php to create organizer fixtures');

  const sessions: Partial<Record<keyof typeof users, WpSession>> = {};

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    sessions.orgA = await loginAs(browser, users.orgA, password, ORGANIZER_LANDING);
    sessions.orgB = await loginAs(browser, users.orgB, password, ORGANIZER_LANDING);
    if (users.vendorB) {
      sessions.vendorB = await loginAs(browser, users.vendorB, password, '/vendor-dashboard/');
    }
    if (users.admin) {
      sessions.admin = await loginAs(browser, users.admin, password, '/wp-admin/');
    }
  });

  test.afterAll(async () => {
    await Promise.all(Object.values(sessions).map((s) => s?.context.close()));
  });

  test('organizer cannot read the trust case queue', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', '/trust/cases');
    expect(response.status()).toBe(403);
  });

  test('organizer cannot approve event claims', async () => {
    const response = await lnkupRest(sessions.orgA!, 'POST', '/trust/claims/1/approve', { note: 'Trying to self-approve.' });
    expect(response.status()).toBe(403);
  });

  test('organizer cannot change event authenticity', async () => {
    const response = await lnkupRest(sessions.orgB!, 'POST', '/trust/events/1/authenticity', { status: 'organizer_controlled', note: 'Self promotion' });
    expect(response.status()).toBe(403);
  });

  test('vendor cannot open organizer verification', async () => {
    test.skip(!sessions.vendorB, 'Set PLAYWRIGHT_VENDOR_B_USER');
    const response = await lnkupRest(sessions.vendorB!, 'GET', '/organizer/verification');
    expect(response.status()).toBe(403);
  });

  test('organizer reads own verification indicators', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', '/organizer/verification');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.indicators).toHaveProperty('payout_connected');
    expect(body.indicators).toHaveProperty('domain_verified');
    expect(body.indicators).toHaveProperty('identity_reviewed');
  });

  test('organizer cannot manage another organization team', async () => {
    const meta = await lnkupRest(sessions.orgA!, 'GET', '/org/team');
    const ownerA = (await meta.json()).owner_id as number;
    const response = await lnkupRest(sessions.orgB!, 'GET', `/org/team?owner_id=${ownerA}`);
    expect(response.status()).toBe(403);
  });

  test('forged team invite token is refused', async () => {
    const response = await lnkupRest(sessions.orgB!, 'POST', '/org/invites/accept', { token: 'not-a-real-invite' });
    expect(response.status()).toBe(400);
  });

  test('Team & Trust view shows both panels with loaded state', async () => {
    const page = await sessions.orgA!.context.newPage();
    await page.goto(`${ORGANIZER_LANDING}?view=team`);
    await expect(page.getByTestId('lnkup-org-team')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Organization verification' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Team access' })).toBeVisible();
    await expect(page.getByText('LNKup review')).toBeVisible();
    await page.close();
  });

  test('admin opens the Trust & Safety console', async () => {
    test.skip(!sessions.admin, 'Set PLAYWRIGHT_ADMIN_USER');
    const page = await sessions.admin!.context.newPage();
    await page.goto('/wp-admin/admin.php?page=lnkup-trust-safety');
    await expect(page.getByTestId('lnkup-admin-trust-safety')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Audit Log' })).toBeVisible();
    await page.close();
    const overview = await lnkupRest(sessions.admin!, 'GET', '/trust/overview');
    expect(overview.ok()).toBeTruthy();
    expect(await overview.json()).toHaveProperty('cases.total');
  });
});

test.describe('Trust & Safety anonymous access', () => {
  test('anonymous request to the case queue is refused', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/trust/cases');
    expect(response.status()).toBe(401);
  });
});
