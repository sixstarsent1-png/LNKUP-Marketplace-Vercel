import { test, expect, type Page } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const organizerEventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID;

/**
 * Log into WordPress with the given credentials.
 */
async function login(page: Page, user: string, pass: string) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill(pass);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Organizer dashboard enhancements', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
    await login(page, organizerUser!, organizerPass!);
  });

  test('Requests shows search, sort, and status badges on each card', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=applications');
    await expect(page.getByRole('searchbox', { name: /search requests/i })).toBeVisible();
    await expect(page.getByRole('combobox', { name: /^sort$/i })).toBeVisible();

    const cards = page.locator('.lnkup-request-row');
    test.skip((await cards.count()) === 0, 'Organizer has no requests to inspect');
    await expect(cards.first().getByRole('list', { name: /request status details/i })).toBeVisible();
    await expect(cards.first().getByText(/^Applied /)).toBeVisible();
  });

  test('Requests bulk bar stays hidden until a request is selected', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=applications&filter=pending');
    const picks = page.getByRole('checkbox', { name: /^select /i });
    test.skip((await picks.count()) === 0, 'No pending requests to select');

    const bulkBar = page.getByRole('region', { name: /bulk actions/i });
    await expect(bulkBar).toBeHidden();
    await picks.first().check();
    await expect(bulkBar).toBeVisible();
    await expect(bulkBar.getByText('1 selected')).toBeVisible();
    await expect(bulkBar.getByRole('button', { name: 'Accept' })).toBeEnabled();
    await bulkBar.getByRole('button', { name: 'Clear' }).click();
    await expect(bulkBar).toBeHidden();
  });

  test('View details opens the request drawer and Escape closes it', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=applications');
    const details = page.getByRole('button', { name: /view details for/i });
    test.skip((await details.count()) === 0, 'No requests to open');

    await details.first().click();
    const drawer = page.getByRole('dialog', { name: /request details/i });
    await expect(drawer).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
  });

  test('Decline asks for a reason inline instead of a browser prompt', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=applications&filter=pending');
    const decline = page.locator('.lnkup-review-btn--decline');
    test.skip((await decline.count()) === 0, 'No pending requests to decline');

    const dialogs: string[] = [];
    page.on('dialog', async (dialog) => {
      dialogs.push(dialog.message());
      await dialog.dismiss();
    });
    await decline.first().click();
    const reasonForm = page.getByRole('group', { name: /decline request/i });
    await expect(reasonForm).toBeVisible();
    await expect(reasonForm.getByLabel(/reason for the vendor/i)).toBeFocused();
    await reasonForm.getByRole('button', { name: 'Cancel' }).click();
    await expect(reasonForm).toBeHidden();
    expect(dialogs, 'decline should not open a browser prompt').toEqual([]);
  });

  test('Event workspace overview shows readiness, spots, and tools', async ({ page }) => {
    test.skip(!organizerEventId, 'Set PLAYWRIGHT_ORGANIZER_EVENT_ID to an event the organizer owns');
    await page.goto(`/organizer-dashboard/?event=${organizerEventId}`);
    await expect(page.getByTestId('lnkup-event-readiness')).toBeVisible();
    const tools = page.getByRole('navigation', { name: /event tools/i });
    await expect(tools.getByRole('link', { name: 'Roster' })).toBeVisible();
    await expect(tools.getByRole('link', { name: 'Operations' })).toBeVisible();
    await expect(tools.getByRole('link', { name: 'QR check-in' })).toBeVisible();
  });

  test('Edit view links back to the workspace for requests and vendors', async ({ page }) => {
    test.skip(!organizerEventId, 'Set PLAYWRIGHT_ORGANIZER_EVENT_ID to an event the organizer owns');
    await page.goto(`/organizer-dashboard/?event=${organizerEventId}&edit=1`);
    await expect(page.getByRole('link', { name: /back to event/i })).toBeVisible();
    const editorTabs = page.getByRole('navigation', { name: /event editor sections/i });
    await expect(editorTabs.getByRole('link', { name: /^Requests/ })).toHaveAttribute('href', /tab=requests/);
    await expect(editorTabs.getByRole('link', { name: 'Vendors' })).toHaveAttribute('href', /tab=vendors/);
  });

  test('Roster selection count drives the bulk buttons', async ({ page }) => {
    test.skip(!organizerEventId, 'Set PLAYWRIGHT_ORGANIZER_EVENT_ID to an event the organizer owns');
    await page.goto(`/organizer-dashboard/?event=${organizerEventId}&roster=1`);
    const count = page.getByTestId('lnkup-roster-selected-count');
    test.skip((await count.count()) === 0, 'Roster is empty for this event');

    await expect(count).toHaveText('0 selected');
    const approve = page.getByRole('group', { name: /booking decisions/i }).getByRole('button', { name: 'Approve' });
    await expect(approve).toBeDisabled();
    await page.getByRole('checkbox', { name: /^select (?!all)/i }).first().check();
    await expect(count).toHaveText('1 selected');
    await expect(approve).toBeEnabled();
  });

  test('QR check-in offers manual entry and a recent check-ins list', async ({ page }) => {
    test.skip(!organizerEventId, 'Set PLAYWRIGHT_ORGANIZER_EVENT_ID to an event the organizer owns');
    await page.goto(`/organizer-dashboard/?event=${organizerEventId}&scan=1`);
    await expect(page.getByLabel('Pass code')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Recent check-ins' })).toBeVisible();
    await expect(page.getByTestId('lnkup-qr-recent').locator('.lnkup-loading-state')).toHaveCount(0);
  });
});

test.describe('Vendor dashboard enhancements', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
    await login(page, vendorUser!, vendorPass!);
  });

  test('Home shows the Action Center once the vendor has applied', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    const noApps = page.getByText('No applications yet');
    if (await noApps.isVisible()) {
      await expect(page.getByTestId('lnkup-action-center')).toHaveCount(0);
      return;
    }
    await expect(page.getByTestId('lnkup-action-center')).toBeVisible();
  });

  test('Request cards show event timing and progress steps', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=applications');
    const cards = page.getByTestId('lnkup-vendor-request-card');
    test.skip((await cards.count()) === 0, 'Vendor has no requests');

    const first = cards.first();
    const hasWhen = await first.getByTestId('lnkup-vendor-request-when').count();
    const hasSteps = await first.getByRole('list', { name: /request progress/i }).count();
    const hasNote = await first.locator('.lnkup-request-row__note').count();
    expect(hasWhen + hasSteps + hasNote, 'card should show timing, steps, or a status note').toBeGreaterThan(0);
  });

  test('Empty request tabs show an empty state instead of a blank list', async ({ page }) => {
    await page.goto('/vendor-dashboard/?view=applications');
    const tabs = page.getByRole('navigation', { name: /filter requests/i }).getByRole('button');
    test.skip((await tabs.count()) === 0, 'Request tabs are not rendered');

    for (const name of ['Needs action', 'In review', 'Confirmed', 'Past events']) {
      const tab = tabs.filter({ hasText: name }).first();
      await tab.click();
      const visibleCards = page.locator('[data-testid="lnkup-vendor-request-card"]:not([hidden])');
      const empty = page.getByTestId('lnkup-vendor-apps-filter-empty');
      if ((await visibleCards.count()) === 0) {
        await expect(empty).toBeVisible();
      } else {
        await expect(empty).toBeHidden();
      }
    }
  });

  test('Profile renders the documents section the strength links point to', async ({ page }) => {
    await page.goto('/vendor-profile/');
    await expect(page.locator('#section-documents')).toBeVisible();
    await expect(page.getByTestId('lnkup-profile-strength-summary')).toBeVisible();
  });
});
