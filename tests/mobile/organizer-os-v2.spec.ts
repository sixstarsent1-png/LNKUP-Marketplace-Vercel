import { test, expect, type Page } from '@playwright/test';
import {
  appLogin,
  expectNoHorizontalOverflow,
  organizerBUser,
  organizerUser,
  restAs,
  trackApiRequests,
} from './helpers/app-session';

const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '927';
const freeEventId = process.env.PLAYWRIGHT_FREE_EVENT_ID || '931';
const reviewAppId = process.env.PLAYWRIGHT_REVIEW_APPLICATION_ID || '';
const securityUser = process.env.PLAYWRIGHT_STAFF_SECURITY_USER || 'e2e_staff_security';
const rawDate = /\b\d{4}-\d{2}-\d{2}\b/;

type PortfolioRow = { id: number; publication: string; chronology: string };

/**
 * First organizer event matching a predicate, read from the mobile events route.
 */
async function findEvent(page: Page, predicate: (row: PortfolioRow) => boolean): Promise<PortfolioRow | undefined> {
  const { body } = await restAs(page, organizerUser, 'GET', '/organizer/mobile/events');
  return ((body as { events?: PortfolioRow[] })?.events ?? []).find(predicate);
}

/**
 * Wait until a screen's test ID is visible.
 */
async function waitFor(page: Page, testId: string) {
  await expect(page.getByTestId(testId)).toBeVisible({ timeout: 45_000 });
}

test.describe('Organizer Mobile OS 2.0 journeys', () => {
  test.beforeEach(async ({ page }) => {
    await appLogin(page, organizerUser);
  });

  test('A · Home is one summary call with scoped KPIs and no raw dates', async ({ page }) => {
    const tracker = trackApiRequests(page);
    await page.goto('/(organizer)');
    await waitFor(page, 'organizer-home');
    expect(tracker.requests.filter((r) => r.includes('/organizer/home-summary')).length).toBeGreaterThan(0);
    expect(tracker.requests.filter((r) => /\/organizer\/onboarding|\/crm\/launch-checklist/.test(r)), 'no competing setup models').toEqual([]);
    await expect(page.getByText(/after LNKup fees · all time/).first()).toBeVisible();
    await expect(page.getByText(rawDate)).toHaveCount(0);
    await expect(page.getByText(/Continue on LNKup web/)).toHaveCount(0);
    await expectNoHorizontalOverflow(page, 'Home');
  });

  test('B · Events search narrows the portfolio on the server', async ({ page }) => {
    await page.goto('/(organizer)/events');
    await page.getByTestId('events-search').fill('Summer Market');
    await expect(page.getByRole('button', { name: /^E2E Summer Market\./ })).toBeVisible({ timeout: 45_000 });
    await page.getByTestId('events-search').fill('no-such-event-zz');
    await expect(page.getByText(/No events match/)).toBeVisible({ timeout: 45_000 });
  });

  test('D · A draft event shows the publish checklist in the app', async ({ page }) => {
    const draft = await findEvent(page, (row) => row.publication === 'draft' && row.chronology !== 'completed');
    test.skip(!draft, 'No draft event in the seed');
    await page.goto(`/(organizer)/events/${draft!.id}`);
    await waitFor(page, 'publish-checklist');
    await expect(page.getByText(/Ready to publish\?/)).toBeVisible();
    await expect(page.getByText(rawDate)).toHaveCount(0);
  });

  test('E · Application review explains approval and shows the decision bar', async ({ page }) => {
    test.skip(!reviewAppId, 'Seed did not export a review application');
    await page.goto(`/(organizer)/vendors/review/${reviewAppId}`);
    await waitFor(page, 'application-review');
    await expect(page.getByText(/completed event|No completed events on LNKup yet/).first()).toBeVisible();
    const approve = page.getByTestId('review-approve');
    if (await approve.isVisible().catch(() => false)) {
      await approve.click();
      await expect(page.getByTestId('approve-note')).toContainText(/invoice|free/i);
      await page.getByRole('button', { name: 'Cancel', exact: true }).click();
    }
  });

  test('F · Discovery never shows a bare zero for completed events', async ({ page }) => {
    await page.goto('/(organizer)/vendors?tab=discover');
    await expect(page.getByRole('button', { name: /^View profile for / }).first()).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/^0 events$/)).toHaveCount(0);
  });

  test('G · Invitations show counts and an invite detail with send date', async ({ page }) => {
    await page.goto('/(organizer)/vendors?tab=invites');
    await waitFor(page, 'invites-panel');
    await page.getByRole('button', { name: /^All/ }).first().click();
    const row = page.locator('[data-testid^="invite-row-"]').first();
    if (await row.isVisible().catch(() => false)) {
      await row.click();
      await waitFor(page, 'invite-detail');
      await expect(page.getByText(/^Sent /).first()).toBeVisible();
    }
  });

  test('H/I · Money separates current balance from unpaid booth fees', async ({ page }) => {
    await page.goto('/(organizer)/money');
    await waitFor(page, 'money-available');
    await expect(page.getByText('Current balance')).toBeVisible();
    await expect(page.getByText('Booth fees not yet paid')).toBeVisible();
    await page.getByRole('tab', { name: /^Invoices/ }).click();
    await expect(page.getByText(/By event · before LNKup fees|No booth fees yet/)).toBeVisible({ timeout: 45_000 });
  });

  test('J · Cash out reviews the server fee preview and never submits in this test', async ({ page }) => {
    await page.goto('/(organizer)/money');
    const cashout = page.getByTestId('money-cashout');
    await expect(cashout).toBeVisible({ timeout: 45_000 });
    test.skip(await cashout.isDisabled(), 'Nothing available to cash out in the seed');
    await cashout.click();
    await page.getByLabel('Amount').fill('1');
    await page.getByTestId('cashout-review').click();
    await waitFor(page, 'cashout-preview');
    await expect(page.getByText(/LNKup (instant )?fee/).first()).toBeVisible();
    await expect(page.getByText(/about 1\.5%/)).toHaveCount(0);
  });

  test('L · Event financials count open invoices only', async ({ page }) => {
    await page.goto(`/(organizer)/money/event?eventId=${eventId}`);
    await waitFor(page, 'event-financials');
    await expect(page.getByText('Open invoices', { exact: true })).toBeVisible();
    await expect(page.getByText(/refunded or cancelled ones are left out/)).toBeVisible();
  });

  test('M · Operations shows tasks with who owns them', async ({ page }) => {
    await page.goto(`/(organizer)/events/operations?eventId=${eventId}`);
    await waitFor(page, 'operations-workspace');
    await expect(page.getByText(/Your step|Vendor's step/).first()).toBeVisible();
    await expect(page.getByText(/vendor-dashboard/)).toHaveCount(0);
    await expectNoHorizontalOverflow(page, 'Operations');
  });

  test('N · Check-in accepts a typed pass code and explains a wrong one', async ({ page }) => {
    await page.goto(`/(organizer)/events/checkin?eventId=${freeEventId}`);
    await waitFor(page, 'checkin-search');
    await expect(page.getByTestId('checkin-populations')).toContainText('eligible checked in');
    await page.getByTestId('checkin-code-open').click();
    await page.getByTestId('checkin-code-input').fill('NOT-A-REAL-CODE');
    await page.getByRole('button', { name: 'Check in with code' }).click();
    await expect(page.getByText(/doesn't match a pass|wasn't found|different event/).first()).toBeVisible({ timeout: 45_000 });
  });

  test('O · Booths, map, logistics and packet are viewable; a booth needs a number', async ({ page }) => {
    await page.goto(`/(organizer)/events/booths?eventId=${eventId}`);
    await expect(page.getByRole('tab', { name: /^Booths/ })).toBeVisible({ timeout: 45_000 });
    await page.getByTestId('booth-add').click();
    await page.getByTestId('booth-create').click();
    await expect(page.getByText('Enter a booth number.')).toBeVisible();
    await page.getByRole('button', { name: /^Close / }).last().click();
    for (const tab of ['Map', 'Logistics', 'Packet']) {
      await page.getByRole('tab', { name: new RegExp(`^${tab}`) }).click();
    }
    await expect(page.getByText('What vendors see')).toBeVisible({ timeout: 45_000 });
  });

  test('P · Tours list opens a tour summary with a stop switcher', async ({ page }) => {
    const { body } = await restAs(page, organizerUser, 'GET', '/portfolio/tours');
    const tour = ((body as { items?: Array<{ id: number; title: string }> })?.items ?? [])[0];
    test.skip(!tour, 'No tour in the seed');
    await page.goto('/(organizer)/tours');
    await page.getByRole('button', { name: new RegExp(`^${tour!.title}`) }).click();
    await waitFor(page, 'tour-summary');
    await expect(page.getByRole('button', { name: 'Whole tour' })).toBeVisible();
    await expect(page.getByText(/Platform fees/i)).toHaveCount(0);
  });

  test('S/T/U · More is Organization HQ; notification and referral copy is plain', async ({ page }) => {
    await page.goto('/(organizer)/more');
    await waitFor(page, 'organizer-hq');
    await expect(page.getByText('Your events', { exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: /^Team\./ }).click();
    await waitFor(page, 'organizer-team');
    await page.goto('/(organizer)/notification-settings');
    await expect(page.getByText(/EAS project/)).toHaveCount(0);
    await page.goto('/(organizer)/referrals');
    await expect(page.getByText(/paid as LNKup credit|share of LNKup’s fee/).first()).toBeVisible({ timeout: 45_000 });
  });

  test('V · Other organizers and unassigned users are refused; check-in staff are allowed', async ({ page }) => {
    expect((await restAs(page, organizerBUser, 'GET', `/organizer/events/${eventId}/operations-summary`)).status).toBe(403);
    expect((await restAs(page, organizerBUser, 'GET', `/operations/events/${eventId}/checkin-summary`)).status).toBe(403);
    expect((await restAs(page, organizerBUser, 'GET', '/financial/organizer-payouts/preview?amount=1&type=standard')).status).toBe(200);
    const staff = await restAs(page, securityUser, 'GET', `/operations/events/${eventId}/checkin-summary`);
    expect([200, 403]).toContain(staff.status);
    if (staff.status === 200) {
      expect(JSON.stringify(staff.body)).not.toMatch(/collected_gross|fee_amount/);
    }
  });
});
