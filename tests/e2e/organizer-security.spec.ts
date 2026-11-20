import { test, expect } from '@playwright/test';
import { loginAs, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const users = {
  orgA: process.env.PLAYWRIGHT_ORGANIZER_USER ?? '',
  orgB: process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '',
  security: process.env.PLAYWRIGHT_STAFF_SECURITY_USER ?? '',
  finance: process.env.PLAYWRIGHT_STAFF_FINANCE_USER ?? '',
  vendorB: process.env.PLAYWRIGHT_VENDOR_B_USER ?? '',
};
const eventA = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID;
const paymentA = process.env.PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID;
const opportunityA = process.env.PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID;
const ORGANIZER_LANDING = '/organizer-dashboard/';

test.describe('Organizer tenant isolation and staff permissions', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!users.orgA || !users.orgB || !eventA, 'Run scripts/e2e/seed.php to create organizer fixtures');

  const sessions: Partial<Record<keyof typeof users, WpSession>> = {};

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    sessions.orgA = await loginAs(browser, users.orgA, password, ORGANIZER_LANDING);
    sessions.orgB = await loginAs(browser, users.orgB, password, ORGANIZER_LANDING);
    sessions.security = await loginAs(browser, users.security, password, ORGANIZER_LANDING);
    sessions.finance = await loginAs(browser, users.finance, password, ORGANIZER_LANDING);
  });

  test.afterAll(async () => {
    await Promise.all(Object.values(sessions).map((s) => s?.context.close()));
  });

  test('another organizer cannot read event support tickets', async () => {
    const response = await lnkupRest(sessions.orgB!, 'GET', `/operations/support?event_id=${eventA}`);
    expect(response.status()).toBe(403);
  });

  test('support ticket list requires an event', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', '/operations/support');
    expect(response.status()).toBe(400);
  });

  test('owner reads own support tickets', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', `/operations/support?event_id=${eventA}`);
    expect(response.ok()).toBeTruthy();
  });

  test('another organizer cannot read event financials', async () => {
    const response = await lnkupRest(sessions.orgB!, 'GET', `/financial/events/${eventA}/financials`);
    expect(response.status()).toBe(403);
  });

  test('security staff cannot read event financials', async () => {
    const response = await lnkupRest(sessions.security!, 'GET', `/financial/events/${eventA}/financials`);
    expect(response.status()).toBe(403);
  });

  test('finance staff can read event financials', async () => {
    const response = await lnkupRest(sessions.finance!, 'GET', `/financial/events/${eventA}/financials`);
    expect(response.ok()).toBeTruthy();
    expect(response.headers()['content-type']).toContain('application/json');
  });

  test('security staff cannot add staff', async () => {
    const response = await lnkupRest(sessions.security!, 'POST', `/operations/events/${eventA}/staff`, {
      user_id: 1,
      role: 'operations',
    });
    expect(response.status()).toBe(403);
  });

  test('another organizer cannot calculate a refund on a foreign payment', async () => {
    test.skip(!paymentA, 'Set PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID');
    const response = await lnkupRest(sessions.orgB!, 'POST', '/financial/refunds/calculate', { payment_id: Number(paymentA) });
    expect(response.status()).toBe(403);
  });

  test('another organizer cannot invite vendors into a foreign opportunity', async () => {
    test.skip(!opportunityA, 'Set PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID');
    const response = await lnkupRest(sessions.orgB!, 'POST', '/crm/invites', {
      vendor_user_id: 1,
      event_id: Number(eventA),
      opportunity_id: Number(opportunityA),
    });
    expect([400, 403]).toContain(response.status());
  });

  test('intelligence AJAX without a nonce returns JSON 403', async () => {
    const response = await sessions.orgA!.request.post('/wp-admin/admin-ajax.php', {
      form: { action: 'lnkup_event_intelligence' },
    });
    expect(response.status()).toBe(403);
    const body = await response.json();
    expect(body.success).toBe(false);
  });
});

test.describe('Invite responses', () => {
  test('anonymous invite response is refused', async ({ request }) => {
    const response = await request.post('/wp-json/lnkup/v1/crm/invites/respond', {
      headers: { 'Content-Type': 'application/json' },
      data: { token: 'not-a-real-token', accept: true },
    });
    expect(response.status()).toBe(401);
  });
});
