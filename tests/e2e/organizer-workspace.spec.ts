import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';
const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';

test.describe('Organizer event workspace', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !eventId, 'Run scripts/e2e/seed.php');
    await loginPage(page, organizerUser, organizerPass);
  });

  test('shows event sections and highlights Events in the nav', async ({ page }) => {
    await page.goto(`/organizer-dashboard/?event=${eventId}`);
    const tabs = page.getByTestId('lnkup-event-workspace-tabs');
    await expect(tabs).toBeVisible();
    for (const name of ['Overview', 'Inventory', 'Applications', 'Confirmed vendors', 'Money', 'Operations', 'Check-in', 'Marketing', 'Settings']) {
      await expect(tabs.getByRole('link', { name: new RegExp(`^${name}`, 'i') })).toBeVisible();
    }
    await expect(page.locator('.lnkup-dashboard__nav-link.is-active[data-view="events"]')).toHaveCount(1);
  });

  test('legacy slots tab key opens Inventory with ledger counts and a vendor preview', async ({ page }) => {
    await page.goto(`/organizer-dashboard/?event=${eventId}&tab=slots`);
    await expect(page.getByTestId('lnkup-event-workspace-tabs').getByRole('link', { name: 'Inventory' })).toHaveAttribute('aria-current', 'page');
    const inventory = page.getByTestId('lnkup-slot-inventory').first();
    await expect(inventory).toContainText(/open · \d+ held · \d+ paid · \d+ waitlisted/);
    await expect(page.getByTestId('lnkup-slot-preview').first()).toHaveAttribute('href', /.+/);
  });

  test('application drawer offers decisions for an open request', async ({ page }) => {
    await page.goto(`/organizer-dashboard/?view=applications&mode=classic&filter=pending&event_filter=${eventId}`);
    await page.getByRole('button', { name: /^Review / }).first().click();
    await page.locator('#oos-app-drawer').getByRole('button', { name: /view details for/i }).click();
    await expect(page.getByTestId('lnkup-intel-decide')).toBeVisible();
    await expect(page.getByTestId('lnkup-intel-decide').getByRole('button', { name: /accept/i })).toBeVisible();
  });

  test('Financials tab shows collected and outstanding totals', async ({ page }) => {
    await page.goto(`/organizer-dashboard/?event=${eventId}&tab=financials`);
    const stats = page.getByTestId('lnkup-event-financials');
    await expect(stats).toContainText(/collected/i);
    await expect(stats).toContainText(/outstanding/i);
  });
});
