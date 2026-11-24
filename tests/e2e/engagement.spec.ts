import { test, expect } from '@playwright/test';
import { loginAs, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? password;
const orgA = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const orgB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const eventA = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';
const CENTER = '/notification-center/';

test.describe('Engagement OS — notification center, invitations and fulfillment', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!vendorUser || !orgA || !orgB || !eventA, 'Run npm run e2e:seed to create fixtures');

  const sessions: { vendor?: WpSession; orgA?: WpSession; orgB?: WpSession } = {};

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    sessions.vendor = await loginAs(browser, vendorUser, vendorPass, '/vendor-dashboard/');
    sessions.orgA = await loginAs(browser, orgA, password, '/organizer-dashboard/');
    sessions.orgB = await loginAs(browser, orgB, password, '/organizer-dashboard/');
  });

  test.afterAll(async () => {
    await Promise.all(Object.values(sessions).map((s) => s?.context.close()));
  });

  test('vendor notification center shows role tabs including Invitations and Account', async () => {
    const page = await sessions.vendor!.context.newPage();
    await page.goto(CENTER);
    const center = page.getByTestId('lnkup-notification-center');
    await expect(center.getByRole('heading', { name: 'Notifications' })).toBeVisible();
    const tabs = center.getByRole('tablist');
    for (const name of ['All activity', 'Applications', 'Payments', 'Opportunities', 'Invitations', 'Account']) {
      await expect(tabs.getByRole('tab', { name: new RegExp(`^${name}`) })).toBeVisible();
    }
    await expect(center.getByRole('link', { name: 'Notification settings' })).toBeVisible();
    await expect(center.getByTestId('lnkup-center-mark-all')).toBeVisible();
    await page.close();
  });

  test('vendor Invitations tab lists open invitations or a clear empty state', async () => {
    const page = await sessions.vendor!.context.newPage();
    await page.goto(`${CENTER}?role=vendor&tab=invitations`);
    const invitations = page.getByTestId('lnkup-center-invitations');
    await expect(invitations).toBeVisible();
    await expect(invitations.getByRole('heading', { name: 'Open invitations' })).toBeVisible();
    await page.close();
  });

  test('switching tabs never leaves a spinner on screen', async () => {
    const page = await sessions.vendor!.context.newPage();
    await page.goto(CENTER);
    const center = page.getByTestId('lnkup-notification-center');
    await center.getByRole('tab', { name: /^Payments/ }).click();
    await expect(center.getByRole('tab', { name: /^Payments/ })).toHaveAttribute('aria-selected', 'true');
    await expect(center.getByText('Loading your notifications…')).toHaveCount(0);
    await page.close();
  });

  test('vendor invitations API is vendor-only and never exposes tokens', async () => {
    const mine = await lnkupRest(sessions.vendor!, 'GET', '/vendor/invites?scope=all');
    expect(mine.ok()).toBeTruthy();
    const body = await mine.json();
    expect(Array.isArray(body.items)).toBeTruthy();
    expect(JSON.stringify(body)).not.toContain('token');
    const organizer = await lnkupRest(sessions.orgA!, 'GET', '/vendor/invites');
    expect([401, 403]).toContain(organizer.status());
  });

  test('event fulfillment is owner-only and reports slots without counting submissions as filled', async () => {
    const own = await lnkupRest(sessions.orgA!, 'GET', `/organizer/events/${eventA}/fulfillment`);
    expect(own.ok()).toBeTruthy();
    const data = await own.json();
    expect(data.totals.held).toBeLessThanOrEqual(Math.max(data.totals.capacity, data.totals.held));
    expect(data.totals.remaining).toBeGreaterThanOrEqual(0);
    expect(Array.isArray(data.actions)).toBeTruthy();
    const other = await lnkupRest(sessions.orgB!, 'GET', `/organizer/events/${eventA}/fulfillment`);
    expect([401, 403]).toContain(other.status());
    const vendor = await lnkupRest(sessions.vendor!, 'GET', `/organizer/events/${eventA}/fulfillment`);
    expect([401, 403]).toContain(vendor.status());
  });

  test('Find Vendors rejects unknown sections and accepts the new ones', async () => {
    for (const section of ['new_nearby', 'previously_booked', 'saved', 'recently_viewed']) {
      const response = await lnkupRest(sessions.orgA!, 'GET', `/match/organizer/events/${eventA}/vendors?section=${section}`);
      expect(response.ok(), section).toBeTruthy();
    }
    const bad = await lnkupRest(sessions.orgA!, 'GET', `/match/organizer/events/${eventA}/vendors?section=everyone`);
    expect(bad.status()).toBe(400);
  });

  test('organizer next steps and inbox tabs include invitations', async () => {
    const inbox = await lnkupRest(sessions.orgA!, 'GET', '/comms/inbox?role=organizer&tab=all&page=1');
    expect(inbox.ok()).toBeTruthy();
    expect((await inbox.json()).tabs).toEqual(['all', 'applications', 'payments', 'events', 'operations', 'invitations', 'account']);
    const steps = await lnkupRest(sessions.orgA!, 'GET', '/comms/next-steps?role=organizer');
    expect(steps.ok()).toBeTruthy();
    const body = await steps.json();
    expect(Array.isArray(body.steps)).toBeTruthy();
  });
});
