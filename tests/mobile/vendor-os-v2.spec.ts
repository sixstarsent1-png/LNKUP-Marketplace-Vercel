import { test, expect } from '@playwright/test';
import { appLogin, restAs, trackApiRequests, vendorBUser, vendorUser } from './helpers/app-session';

type GroupedSearch = { grouped?: boolean; total_events?: number; total_options?: number; items?: Array<{ key: string; event_id: number; options: Array<{ opportunity_id: number }> }> };
type Booking = { application_id: number; opportunity_id: number; event_id: number; event_name: string; event_resolution?: string };
type Transaction = { id: number; status: string; payment_status: string; type: string };
type Receipt = { id: number; payment_status?: string };

test.describe('Vendor OS 2.0 journeys', () => {
  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'iphone-15', 'Journeys run on one device');
  });

  test('Journey B: grouped search returns each event once with every option', async ({ page }) => {
    const { body } = await restAs(page, vendorUser, 'GET', '/marketplace/search?group_by=event&per_page=48&include_external=false');
    const result = body as GroupedSearch;
    expect(result.grouped).toBe(true);
    const keys = (result.items ?? []).map((g) => g.key);
    expect(new Set(keys).size, 'no event appears twice').toBe(keys.length);
    const optionIds = (result.items ?? []).flatMap((g) => g.options.map((o) => o.opportunity_id));
    expect(new Set(optionIds).size, 'options keep their own identity').toBe(optionIds.length);
    expect(result.total_options ?? 0).toBeGreaterThanOrEqual(result.total_events ?? 0);

    const legacy = await restAs(page, vendorUser, 'GET', '/marketplace/search?per_page=5&include_external=false');
    expect((legacy.body as { grouped?: boolean }).grouped, 'old clients get the old shape').toBeUndefined();
  });

  test('Journey B: Explore renders one card per event and the same event count', async ({ page }) => {
    const { body } = await restAs(page, vendorUser, 'GET', '/marketplace/search?group_by=event&per_page=20&include_external=false&sort=recommended');
    const totalEvents = (body as GroupedSearch).total_events ?? 0;
    await appLogin(page, vendorUser);
    const api = trackApiRequests(page);
    await page.goto('/(vendor)/find');
    await expect(page.getByTestId('explore-event-card').first()).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(new RegExp(`^${totalEvents} events?`))).toBeVisible();
    expect(api.requests.some((r) => r.includes('group_by=event')), 'Explore asks for grouped results').toBe(true);
  });

  test('Journey F: bookings resolve their event and the single booking is owner-only', async ({ page }) => {
    const { body } = await restAs(page, vendorUser, 'GET', '/applications/vendor');
    const items = ((body as { items?: Booking[] }).items ?? []) as Booking[];
    expect(items.length).toBeGreaterThan(0);
    for (const item of items) {
      expect(item.event_name).not.toBe('Event details unavailable');
      expect(['live', 'opportunity', 'snapshot', 'unrecoverable']).toContain(item.event_resolution);
    }
    const first = items[0];
    const own = await restAs(page, vendorUser, 'GET', `/applications/vendor/${first.application_id}`);
    expect(own.status).toBe(200);
    expect(Array.isArray((own.body as { timeline?: unknown[] }).timeline)).toBe(true);
    const other = await restAs(page, vendorBUser, 'GET', `/applications/vendor/${first.application_id}`);
    expect(other.status, 'another vendor cannot read it').toBe(404);

    await appLogin(page, vendorUser);
    await page.goto(`/booking/${first.application_id}`);
    await expect(page.getByTestId('booking-timeline')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Event details unavailable')).toHaveCount(0);
  });

  test('Journey H: wallet history is the ledger, not reminders', async ({ page }) => {
    await appLogin(page, vendorUser);
    await page.goto('/(vendor)/wallet?tab=history');
    await expect(page.getByTestId('wallet-summary')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/Payment past due/)).toHaveCount(0);
    await expect(page.getByText(/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/)).toHaveCount(0);
  });

  test('Journey H/I: transaction and receipt details are owner-only and show real status', async ({ page }) => {
    const txns = (await restAs(page, vendorUser, 'GET', '/financial/transactions?role=vendor&limit=50')).body as Transaction[];
    const paid = (txns ?? []).find((t) => t.status === 'paid' || t.status === 'completed');
    test.skip(!paid, 'Seed has no paid ledger row');
    const other = await restAs(page, vendorBUser, 'GET', `/financial/transactions/${paid!.id}`);
    expect(other.status, 'another vendor cannot read it').toBe(404);

    const receipts = (await restAs(page, vendorUser, 'GET', '/financial/receipts?role=vendor')).body as Receipt[];
    for (const r of receipts ?? []) expect(r.payment_status).toBeTruthy();

    await appLogin(page, vendorUser);
    await page.goto(`/wallet/transaction/${paid!.id}`);
    await expect(page.getByTestId('transaction-detail')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/^Paid$/).first()).toBeVisible();
    if (receipts?.[0]) {
      await page.goto(`/wallet/receipt/${receipts[0].id}`);
      await expect(page.getByTestId('receipt-detail')).toBeVisible({ timeout: 45_000 });
      await expect(page.getByText(/unknown/i)).toHaveCount(0);
    }
  });

  test('Journey N: an opportunity link opens its event with that option selected', async ({ page }) => {
    const { body } = await restAs(page, vendorUser, 'GET', '/marketplace/search?group_by=event&per_page=5&include_external=false');
    const group = (body as GroupedSearch).items?.find((g) => g.event_id > 0);
    test.skip(!group, 'No grouped events in seed');
    const optionId = group!.options[0].opportunity_id;
    await appLogin(page, vendorUser);
    await page.goto(`/opportunity/${optionId}`);
    await expect(page).toHaveURL(new RegExp(`/event/${group!.event_id}\\?option=${optionId}`), { timeout: 45_000 });
    await expect(page.getByTestId('vendor-event-detail')).toBeVisible();
    await expect(page.getByRole('radio', { checked: true })).toHaveCount(1);
  });

  test('Home leads with actions tied to events and never shows a stale pay prompt for a past event', async ({ page }) => {
    await appLogin(page, vendorUser);
    await page.goto('/(vendor)');
    await expect(page.getByTestId('vendor-home-search')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/Encountered two children with the same key/)).toHaveCount(0);
    const items = ((await restAs(page, vendorUser, 'GET', '/applications/vendor')).body as { items?: Array<{ event_name: string; payment_context?: { past_event_invoice?: boolean } }> }).items ?? [];
    for (const past of items.filter((i) => i.payment_context?.past_event_invoice)) {
      await expect(page.getByRole('button', { name: new RegExp(`to confirm your booth\\. ${past.event_name}`) })).toHaveCount(0);
    }
  });
});
