import { test, expect } from '@playwright/test';
import { loginAs, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const vendor = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const eventA = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';
const passCode = process.env.PLAYWRIGHT_FREE_PASS_CODE ?? '';
const freeEvent = process.env.PLAYWRIGHT_FREE_EVENT_ID ?? '';

test.describe('Organizer integrity guards', () => {
  test.skip(!organizer || !organizerB || !vendor || !eventA, 'Run scripts/e2e/seed.php to create organizer fixtures');

  let owner: WpSession;
  let other: WpSession;
  let vendorSession: WpSession;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    owner = await loginAs(browser, organizer, password, '/organizer-dashboard/');
    other = await loginAs(browser, organizerB, password, '/organizer-dashboard/');
    vendorSession = await loginAs(browser, vendor, password, '/vendor-dashboard/');
  });

  test.afterAll(async () => {
    await Promise.all([owner, other, vendorSession].map((s) => s?.context.close()));
  });

  test('QR scan requires the event being worked', async () => {
    expect((await lnkupRest(owner, 'POST', '/operations/qr/scan', { token: 'LNKUP-NOPE' })).status()).toBe(400);
  });

  test('QR scan is refused for vendors and other organizers', async () => {
    expect((await lnkupRest(vendorSession, 'POST', '/operations/qr/scan', { token: passCode || 'LNKUP-NOPE', event_id: Number(freeEvent || eventA) })).status()).toBe(403);
    expect((await lnkupRest(other, 'POST', '/operations/qr/scan', { token: passCode || 'LNKUP-NOPE', event_id: Number(freeEvent || eventA) })).status()).toBe(403);
  });

  test('a pass for another event reads as not valid for this event', async () => {
    test.skip(!passCode || !freeEvent || freeEvent === eventA, 'Needs the free-event pass from seed.php');
    const response = await lnkupRest(owner, 'POST', '/operations/qr/scan', { token: passCode, event_id: Number(eventA) });
    expect(response.status()).toBe(404);
  });

  test('broadcast preview counts only the chosen category audience', async () => {
    const all = await (await lnkupRest(owner, 'GET', `/operations/events/${eventA}/broadcasts/preview?audience=all`)).json();
    const trucks = await (await lnkupRest(owner, 'GET', `/operations/events/${eventA}/broadcasts/preview?audience=food_trucks`)).json();
    expect(trucks.recipient_count).toBeLessThanOrEqual(all.recipient_count);
    expect((await lnkupRest(other, 'GET', `/operations/events/${eventA}/broadcasts/preview?audience=all`)).status()).toBe(403);
  });

  test('editing a booth without a vendor keeps its assignment', async () => {
    const booths = await (await lnkupRest(owner, 'GET', `/operations/events/${eventA}/booths`)).json();
    const assigned = (booths.items as Array<{ id: number; booth_number: string; application_id: number }>).find((b) => b.application_id > 0);
    test.skip(!assigned, 'No assigned booth on the seeded event');
    const save = await lnkupRest(owner, 'POST', `/operations/events/${eventA}/booths`, { id: assigned!.id, booth_number: assigned!.booth_number, notes: 'E2E edit' });
    expect(save.status()).toBe(200);
    const after = await (await lnkupRest(owner, 'GET', `/operations/events/${eventA}/booths`)).json();
    expect(after.items.find((b: { id: number }) => b.id === assigned!.id).application_id).toBe(assigned!.application_id);
  });
});
