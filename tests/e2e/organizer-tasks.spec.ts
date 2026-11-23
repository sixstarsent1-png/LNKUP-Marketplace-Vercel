import { test, expect } from '@playwright/test';
import { loginAs, loginPage, lnkupRest } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const eventId = process.env.PLAYWRIGHT_OPS_EVENT_ID ?? '';
const UI_WAIT = 60_000;

test.describe('Event tasks', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });
  test.skip(!organizer || !eventId, 'Run scripts/e2e/seed-organizer-os.php on after seed.php');

  const title = `E2E task ${Date.now()}`;

  test('owner adds a task on the event Tasks tab and marks it done', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&tab=tasks`);
    const panel = page.getByTestId('lnkup-ops2-tasks');
    await expect(panel.getByRole('button', { name: 'Add task' })).toBeVisible({ timeout: UI_WAIT });
    await panel.getByLabel('Task', { exact: true }).fill(title);
    await panel.getByLabel('Priority').selectOption('high');
    await panel.getByRole('button', { name: 'Add task' }).click();
    const done = panel.getByRole('checkbox', { name: `Mark "${title}" done` });
    await expect(done).toBeVisible({ timeout: UI_WAIT });
    await done.check();
    await expect(panel.getByRole('checkbox', { name: `Mark "${title}" done` })).toBeChecked({ timeout: UI_WAIT });
  });

  test('My tasks lists views with counts', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto('/organizer-dashboard/?view=tasks&tasks_view=completed');
    const panel = page.getByTestId('lnkup-ops2-mytasks');
    await expect(panel.getByRole('button', { name: /Completed \(\d+\)/ })).toHaveAttribute('aria-pressed', 'true', { timeout: UI_WAIT });
    await expect(panel.getByText(title)).toBeVisible();
    await expect(panel.getByRole('heading', { name: 'Task templates' })).toBeVisible();
  });

  test('another organizer cannot read or add tasks on the event', async ({ browser }) => {
    const other = await loginAs(browser, organizerB, password, '/organizer-dashboard/');
    expect((await lnkupRest(other, 'GET', `/events/${eventId}/tasks`)).status()).toBe(403);
    expect((await lnkupRest(other, 'POST', `/events/${eventId}/tasks`, { title: 'E2E intrusion' })).status()).toBe(403);
    await other.context.close();
  });
});
