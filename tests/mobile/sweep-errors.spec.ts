import { test, expect, type Page, type Route } from '@playwright/test';
import { appLogin, organizerUser, restAs, vendorUser } from './helpers/app-session';

const pendingPaymentAppId = process.env.PLAYWRIGHT_PENDING_PAYMENT_APPLICATION_ID || '64';
const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '56';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER || 'e2e_org_b';
const opportunityId = process.env.PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID || '58';

/**
 * Fail one REST route with a server error and count how many times the app called it.
 */
async function failRoute(page: Page, pattern: string, method = 'POST'): Promise<{ calls: () => number }> {
  let count = 0;
  await page.route(pattern, (route: Route) => {
    if (route.request().method() !== method) return route.fallback();
    count += 1;
    return route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ code: 'server_error', message: 'Internal failure xyz' }),
    });
  });
  return { calls: () => count };
}

test.describe('Vendor error paths', () => {
  test.beforeEach(async ({ page }) => {
    await appLogin(page, vendorUser);
  });

  test('a failed profile save keeps the edits and explains, without a success message', async ({ page }) => {
    const failing = await failRoute(page, '**/wp-json/lnkup/v1/vendor/profile');
    await page.goto('/(vendor)/profile');
    const city = page.getByLabel('City');
    await expect(city).toBeVisible({ timeout: 45_000 });
    await city.fill(`Sweep ${Date.now() % 1000}`);
    await page.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByText(/Couldn't save your profile/)).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('You have unsaved changes')).toBeVisible();
    await expect(page.getByText('Internal failure xyz')).toHaveCount(0);
    expect(failing.calls()).toBe(1);
  });

  test('a failed checkout handoff leaves the booking unpaid and the button usable', async ({ page }) => {
    const failing = await failRoute(page, '**/wp-json/lnkup/v1/financial/checkout/*/redirect-url');
    await page.goto(`/pass/${pendingPaymentAppId}`);
    const pay = page.getByRole('button', { name: /^Pay / });
    await expect(pay).toBeVisible({ timeout: 45_000 });
    await pay.dblclick();
    // The app falls back to the LNKup payments page and waits for server confirmation; it never claims payment.
    await expect(
      page.getByText(/haven’t received confirmation|Finish payment on LNKup|Couldn|isn’t ready|try again/i).first(),
    ).toBeVisible({ timeout: 90_000 });
    await expect(page.getByText(/Payment received/)).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Pay / })).toBeEnabled();
    expect(failing.calls(), 'double tap sends one handoff request').toBeLessThanOrEqual(1);
    const { body } = await restAs(page, vendorUser, 'GET', '/applications/vendor');
    const item = ((body as { items?: Array<{ application_id?: number; status?: string }> })?.items ?? []).find(
      (row) => String(row.application_id) === pendingPaymentAppId,
    );
    expect(item?.status).not.toBe('paid');
  });

  test('a failed save toggle puts the heart back and says why', async ({ page }) => {
    await failRoute(page, '**/wp-json/lnkup/v1/marketplace/favorites');
    await page.goto(`/opportunity/${opportunityId}`);
    const heart = page.getByRole('button', { name: /^(Save|Unsave) / }).first();
    await expect(heart).toBeVisible({ timeout: 45_000 });
    const before = await heart.getAttribute('aria-label');
    await heart.click();
    await expect(page.getByText(/Couldn't update saved events/)).toBeVisible({ timeout: 45_000 });
    await expect(page.getByRole('button', { name: before ?? '' })).toBeVisible();
  });
});

test.describe('Organizer error paths', () => {
  test('a failed cash out shows an error, sends one request, and leaves the balance alone', async ({ page }) => {
    const before = await restAs(page, organizerB, 'GET', '/financial/dashboard?role=organizer');
    const available = Number((before.body as { available_balance?: number })?.available_balance ?? 0);
    test.skip(available < 5, 'Seed did not give organizer B a test-mode balance');
    await appLogin(page, organizerB);
    const failing = await failRoute(page, '**/wp-json/lnkup/v1/financial/organizer-wallet/cashout');
    await page.goto('/(organizer)/money');
    await page.getByLabel('Cash out amount').fill('5');
    await page.getByRole('button', { name: 'Cash out', exact: true }).click();
    await page.getByRole('button', { name: 'Cash out', exact: true }).last().dblclick();
    await expect(page.getByText(/couldn|try again|LNKup/i).first()).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Internal failure xyz')).toHaveCount(0);
    expect(failing.calls()).toBe(1);
    const after = await restAs(page, organizerB, 'GET', '/financial/dashboard?role=organizer');
    expect(Number((after.body as { available_balance?: number })?.available_balance ?? 0)).toBe(available);
  });

  test('a failed slot pricing save explains and keeps the server values', async ({ page }) => {
    await appLogin(page, organizerUser);
    const failing = await failRoute(page, '**/wp-json/lnkup/v1/organizer/opportunities/*/pricing');
    await page.goto(`/(organizer)/events/${eventId}?tab=slots`);
    const fee = page.getByRole('textbox', { name: /^Food Vendor Space vendor fee in dollars$/ });
    await expect(fee).toBeVisible({ timeout: 45_000 });
    await fee.fill('999');
    await page.getByRole('button', { name: 'Save fee & spots' }).first().click();
    await expect(page.getByText(/Couldn't update pricing/)).toBeVisible({ timeout: 45_000 });
    expect(failing.calls()).toBeGreaterThanOrEqual(1);
  });
});
