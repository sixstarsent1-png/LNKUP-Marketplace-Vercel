import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const vendorUser = process.env.PLAYWRIGHT_VENDOR_B_USER ?? '';
const appId = process.env.PLAYWRIGHT_REVIEW_APPLICATION_ID ?? '';
const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';

test.describe('Request more information', () => {
  test.describe.configure({ mode: 'serial' });
  test.skip(!organizerUser || !vendorUser || !appId, 'Run scripts/e2e/seed.php');

  const question = `Please send your insurance certificate (${Date.now()})`;
  const reply = 'Uploaded it to my documents just now.';

  test('organizer asks the vendor a question', async ({ page }) => {
    await loginPage(page, organizerUser, password);
    await page.goto(`/organizer-dashboard/?view=applications&mode=classic&event_filter=${eventId}`);
    const ask = page.locator(`[data-testid="lnkup-org-request-info"][data-app-id="${appId}"]`);
    await expect(ask, 'review application should be open for questions').toBeVisible();
    await ask.click();
    const compose = page.getByTestId('lnkup-org-info-compose');
    await compose.getByLabel(/what do you need/i).fill(question);
    await compose.getByLabel(/insurance certificate/i).check();
    await compose.getByRole('button', { name: /send question/i }).click();
    await expect(page.getByText(/question sent/i)).toBeVisible();
    await page.reload();
    await expect(page.getByTestId('lnkup-org-info-thread').filter({ hasText: question })).toBeVisible();
  });

  test('vendor sees the question and replies', async ({ page }) => {
    await loginPage(page, vendorUser, password);
    await page.goto('/vendor-dashboard/?view=applications');
    const block = page.getByTestId('lnkup-vendor-info-request').filter({ hasText: question });
    await expect(block).toBeVisible();
    await expect(block).toContainText(/insurance certificate/i);
    await block.getByTestId('lnkup-vendor-info-reply').fill(reply);
    await block.getByTestId('lnkup-vendor-info-send').click();
    await expect(page.getByTestId('lnkup-vendor-info-sent')).toBeVisible();
  });

  test('organizer sees the reply and the request is back in review', async ({ page }) => {
    await loginPage(page, organizerUser, password);
    await page.goto(`/organizer-dashboard/?view=applications&mode=classic&event_filter=${eventId}`);
    await expect(page.getByTestId('lnkup-org-info-thread').filter({ hasText: reply })).toContainText(/vendor replied/i);
    await expect(page.locator(`[data-testid="lnkup-org-request-info"][data-app-id="${appId}"]`)).toBeVisible();
  });
});
