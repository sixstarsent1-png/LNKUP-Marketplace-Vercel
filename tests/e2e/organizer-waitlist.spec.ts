import { test, expect } from '@playwright/test';
import { loginAs, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const reviewer = process.env.PLAYWRIGHT_REVIEWER_USER ?? '';
const opportunity = process.env.PLAYWRIGHT_WAITLIST_OPPORTUNITY_ID ?? '';
const bookedApp = process.env.PLAYWRIGHT_WAITLIST_BOOKED_APP_ID ?? '';
const waitlistedApp = process.env.PLAYWRIGHT_WAITLIST_APP_ID ?? '';

test.describe('Waitlist offers', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!opportunity || !bookedApp || !waitlistedApp, 'Run scripts/e2e/seed-organizer-os.php on (it resets this fixture)');

  let owner: WpSession;
  let staff: WpSession;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    owner = await loginAs(browser, organizer, password, '/organizer-dashboard/');
    staff = await loginAs(browser, reviewer, password, '/organizer-dashboard/');
  });

  test.afterAll(async () => {
    await Promise.all([owner, staff].map((s) => s?.context.close()));
  });

  test('waitlist lists the vendor in queue order with live inventory', async () => {
    const body = await (await lnkupRest(owner, 'GET', `/opportunities/${opportunity}/waitlist`)).json();
    expect(body.items.map((row: { id: number }) => String(row.id))).toContain(waitlistedApp);
    expect(body.inventory.capacity).toBe(1);
    expect(body.inventory.available).toBe(0);
  });

  test('an offer is refused while the only spot is held', async () => {
    const response = await lnkupRest(owner, 'POST', `/applications/${waitlistedApp}/waitlist-offer`, { hours: 24 });
    expect(response.status()).toBe(409);
  });

  test('a reviewer on another event cannot make offers', async () => {
    const response = await lnkupRest(staff, 'POST', `/applications/${waitlistedApp}/waitlist-offer`, { hours: 24 });
    expect(response.status()).toBe(403);
  });

  test('when the held spot is released the waitlisted vendor can be offered it', async () => {
    const decline = await lnkupRest(owner, 'POST', `/applications/${bookedApp}/decision`, { action: 'reject', rejection_reason: 'E2E release' });
    expect(decline.status()).toBe(200);
    const offer = await lnkupRest(owner, 'POST', `/applications/${waitlistedApp}/waitlist-offer`, { hours: 24 });
    expect(offer.status()).toBe(200);
    const ws = await offer.json();
    expect(ws.status).toBe('payment_required');
    expect(ws.waitlist_offer?.expires_at).toBeTruthy();
    expect(ws.actions).toContain('revoke_offer');
  });

  test('a second offer for the same vendor is refused', async () => {
    expect((await lnkupRest(owner, 'POST', `/applications/${waitlistedApp}/waitlist-offer`, { hours: 24 })).status()).toBe(409);
  });

  test('withdrawing the offer returns the vendor to the waitlist', async () => {
    const response = await lnkupRest(owner, 'DELETE', `/applications/${waitlistedApp}/waitlist-offer`);
    expect(response.status()).toBe(200);
    const ws = await response.json();
    expect(ws.status).toBe('waitlisted');
    expect(ws.waitlist_offer).toBeNull();
    expect(ws.waitlist_position).toBeGreaterThan(0);
  });
});
