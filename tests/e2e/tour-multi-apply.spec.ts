import { test, expect, type APIResponse } from '@playwright/test';
import { loginAs, lnkupRest, type WpSession } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_B_USER ?? '';
const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const tourId = process.env.PLAYWRIGHT_TOUR_ID ?? '';

interface StopResult {
  opportunity_id: number;
  result: string;
  application_id?: number;
}

/**
 * POST a multi-stop application with an Idempotency-Key header.
 */
function applyToTour(session: WpSession, body: Record<string, unknown>, key: string): Promise<APIResponse> {
  return session.request.fetch(`/wp-json/lnkup/v1/tours/${tourId}/apply`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': session.nonce, 'Content-Type': 'application/json', 'Idempotency-Key': key },
    data: body,
  });
}

test.describe('Multi-stop tour application', () => {
  test.skip(!vendorUser || !password || !tourId, 'Run scripts/e2e/seed.php');
  test.describe.configure({ mode: 'serial' });

  let vendor: WpSession;
  let selections: Array<{ event_id: number; opportunity_id: number }> = [];
  const groupId = `e2e-group-${Date.now()}`;

  test.beforeAll(async ({ browser }) => {
    vendor = await loginAs(browser, vendorUser, password, '/opportunities/');
    const apps = await lnkupRest(vendor, 'GET', '/applications/vendor');
    const items = (await apps.json()).items as Array<{ application_id: number; group_id: string; status: string }>;
    for (const app of items.filter((a) => a.group_id.startsWith('e2e-group-') && a.status === 'submitted')) {
      await lnkupRest(vendor, 'POST', `/applications/${app.application_id}/withdraw`, {});
    }
  });

  test.afterAll(async () => {
    await vendor?.context.close();
  });

  test('apply context lists every stop with a server price estimate', async () => {
    const res = await lnkupRest(vendor, 'GET', `/tours/${tourId}/apply-context`);
    test.skip(res.status() === 404, 'tour_multi_apply_enabled is off in this environment');
    expect(res.status()).toBe(200);
    const body = await res.json();
    expect(body.tour.stops.length).toBeGreaterThanOrEqual(3);
    for (const stop of body.tour.stops) {
      const opp = stop.opportunities[0];
      expect(opp.quote.is_estimate).toBe(true);
      if (opp.can_apply) {
        selections.push({ event_id: stop.event_id, opportunity_id: opp.opportunity_id });
      }
    }
    expect(selections.length).toBeGreaterThanOrEqual(3);
  });

  test('one submit creates one independent application per stop', async () => {
    test.skip(selections.length === 0, 'apply context unavailable');
    const res = await applyToTour(vendor, { group_id: groupId, accepted_terms: true, selections, shared: { application_message: 'On tour' } }, 'tour-key-000001');
    expect(res.status()).toBe(200);
    const body = await res.json();
    const results = body.results as StopResult[];
    expect(results.every((r) => r.result === 'submitted')).toBe(true);
    expect(new Set(results.map((r) => r.application_id)).size).toBe(selections.length);
  });

  test('retrying the same group creates no duplicates', async () => {
    test.skip(selections.length === 0, 'apply context unavailable');
    const res = await applyToTour(vendor, { group_id: groupId, accepted_terms: true, selections }, 'tour-key-000002');
    const body = await res.json();
    expect(body.summary.submitted).toBe(0);
    expect(body.summary.already).toBe(selections.length);

    const apps = await lnkupRest(vendor, 'GET', '/applications/vendor');
    const items = (await apps.json()).items as Array<{ group_id: string }>;
    expect(items.filter((a) => a.group_id === groupId)).toHaveLength(selections.length);
  });

  test('a forged slot from outside the tour is refused per stop', async () => {
    test.skip(selections.length === 0, 'apply context unavailable');
    const res = await applyToTour(
      vendor,
      { group_id: `${groupId}-x`, accepted_terms: true, selections: [{ event_id: selections[0].event_id, opportunity_id: 999999 }] },
      'tour-key-000003',
    );
    const body = await res.json();
    expect((body.results as StopResult[])[0].result).toBe('ineligible');
  });
});
