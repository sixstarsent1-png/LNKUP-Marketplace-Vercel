import { test, expect, type Page } from '@playwright/test';
import { loginAs, loginPage, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? password;
const orgA = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const orgB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const orgPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? password;
const eventA = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';

const VENDOR_LANDING = '/vendor-dashboard/';
const ORGANIZER_LANDING = '/organizer-dashboard/';

interface RecommendationsBody {
  enabled: boolean;
  items: Array<{ opportunity_id?: number; match?: Record<string, unknown> }>;
}

/**
 * Wait until a dashboard panel leaves its skeleton state, so no spinner is left running.
 */
async function expectSettled(page: Page, testId: string) {
  const panel = page.getByTestId(testId);
  await expect(panel).toBeVisible();
  await expect(panel.locator('.lnkup-crm-skeleton')).toHaveCount(0, { timeout: 45_000 });
}

test.describe('Matching & discovery API contracts', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!vendorUser || !orgA || !orgB || !eventA, 'Run scripts/e2e/seed.php to create fixtures');

  const sessions: { vendor?: WpSession; orgA?: WpSession; orgB?: WpSession } = {};

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    sessions.vendor = await loginAs(browser, vendorUser, vendorPass, VENDOR_LANDING);
    sessions.orgA = await loginAs(browser, orgA, orgPass, ORGANIZER_LANDING);
    sessions.orgB = await loginAs(browser, orgB, orgPass, ORGANIZER_LANDING);
  });

  test.afterAll(async () => {
    await Promise.all(Object.values(sessions).map((s) => s?.context.close()));
  });

  test('logged-out visitors cannot read vendor recommendations', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/match/vendor/recommendations');
    expect(response.status()).toBe(401);
  });

  test('vendor recommendations return JSON with bands, never raw scores', async () => {
    const response = await lnkupRest(sessions.vendor!, 'GET', '/match/vendor/recommendations?section=recommended');
    expect(response.ok()).toBeTruthy();
    expect(response.headers()['content-type']).toContain('application/json');
    const body = (await response.json()) as RecommendationsBody;
    expect(typeof body.enabled).toBe('boolean');
    for (const item of body.items) {
      expect(item.match).toBeDefined();
      expect(item.match).not.toHaveProperty('score');
      expect(['strong', 'good', 'possible']).toContain(item.match?.band);
    }
  });

  test('unknown recommendation sections are rejected', async () => {
    const response = await lnkupRest(sessions.vendor!, 'GET', '/match/vendor/recommendations?section=everything');
    expect(response.status()).toBe(400);
  });

  test('vendor matching preferences round-trip, including discovery opt-out', async () => {
    const before = await lnkupRest(sessions.vendor!, 'GET', '/match/vendor/preferences');
    expect(before.ok()).toBeTruthy();
    const original = (await before.json()) as { preferences?: { discoverable: boolean; available_months: number[] } };
    test.skip(!original.preferences, 'Seed vendor has no profile');

    const saved = await lnkupRest(sessions.vendor!, 'POST', '/match/vendor/preferences', {
      discoverable: false,
      available_months: [5, 6],
    });
    expect(saved.ok()).toBeTruthy();
    const body = (await saved.json()) as { preferences: { discoverable: boolean; available_months: number[] } };
    expect(body.preferences.discoverable).toBe(false);
    expect(body.preferences.available_months).toEqual([5, 6]);

    const restore = await lnkupRest(sessions.vendor!, 'POST', '/match/vendor/preferences', {
      discoverable: original.preferences!.discoverable,
      available_months: original.preferences!.available_months,
    });
    expect(restore.ok()).toBeTruthy();
  });

  test('vendors cannot use organizer recruitment routes', async () => {
    const response = await lnkupRest(sessions.vendor!, 'GET', `/match/organizer/events/${eventA}/vendors`);
    expect(response.status()).toBe(403);
  });

  test('another organizer cannot list candidates or recruit for this event', async () => {
    const list = await lnkupRest(sessions.orgB!, 'GET', `/match/organizer/events/${eventA}/vendors`);
    expect(list.status()).toBe(403);
    const preview = await lnkupRest(sessions.orgB!, 'POST', '/match/organizer/invites/preview', {
      event_id: Number(eventA),
      profile_ids: [1],
    });
    expect(preview.status()).toBe(403);
  });

  test('owner lists candidates as public cards without private contact fields', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', `/match/organizer/events/${eventA}/vendors`);
    expect(response.ok()).toBeTruthy();
    const body = (await response.json()) as { items: Array<Record<string, unknown>> };
    for (const card of body.items) {
      expect(card).not.toHaveProperty('email');
      expect(card).not.toHaveProperty('phone');
      expect(card).not.toHaveProperty('documents');
      expect(card).not.toHaveProperty('payments_total');
    }
  });

  test('organizer recruitment summary returns readiness and never fails hard', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', `/match/organizer/events/${eventA}/recruitment`);
    expect(response.ok()).toBeTruthy();
    const body = (await response.json()) as { readiness: unknown[] };
    expect(Array.isArray(body.readiness)).toBeTruthy();
  });

  test('admin diagnostics are not available to organizers', async () => {
    const response = await lnkupRest(sessions.orgA!, 'GET', '/admin/match/diagnose?profile_id=1&opportunity_id=1');
    expect(response.status()).toBe(403);
  });
});

test.describe('Matching & discovery dashboards', () => {
  test.skip(!vendorUser || !orgA, 'Run scripts/e2e/seed.php to create fixtures');

  test('vendor profile shows optional matching preferences with privacy controls', async ({ page }) => {
    await loginPage(page, vendorUser, vendorPass);
    await page.goto('/vendor-profile/');
    const completeness = page.getByTestId('lnkup-match-completeness');
    test.skip(!(await completeness.isVisible().catch(() => false)), 'Matching preferences not rendered (no vendor profile)');
    await expect(completeness).toContainText(/complete/i);
    await expect(page.getByTestId('lnkup-match-discoverable')).toBeVisible();
  });

  test('organizer Find Vendors settles into results or a helpful empty state', async ({ page }) => {
    test.setTimeout(90_000);
    await loginPage(page, orgA, orgPass);
    await page.goto(`${ORGANIZER_LANDING}?view=vendors`);
    const finder = page.getByTestId('lnkup-find-vendors');
    test.skip(!(await finder.isVisible().catch(() => false)), 'Organizer matching flag is off');
    await expectSettled(page, 'lnkup-find-vendors');
    const cards = page.getByTestId('lnkup-find-vendor-card');
    const empty = finder.locator('.lnkup-empty-state, .lnkup-alert');
    await expect(cards.first().or(empty.first())).toBeVisible();
  });

  test('organizer events view offers the tours & series manager', async ({ page }) => {
    await loginPage(page, orgA, orgPass);
    await page.goto(`${ORGANIZER_LANDING}?view=events`);
    const manager = page.getByTestId('lnkup-tours-manager');
    test.skip(!(await manager.isVisible().catch(() => false)), 'Tours flag is off');
    await expect(manager.getByRole('heading', { name: /tours & series/i })).toBeVisible();
    const form = page.getByTestId('lnkup-tour-form');
    if (await form.isVisible().catch(() => false)) {
      await form.getByLabel(/^name$/i).fill('Playwright tour');
      await form.getByRole('button', { name: /create tour/i }).click();
      await expect(form.getByText(/choose at least two events/i)).toBeVisible();
    }
  });

  test('Find Events explore rails never show an empty heading', async ({ page }) => {
    await loginPage(page, vendorUser, vendorPass);
    await page.goto('/opportunities/');
    const explore = page.locator('#lnkup-explore-more');
    if (!(await explore.isVisible().catch(() => false))) {
      return;
    }
    const tabs = page.getByRole('tab');
    const count = await tabs.count();
    for (let i = 0; i < count; i += 1) {
      await tabs.nth(i).click();
      const panel = page.getByRole('tabpanel').filter({ visible: true }).first();
      await expect(panel.locator('article, .lnkup-event-card, .lnkup-tour-card, .lnkup-chip, a').first()).toBeVisible();
    }
  });
});
