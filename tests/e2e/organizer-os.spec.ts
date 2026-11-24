import AxeBuilder from '@axe-core/playwright';
import { test, expect, type Page } from '@playwright/test';
import { loginAs, loginPage, lnkupRest, type WpSession } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';
const organizerBUser = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? '';
const password = process.env.PLAYWRIGHT_PASSWORD ?? organizerPass;
const workspaceEventId = process.env.PLAYWRIGHT_WAITLIST_EVENT_ID ?? '';
const workspaceOpportunityId = process.env.PLAYWRIGHT_WAITLIST_OPPORTUNITY_ID ?? '';
const paidEventId = process.env.PLAYWRIGHT_FREE_EVENT_ID ?? '';
const paidApplicationId = process.env.PLAYWRIGHT_FREE_APPLICATION_ID ?? '';
const opsEventId = process.env.PLAYWRIGHT_OPS_EVENT_ID ?? '';
const tourId = process.env.PLAYWRIGHT_TOUR_ID ?? '';

const dashboard = '/organizer-dashboard/';
const E2E_BOOTH = 'E2E-OS-1';

interface PortfolioEvent {
  id: number;
  title: string;
  place: string;
  chronology: string;
  applications: { pending: number; held: number; confirmed: number };
  capacity: { total: number; confirmed: number; held: number; open: number };
}

/**
 * Read the Home metric tile value for a `data-stat-key`.
 */
async function homeMetric(page: Page, key: string): Promise<string> {
  return (await page.locator(`[data-stat-key="${key}"] .vos-metric__value`).innerText()).trim();
}

/**
 * Fetch the organizer's portfolio rows (the same contract Home and Events render from).
 */
async function portfolio(session: WpSession): Promise<PortfolioEvent[]> {
  const res = await lnkupRest(session, 'GET', '/organizer/portfolio');
  expect(res.ok(), 'GET /organizer/portfolio').toBeTruthy();
  return ((await res.json()) as { events: PortfolioEvent[] }).events;
}

/**
 * Report horizontal overflow on the current page in CSS pixels.
 */
async function horizontalOverflow(page: Page): Promise<number> {
  return page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
}

test.describe('Organizer Experience OS 2.0', () => {
  test.skip(!organizerUser || !workspaceEventId, 'Run scripts/e2e/seed.php');

  let session: WpSession;

  test.beforeAll(async ({ browser }) => {
    session = await loginAs(browser, organizerUser, organizerPass, dashboard);
  });

  test.afterAll(async () => {
    await session?.context.close();
  });

  test.describe('signed in as the organizer', () => {
    test.beforeEach(async ({ page }) => {
      await loginPage(page, organizerUser, organizerPass);
    });

    test('Home metrics equal the portfolio contract and link to filtered views', async ({ page }) => {
      const events = await portfolio(session);
      const active = events.filter((e) => e.chronology !== 'completed');
      const sum = (pick: (e: PortfolioEvent) => number, rows = events) => rows.reduce((n, e) => n + pick(e), 0);

      await page.goto(dashboard);
      expect(await homeMetric(page, 'upcoming')).toBe(String(active.length));
      expect(await homeMetric(page, 'pending')).toBe(String(sum((e) => e.applications.pending)));
      expect(await homeMetric(page, 'unpaid')).toBe(String(sum((e) => e.applications.held)));
      expect(await homeMetric(page, 'confirmed')).toBe(String(sum((e) => e.applications.confirmed)));
      expect(await homeMetric(page, 'open')).toBe(String(sum((e) => e.capacity.open, active)));

      await expect(page.locator('[data-stat-key="pending"]')).toHaveAttribute('href', /view=applications.*filter=pending/);
      await expect(page.locator('[data-stat-key="unpaid"]')).toHaveAttribute('href', /view=applications.*filter=approved/);
      await expect(page.locator('[data-stat-key="upcoming"]')).toHaveAttribute('href', /view=events.*chronology=active/);
      await expect(page.locator('[data-stat-key="collected"]')).toHaveAttribute('href', /view=financial/);
    });

    test('Events portfolio filters by publication and switches to grid', async ({ page }) => {
      await page.goto(`${dashboard}?view=events`);
      await expect(page.getByTestId('lnkup-events-filters')).toBeVisible();
      await expect(page.getByTestId('lnkup-events-table')).toBeVisible();

      await page.goto(`${dashboard}?view=events&publication=draft`);
      const count = page.getByTestId('lnkup-events-count');
      await expect(count).toHaveText(/Showing \d+ of \d+ events/);
      const shown = Number((await count.innerText()).match(/Showing (\d+)/)?.[1] ?? -1);
      const drafts = (await portfolio(session)).filter((e) => (e as unknown as { publication: string }).publication === 'draft');
      expect(shown).toBe(drafts.length);

      await page.goto(`${dashboard}?view=events`);
      await page.getByRole('button', { name: /^grid$/i }).click();
      await expect(page.getByTestId('lnkup-events-grid')).toBeVisible();
      await page.getByRole('button', { name: /^table$/i }).click();
      await expect(page.getByTestId('lnkup-events-table')).toBeVisible();
    });

    test('Duplicate event needs a location and never inherits the source city', async () => {
      const missing = await lnkupRest(session, 'POST', `/organizer/events/${workspaceEventId}/duplicate`, {
        event_date: '2031-05-17',
      });
      expect(missing.ok()).toBeFalsy();
      expect(JSON.stringify(await missing.json())).toMatch(/city and state/i);

      const copy = await lnkupRest(session, 'POST', `/organizer/events/${workspaceEventId}/duplicate`, {
        event_date: '2031-05-17',
        event_name: 'E2E OS Greensboro copy',
        city: 'Greensboro',
        state: 'NC',
        client_token: 'e2e-organizer-os-duplicate-greensboro',
      });
      expect(copy.ok(), 'duplicate with a new location').toBeTruthy();
      const { event_id: copyId } = (await copy.json()) as { event_id: number };
      const summary = await lnkupRest(session, 'GET', `/organizer/events/${copyId}/summary`);
      expect(((await summary.json()) as { place: string }).place).toBe('Greensboro, NC');
    });

    test('Inventory drawer refuses capacity below held plus confirmed', async ({ page }) => {
      await page.goto(`${dashboard}?event=${workspaceEventId}&tab=inventory`);
      await expect(page.getByTestId('lnkup-inventory-table')).toBeVisible();
      await page.getByTestId('lnkup-inventory-table').getByRole('button', { name: 'Edit' }).first().click();
      const drawer = page.getByTestId('lnkup-inventory-drawer').filter({ visible: true });
      await expect(drawer).toBeVisible();
      await drawer.getByLabel('Total spaces').fill('0');
      await drawer.getByRole('button', { name: 'Save changes' }).click();
      await expect(drawer.getByRole('alert')).toContainText(/can’t be lower than/);

      const rejected = await lnkupRest(session, 'PATCH', `/organizer/opportunities/${workspaceOpportunityId}/pricing`, { spots_total: 0 });
      expect(rejected.ok()).toBeFalsy();
    });

    test('Application drawer opens from the shared table and steps between applications', async ({ page }) => {
      await page.goto(`${dashboard}?view=applications`);
      const openers = page.getByRole('button', { name: /^Review / });
      test.skip((await openers.count()) === 0, 'No applications for this organizer');
      await openers.first().click();
      const drawer = page.locator('#oos-app-drawer');
      await expect(drawer).toBeVisible();
      const position = drawer.locator('[data-oos-app-pos]');
      await expect(position).toHaveText(/1 of \d+/);
      if ((await openers.count()) > 1) {
        await drawer.getByTestId('lnkup-app-next').click();
        await expect(position).toHaveText(/2 of \d+/);
        await drawer.getByTestId('lnkup-app-prev').click();
        await expect(position).toHaveText(/1 of \d+/);
      }
      await page.keyboard.press('Escape');
      await expect(drawer).toBeHidden();
    });

    test('Map Builder starts with explicit choices when no map exists', async ({ page }) => {
      await page.goto(`${dashboard}?event=${workspaceEventId}&ops=1`);
      await page.getByRole('navigation', { name: 'Operations sections' }).getByRole('button', { name: 'Maps' }).click();
      await page.getByRole('tab', { name: 'Map Builder' }).click();
      const start = page.getByTestId('lnkup-map-start');
      const editor = page.locator('#lnkup-ops-map-builder-panel canvas, #lnkup-ops-map-builder-panel svg').first();
      await expect(start.or(editor)).toBeVisible();
      if (await start.isVisible()) {
        await expect(start.getByRole('button', { name: 'Use the booth list' })).toBeVisible();
        await expect(start.getByRole('button', { name: 'Build a layout' })).toBeVisible();
      }
    });

    test('Money shows the reconciliation flow and previews cash out without submitting', async ({ page }) => {
      const cashoutPosts: string[] = [];
      page.on('request', (req) => {
        if (req.method() === 'POST' && /cashout/i.test(req.url())) cashoutPosts.push(req.url());
      });
      await page.goto(`${dashboard}?view=financial`);
      const flow = page.getByTestId('lnkup-money-flow');
      await expect(flow).toBeVisible();
      await expect(flow).toContainText(/collected/i);
      await expect(page.getByText(/settlement: destination|stripe connect active/i)).toHaveCount(0);

      await page.getByTestId('lnkup-money-tabs').getByRole('tab', { name: 'Cash out' }).click();
      const panel = page.locator('#lnkup-fin-org-cashout');
      await expect(panel).toBeVisible();
      await expect(panel.locator('.lnkup-skeleton, .lnkup-loading')).toHaveCount(0, { timeout: 15_000 });
      await expect(panel).not.toBeEmpty();
      expect(cashoutPosts).toEqual([]);
    });

    test('Check-in screen filters vendors and rejects an unknown pass code', async ({ page }) => {
      await page.goto(`${dashboard}?event=${opsEventId}&scan=1`);
      await expect(page.getByTestId('lnkup-checkin-counts')).toBeVisible();
      await expect(page.getByTestId('lnkup-qr-recent').locator('.lnkup-skeleton')).toHaveCount(0, { timeout: 15_000 });

      const rows = page.getByTestId('lnkup-checkin-lookup').getByRole('listitem');
      if ((await rows.count()) > 0) {
        await page.getByTestId('lnkup-checkin-search').fill('zz-no-vendor-matches-this');
        await expect(rows.filter({ visible: true })).toHaveCount(0);
        await page.getByTestId('lnkup-checkin-search').fill('');
      }

      await page.getByLabel('Pass code').fill('NOT-A-REAL-PASS');
      await page.getByRole('button', { name: 'Check in', exact: true }).first().click();
      await expect(page.locator('#lnkup-qr-result')).not.toBeEmpty();
      await expect(page.locator('#lnkup-qr-result')).not.toContainText(/checked in/i);
    });

    test('Settings hub summarizes profile, payouts, notifications and team roles', async ({ page }) => {
      await page.goto(`${dashboard}?view=settings`);
      await expect(page.getByTestId('lnkup-org-settings')).toBeVisible();
      await expect(page.getByTestId('lnkup-org-settings-profile')).toContainText(/profile/i);
      await expect(page.getByTestId('lnkup-org-settings-payments')).toBeVisible();
      await expect(page.getByTestId('lnkup-org-settings-team').getByRole('table')).toContainText('Application Reviewer');
      await expect(page.locator('.lnkup-dashboard__nav-link.is-active[data-view="settings"]')).toHaveCount(1);
    });

    for (const route of [`${dashboard}`, `${dashboard}?view=events`, `${dashboard}?view=settings`]) {
      test(`axe finds no serious or critical issues on ${route}`, async ({ page }) => {
        await page.goto(route);
        const results = await new AxeBuilder({ page }).include('.lnkup-dashboard__main, main').analyze();
        const blocking = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
        expect(blocking.map((v) => `${v.id}: ${v.nodes.length}`)).toEqual([]);
      });
    }

    for (const width of [1440, 1024, 390]) {
      test(`no horizontal overflow at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        for (const route of [
          dashboard,
          `${dashboard}?view=events`,
          `${dashboard}?event=${workspaceEventId}`,
          `${dashboard}?event=${workspaceEventId}&tab=inventory`,
          `${dashboard}?view=applications`,
          `${dashboard}?view=financial`,
          `${dashboard}?view=settings`,
        ]) {
          await page.goto(route);
          expect(await horizontalOverflow(page), route).toBeLessThanOrEqual(1);
        }
      });
    }
  });

  test('Tour attach keeps stop IDs and application counts', async () => {
    test.skip(!tourId, 'Run scripts/e2e/seed-portfolio.php');
    type Stop = { event_id: number; application_count: unknown };
    const readStops = async (): Promise<Stop[]> => {
      const res = await lnkupRest(session, 'GET', `/portfolio/tours/${tourId}`);
      expect(res.ok()).toBeTruthy();
      const body = (await res.json()) as { stops?: Stop[]; tour?: { stops?: Stop[] } };
      return (body.stops ?? body.tour?.stops ?? []).map((s) => ({ event_id: s.event_id, application_count: s.application_count }));
    };
    const before = await readStops();
    test.skip(before.length === 0, 'Tour has no stops');

    const attach = await lnkupRest(session, 'POST', `/portfolio/tours/${tourId}/attach`, { event_ids: before.map((s) => s.event_id) });
    expect(attach.ok()).toBeTruthy();
    const result = (await attach.json()) as { attached: number[]; skipped: Array<{ reason: string }> };
    expect(result.attached).toEqual([]);
    expect(result.skipped.every((s) => s.reason === 'already_in_tour')).toBeTruthy();
    expect(await readStops()).toEqual(before);
  });

  test('Booth assigned in Operations appears on the vendor’s event home', async ({ browser }) => {
    test.skip(!paidApplicationId || !paidEventId || !vendorUser, 'Run scripts/e2e/seed.php');
    const boothsRes = await lnkupRest(session, 'GET', `/operations/events/${paidEventId}/booths`);
    expect(boothsRes.ok()).toBeTruthy();
    const { items: booths = [] } = (await boothsRes.json()) as { items?: Array<{ id: number; booth_number: string }> };
    let boothId = booths.find((b) => b.booth_number === E2E_BOOTH)?.id;
    if (!boothId) {
      const created = await lnkupRest(session, 'POST', `/operations/events/${paidEventId}/booths`, { booth_number: E2E_BOOTH, booth_type: 'standard' });
      expect(created.ok(), 'create E2E booth').toBeTruthy();
      boothId = ((await created.json()) as { id: number }).id;
    }

    const assigned = await lnkupRest(session, 'POST', `/operations/events/${paidEventId}/booths/${boothId}/assign`, { application_id: Number(paidApplicationId) });
    expect(assigned.ok(), 'assign booth').toBeTruthy();

    const vendor = await loginAs(browser, vendorUser, vendorPass || password, '/vendor-dashboard/');
    try {
      const home = await lnkupRest(vendor, 'GET', `/operations/vendor-home?application_id=${paidApplicationId}`);
      expect(home.ok()).toBeTruthy();
      const body = (await home.json()) as { booth?: { booth_number?: string } | null };
      expect(body.booth?.booth_number).toBe(E2E_BOOTH);
    } finally {
      await vendor.context.close();
      await lnkupRest(session, 'POST', `/operations/events/${paidEventId}/booths/${boothId}/unassign`, {});
    }
  });

  test('Another organizer cannot read this event or attach to this tour', async ({ browser }) => {
    test.skip(!organizerBUser, 'Run scripts/e2e/seed.php');
    const other = await loginAs(browser, organizerBUser, password, dashboard);
    try {
      const summary = await lnkupRest(other, 'GET', `/organizer/events/${workspaceEventId}/summary`);
      expect([401, 403]).toContain(summary.status());
      if (tourId) {
        const attach = await lnkupRest(other, 'POST', `/portfolio/tours/${tourId}/attach`, { event_ids: [Number(workspaceEventId)] });
        expect([401, 403, 404]).toContain(attach.status());
      }
      const pricing = await lnkupRest(other, 'PATCH', `/organizer/opportunities/${workspaceOpportunityId}/pricing`, { spots_total: 50 });
      expect(pricing.ok()).toBeFalsy();
    } finally {
      await other.context.close();
    }
  });
});
