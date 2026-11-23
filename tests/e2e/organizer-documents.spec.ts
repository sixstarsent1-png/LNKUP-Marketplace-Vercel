import { test, expect } from '@playwright/test';
import { loginAs, loginPage, lnkupRest } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const eventId = process.env.PLAYWRIGHT_OPS_EVENT_ID ?? '';
const docsApp = process.env.PLAYWRIGHT_DOCS_APP_ID ?? '';
const UI_WAIT = 60_000;

test.describe('Organizer document review', () => {
  test.describe.configure({ mode: 'serial', timeout: 120_000 });
  test.skip(!organizer || !eventId || !docsApp || docsApp === '0', 'Run scripts/e2e/seed-organizer-os.php on after seed.php');

  test('owner reviews a shared document and accepts it for this event', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&tab=documents`);
    const panel = page.getByTestId('lnkup-ops2-documents');
    const row = panel.locator(`tr[data-app-id="${docsApp}"][data-type="certificate_of_insurance"]`);
    await expect(row.getByText('Ready for review')).toBeVisible({ timeout: UI_WAIT });
    await expect(row.getByRole('link', { name: 'Open file' })).toBeVisible();
    await row.getByRole('button', { name: 'Accept' }).click();
    await expect(panel.locator(`tr[data-app-id="${docsApp}"][data-type="certificate_of_insurance"]`).getByText('Accepted', { exact: true })).toBeVisible({ timeout: UI_WAIT });
    await expect(panel.getByText(/apply to this event only/)).toBeVisible();
  });

  test('another organizer cannot read or decide documents for the event', async ({ browser }) => {
    const other = await loginAs(browser, organizerB, password, '/organizer-dashboard/');
    expect((await lnkupRest(other, 'GET', `/events/${eventId}/documents`)).status()).toBe(403);
    expect((await lnkupRest(other, 'POST', `/applications/${docsApp}/documents/certificate_of_insurance/review`, { decision: 'accept' })).status()).toBe(403);
    await other.context.close();
  });
});
