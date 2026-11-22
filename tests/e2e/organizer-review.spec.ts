import { test, expect, type Page } from '@playwright/test';
import { loginAs, loginPage, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const reviewer = process.env.PLAYWRIGHT_REVIEWER_USER ?? '';
const reviewApp = process.env.PLAYWRIGHT_REVIEW_APPLICATION_ID ?? '';
const REQUESTS = '/organizer-dashboard/?view=applications&group=pending';
const UI_WAIT = 60_000;

/**
 * Open the review drawer for the first queue row whose vendor name matches.
 */
async function openApplication(page: Page, vendor: RegExp) {
  await page.getByRole('button', { name: vendor }).first().click({ timeout: UI_WAIT });
  const drawer = page.getByRole('complementary', { name: 'Application review' });
  await expect(drawer.getByRole('button', { name: 'Close' })).toBeVisible({ timeout: UI_WAIT });
  return drawer;
}

test.describe('Organizer review workspace', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });
  test.skip(!reviewer || !organizer || !reviewApp, 'Run scripts/e2e/seed-organizer-os.php on after seed.php');

  let owner: WpSession;
  let other: WpSession;
  let staff: WpSession;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    owner = await loginAs(browser, organizer, password, '/organizer-dashboard/');
    other = await loginAs(browser, organizerB, password, '/organizer-dashboard/');
    staff = await loginAs(browser, reviewer, password, '/organizer-dashboard/');
  });

  test.afterAll(async () => {
    await Promise.all([owner, other, staff].map((s) => s?.context.close()));
  });

  test('owner sees the review queue with status groups and opens an application', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto(REQUESTS);
    await expect(page.getByRole('heading', { name: 'Review applications' })).toBeVisible();
    await expect(page.getByRole('button', { name: /Needs review \(\d+\)/ })).toHaveAttribute('aria-pressed', 'true', { timeout: UI_WAIT });
    const drawer = await openApplication(page, /E2E Vendor B Goods/);
    await expect(drawer.getByRole('button', { name: 'Approve' })).toBeVisible();
    await expect(drawer.getByRole('combobox', { name: 'Add reviewer' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Classic view' })).toBeVisible();
  });

  test('queue rows come from the server with server-computed actions', async () => {
    const response = await lnkupRest(owner, 'GET', '/applications/review/queue?group=pending');
    expect(response.status()).toBe(200);
    const body = await response.json();
    expect(body.counts.pending).toBeGreaterThan(0);
    const row = body.items.find((item: { id: number }) => String(item.id) === reviewApp);
    expect(row, 'seeded review application is in the owner queue').toBeTruthy();
    expect(row.actions).toContain('approve');
  });

  test('another organizer cannot open the workspace or bulk-decide foreign applications', async () => {
    expect((await lnkupRest(other, 'GET', `/applications/${reviewApp}/workspace`)).status()).toBe(403);
    const preview = await (await lnkupRest(other, 'POST', '/applications/bulk/preview', { action: 'approve', ids: [Number(reviewApp)] })).json();
    expect(preview.eligible).toHaveLength(0);
    expect(preview.ineligible).toHaveLength(1);
    expect(preview.token).toBe('');
  });

  test('reviewer without an organizer account lands on the staff workspace', async ({ page }) => {
    await loginPage(page, reviewer, password);
    await page.goto('/organizer-dashboard/');
    await expect(page.getByTestId('lnkup-staff-home')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Your access' })).toBeVisible();
    await expect(page.getByText(/Application Reviewer/).first()).toBeVisible();
    await expect(page.getByRole('link', { name: 'Open review queue' })).toBeVisible();
  });

  test('reviewer can score but not decide', async ({ page }) => {
    const ws = await (await lnkupRest(staff, 'GET', `/applications/${reviewApp}/workspace`)).json();
    expect(ws.actions).toContain('score');
    expect(ws.actions).not.toContain('approve');
    expect((await lnkupRest(staff, 'POST', `/applications/${reviewApp}/decision`, { action: 'approve' })).status()).toBe(403);

    await loginPage(page, reviewer, password);
    await page.goto(`${REQUESTS}&app=${reviewApp}`);
    const drawer = page.getByRole('complementary', { name: 'Application review' });
    await expect(drawer.getByRole('button', { name: 'Submit review' }).or(drawer.getByRole('button', { name: 'Update review' }))).toBeVisible({ timeout: UI_WAIT });
    await expect(drawer.getByRole('button', { name: 'Approve' })).toHaveCount(0);
    for (const group of await drawer.getByRole('group').all()) {
      await group.getByRole('radio', { name: '4', exact: true }).check();
    }
    await drawer.getByRole('button', { name: /Submit review|Update review/ }).click();
    await expect(drawer.getByText('Review submitted.')).toBeVisible({ timeout: UI_WAIT });
  });

  test('reviewer cannot read financials or the team log', async () => {
    const queue = await (await lnkupRest(staff, 'GET', '/applications/review/queue')).json();
    const eventId = queue.items[0]?.event_id;
    expect(eventId).toBeTruthy();
    expect((await lnkupRest(staff, 'GET', `/financial/events/${eventId}/financials`)).status()).toBe(403);
    expect((await lnkupRest(staff, 'GET', '/org/team/audit?owner_id=1')).status()).toBe(403);
  });

  test('owner sees the reviewer score in the consolidated summary', async () => {
    const ws = await (await lnkupRest(owner, 'GET', `/applications/${reviewApp}/workspace`)).json();
    expect(ws.review.completed).toBeGreaterThan(0);
    expect(ws.review.average).toBe(4);
    expect(ws.status).not.toBe('approved');
  });
});
