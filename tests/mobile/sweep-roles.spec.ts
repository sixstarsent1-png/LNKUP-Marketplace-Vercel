import { test, expect } from '@playwright/test';
import { organizerUser, restAs, vendorUser } from './helpers/app-session';

const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '56';
const freeAppId = process.env.PLAYWRIGHT_FREE_APPLICATION_ID || '66';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER || 'e2e_org_b';

/** Routes the mobile app calls for one role; the other role must be refused (401/403), never served. */
const VENDOR_ONLY: Array<{ method: 'GET' | 'POST'; path: string }> = [
  { method: 'GET', path: '/applications/vendor' },
  { method: 'GET', path: '/vendor/profile' },
  { method: 'GET', path: '/documents/vendor' },
  { method: 'GET', path: '/operations/vendor-home' },
  { method: 'GET', path: '/marketplace/saved-searches' },
];

const ORGANIZER_ONLY: Array<{ method: 'GET' | 'POST'; path: string }> = [
  { method: 'GET', path: '/applications/organizer' },
  { method: 'GET', path: '/financial/organizer-balances' },
  { method: 'GET', path: '/financial/organizer-payouts' },
  { method: 'GET', path: `/crm/events/${eventId}/roster` },
  { method: 'GET', path: '/crm/events/invite-options?include_all=1' },
  { method: 'GET', path: '/organizer/profile' },
];

test.describe('Role permissions behind every tab', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'iphone-15', 'Server checks; viewport independent');
  });

  for (const route of ORGANIZER_ONLY) {
    test(`a vendor is refused ${route.method} ${route.path}`, async ({ page }) => {
      const { status } = await restAs(page, vendorUser, route.method, route.path);
      expect([401, 403]).toContain(status);
    });
  }

  for (const route of VENDOR_ONLY) {
    test(`an organizer-only account is refused ${route.method} ${route.path}`, async ({ page }) => {
      const { status } = await restAs(page, organizerB, route.method, route.path);
      expect([401, 403]).toContain(status);
    });
  }

  test("another organizer cannot read or change this organizer's event", async ({ page }) => {
    expect((await restAs(page, organizerB, 'GET', `/crm/events/${eventId}/roster`)).status).toBe(403);
    expect((await restAs(page, organizerB, 'POST', `/operations/applications/${freeAppId}/checkin`, { action: 'checked_in' })).status).toBe(403);
    const opportunityId = process.env.PLAYWRIGHT_FRESH_OPPORTUNITY_ID || '59';
    expect((await restAs(page, organizerB, 'POST', `/organizer/opportunities/${opportunityId}/status`, { status: 'closed' })).status).toBe(403);
    expect((await restAs(page, organizerB, 'GET', `/organizer/events/${eventId}/operations-summary`)).status).toBe(403);
    expect((await restAs(page, organizerB, 'GET', `/operations/events/${eventId}/checkin-summary`)).status).toBe(403);
  });

  test('the owner can read their own event-day roster', async ({ page }) => {
    expect((await restAs(page, organizerUser, 'GET', `/crm/events/${eventId}/roster`)).status).toBe(200);
  });
});
