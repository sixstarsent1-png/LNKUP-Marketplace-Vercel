import { test, expect } from '@playwright/test';
import { loginAs, loginPage, lnkupRest, type WpSession } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const reviewer = process.env.PLAYWRIGHT_REVIEWER_USER ?? '';
const eventId = process.env.PLAYWRIGHT_OPS_EVENT_ID ?? '';
const UI_WAIT = 60_000;

test.describe('Event readiness and Overview', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });
  test.skip(!organizer || !eventId, 'Run scripts/e2e/seed-organizer-os.php on after seed.php');

  let staff: WpSession;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    staff = await loginAs(browser, reviewer, password, '/organizer-dashboard/');
  });

  test.afterAll(async () => {
    await staff?.context.close();
  });

  test('owner sees the readiness checklist with verified and incomplete items', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&tab=readiness`);
    const panel = page.getByTestId('lnkup-ops2-readiness');
    await expect(panel.getByText('Event details confirmed')).toBeVisible({ timeout: UI_WAIT });
    await expect(panel.locator('[data-item]')).toHaveCount(11);
    await expect(panel.getByRole('progressbar', { name: 'Event readiness' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Readiness' }).first()).toBeVisible();
  });

  test('a manual-only item can be confirmed and shows who confirmed it', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&tab=readiness`);
    const item = page.getByTestId('lnkup-ops2-readiness').locator('[data-item="setup_instructions_sent"]');
    await expect(item).toBeVisible({ timeout: UI_WAIT });
    page.once('dialog', (dialog) => dialog.accept('E2E emailed instructions'));
    await item.getByRole('button', { name: 'Confirm' }).click();
    await expect(page.getByTestId('lnkup-ops2-readiness').locator('[data-item="setup_instructions_sent"]').getByText('Confirmed', { exact: true })).toBeVisible({ timeout: UI_WAIT });
  });

  test('a system-checked item cannot be confirmed by hand', async ({ browser }) => {
    const owner = await loginAs(browser, organizer, password, '/organizer-dashboard/');
    const response = await lnkupRest(owner, 'POST', `/events/${eventId}/readiness/applications_reviewed`, { state: 'confirmed' });
    expect(response.status()).toBe(409);
    await owner.context.close();
  });

  test('a reviewer without event access cannot read readiness', async () => {
    expect((await lnkupRest(staff, 'GET', `/events/${eventId}/readiness`)).status()).toBe(403);
  });

  test('the Overview shows what needs attention and upcoming event cards', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto('/organizer-dashboard/');
    await expect(page.getByTestId('lnkup-overview-v2')).toBeVisible({ timeout: UI_WAIT });
    await expect(page.getByRole('heading', { name: 'Needs attention' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Upcoming events' })).toBeVisible();
    await expect(page.getByTestId('lnkup-organizer-quick-actions').getByRole('link', { name: /Review applications/ })).toBeVisible();
  });
});
