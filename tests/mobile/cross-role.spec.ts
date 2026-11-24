import { test, expect, type Browser, type Page } from '@playwright/test';
import { appLogin, appLogout, organizerUser, password, restAs, vendorBUser, wpBase } from './helpers/app-session';

const freshOpportunityId = process.env.PLAYWRIGHT_FRESH_OPPORTUNITY_ID || '59';
const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '56';
const simulationEnabled = process.env.PLAYWRIGHT_PAYMENT_SIMULATION === '1';

type VendorApp = { application_id?: number; id?: number; opportunity_id?: number; status?: string };

/**
 * Vendor B's application to the fresh one-spot opportunity, read straight from WordPress.
 */
async function freshApplication(page: Page): Promise<VendorApp | undefined> {
  const { body } = await restAs(page, vendorBUser, 'GET', '/applications/vendor');
  const items = ((body as { items?: VendorApp[] })?.items ?? []) as VendorApp[];
  return items.find((item) => String(item.opportunity_id) === freshOpportunityId);
}

/**
 * Finish WordPress checkout in the vendor's own browser session (cookie sign-in), as the hosted
 * checkout would. The simulation link the app receives is signed for the app's token session, so the
 * browser asks the same `redirect-url` endpoint for its own link. Test-mode manual provider only:
 * no card network or live Stripe is involved.
 */
async function completeCheckoutInBrowser(browser: Browser, appCheckoutUrl: string): Promise<void> {
  const paymentId = new URL(appCheckoutUrl).searchParams.get('payment_id');
  expect(paymentId, 'the app handed off a payment').toBeTruthy();
  const context = await browser.newContext({ baseURL: wpBase });
  const page = await context.newPage();
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(vendorBUser);
    await page.getByLabel(/^password$/i).fill(password);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForLoadState('domcontentloaded');
    if (!/wp-login/.test(page.url())) break;
  }
  await page.goto('/vendor-dashboard/');
  const nonce = await page.evaluate(() => {
    const w = window as unknown as { lnkupAjax?: { restNonce?: string }; wpApiSettings?: { nonce?: string } };
    return w.lnkupAjax?.restNonce || w.wpApiSettings?.nonce || '';
  });
  expect(nonce, 'vendor dashboard exposes a REST nonce').not.toBe('');
  const response = await context.request.post(`/wp-json/lnkup/v1/financial/checkout/${paymentId}/redirect-url`, {
    headers: { 'X-WP-Nonce': nonce, Accept: 'application/json' },
    data: { return_to: 'app' },
  });
  const body = (await response.json()) as { status?: string; checkout_url?: string };
  expect(body.status, JSON.stringify(body)).toBe('ready');
  await page.goto(String(body.checkout_url));
  await expect(page).toHaveURL(/lnkup_payment=success/, { timeout: 60_000 });
  await context.close();
}

test.describe('Cross-role booking journey', () => {
  test.describe.configure({ mode: 'serial' });
  test.setTimeout(240_000);

  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'iphone-15', 'Writes bookings and a test-mode payment; one device per seed');
  });

  test('vendor applies, organizer approves, test-mode payment confirms, both sides agree', async ({ page, browser }) => {
    test.skip(!simulationEnabled, 'Payment simulation is off on this environment');
    test.skip(Boolean(await freshApplication(page)), 'Fresh opportunity already has an application — reseed to rerun');

    await test.step('vendor applies in the app', async () => {
      await appLogin(page, vendorBUser);
      await page.goto(`/apply/${freshOpportunityId}`);
      await page.getByRole('button', { name: 'Food Vendor' }).click();
      await page.getByRole('checkbox', { name: /accept LNKup marketplace terms/ }).click();
      await page.getByRole('button', { name: /apply|submit/i }).last().click();
      await expect.poll(async () => (await freshApplication(page))?.status, { timeout: 45_000 }).toMatch(/submitted|under_review/);
      await appLogout(page);
    });

    const app = await freshApplication(page);
    const appId = String(app?.application_id ?? app?.id ?? '');
    expect(appId).not.toBe('');

    await test.step('organizer approves from the Vendors inbox', async () => {
      await appLogin(page, organizerUser);
      await page.goto(`/(organizer)/vendors?event=${eventId}&tab=requests&filter=review`);
      const card = page.getByTestId(`organizer-request-${appId}`);
      await expect(card).toBeVisible({ timeout: 45_000 });
      await card.getByRole('button', { name: /^Approve / }).click();
      await page.getByRole('button', { name: 'Approve vendor' }).click();
      await expect(page.getByText(/approved — we've asked them to pay to confirm/)).toBeVisible({ timeout: 45_000 });
      await expect.poll(async () => (await freshApplication(page))?.status, { timeout: 45_000 }).toMatch(/payment_required|approved/);
      await appLogout(page);
    });

    await test.step('vendor pays through the WordPress checkout handoff', async () => {
      await appLogin(page, vendorBUser);
      await page.goto(`/pass/${appId}`);
      const pay = page.getByRole('button', { name: /^Pay / });
      await expect(pay).toBeVisible({ timeout: 45_000 });
      const popupPromise = page.waitForEvent('popup', { timeout: 45_000 });
      await pay.click();
      const popup = await popupPromise;
      await popup.waitForLoadState('domcontentloaded').catch(() => undefined);
      const checkoutUrl = popup.url();
      await popup.close();
      expect(checkoutUrl, 'the app hands off to WordPress, never a client-side charge').toContain(new URL(wpBase).host);
      await completeCheckoutInBrowser(browser, checkoutUrl);
      await expect.poll(async () => (await freshApplication(page))?.status, { timeout: 45_000 }).toMatch(/paid|completed/);
    });

    await test.step('vendor sees the confirmed booking', async () => {
      await page.goto(`/booking/${appId}`);
      await expect(page.getByTestId('booking-timeline').getByText(/Paid · booking confirmed/)).toBeVisible({ timeout: 45_000 });
      await page.goto('/(vendor)/requests?filter=confirmed');
      await expect(page.getByRole('button', { name: /^E2E Summer Market, / }).first()).toBeVisible({ timeout: 45_000 });
      await appLogout(page);
    });

    await test.step('organizer sees the vendor as confirmed', async () => {
      await appLogin(page, organizerUser);
      await page.goto(`/(organizer)/vendors?event=${eventId}&tab=requests&filter=confirmed`);
      await expect(page.getByTestId(`organizer-request-${appId}`)).toBeVisible({ timeout: 45_000 });
    });
  });
});

test.describe('Cross-role failure and session handling', () => {
  test('a failed approval shows an error and never a false success', async ({ page }) => {
    await appLogin(page, organizerUser);
    await page.route('**/wp-json/lnkup/v1/crm/events/*/roster/bulk', (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ code: 'server_error', message: 'boom' }) }),
    );
    await page.goto(`/(organizer)/vendors?event=${eventId}&tab=requests&filter=review`);
    const row = page.locator('[data-testid^="organizer-request-"]').first();
    await expect(row).toBeVisible({ timeout: 45_000 });
    await row.click();
    const approve = page.getByTestId('review-approve');
    await expect(approve).toBeVisible({ timeout: 45_000 });
    await approve.click();
    await expect(page.getByTestId('approve-note')).toBeVisible({ timeout: 45_000 });
    await page.getByRole('button', { name: 'Approve vendor' }).click();
    await expect(page.getByText(/Couldn't update this request/)).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/is approved\. We've sent the invoice/)).toHaveCount(0);
    await expect(page.getByText('boom')).toHaveCount(0);
  });

  test('a session survives a reload and sign-out clears it', async ({ page }) => {
    await appLogin(page, vendorBUser);
    await page.goto('/(vendor)/requests');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Bookings' })).toBeVisible({ timeout: 45_000 });
    await page.goto('/(vendor)/more');
    await page.getByTestId('vendor-more-sign-out').click();
    await page.getByRole('button', { name: 'Sign out', exact: true }).last().click();
    await expect(page).toHaveURL(/\/login/);
    await page.goto('/(vendor)/wallet');
    await expect(page).toHaveURL(/\/login/);
  });
});
