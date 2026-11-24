import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Meta measurement (Pixel + server outbox + attribution).
 *
 * Requires a disposable site with the LNKup Pixel enabled in test mode and no CAPI token, e.g.:
 *   wp option update lnkup_meta_settings '{"pixel_enabled":true,"capi_enabled":true,"test_mode":true,"test_event_code":"TEST1"}' --format=json
 * fbevents.js is stubbed, so nothing reaches Meta. Admin credentials: META_E2E_ADMIN_USER / META_E2E_ADMIN_PASS.
 */

const ADMIN_USER = process.env.META_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.META_E2E_ADMIN_PASS || 'admin';

type FbqCall = [string, ...unknown[]];

/** Replace Meta's script with an empty stub so fbq() calls stay in window.fbq.queue. */
async function stubMetaScript(context: BrowserContext): Promise<void> {
  await context.route('**/connect.facebook.net/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* stub */' })
  );
}

/** fbq calls queued on the page (empty when the Pixel did not load). */
async function fbqCalls(page: Page): Promise<FbqCall[]> {
  await page.waitForLoadState('load');
  return page.evaluate(() => {
    const fbq = (window as unknown as { fbq?: { queue?: unknown[][] } }).fbq;
    return (fbq?.queue || []).map((args) => Array.from(args) as FbqCall);
  });
}

/** Event IDs fired for an event name. */
function eventIds(calls: FbqCall[], name: string): string[] {
  return calls
    .filter((c) => (c[0] === 'track' || c[0] === 'trackCustom') && c[1] === name)
    .map((c) => (c[3] as { eventID?: string } | undefined)?.eventID || '');
}

/** Log into wp-admin and wait until the dashboard has loaded. */
async function loginAsAdmin(page: Page): Promise<void> {
  await page.goto('/wp-login.php');
  await page.getByLabel('Username or Email Address').fill(ADMIN_USER);
  await page.getByLabel('Password', { exact: true }).fill(ADMIN_PASS);
  await page.getByRole('button', { name: 'Log In' }).click();
  await page.waitForURL(/\/wp-admin\//);
}

/** Fill and submit the LNKup sign-up form as a vendor. */
async function registerVendor(page: Page, email: string): Promise<void> {
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Password', { exact: true }).fill('Passw0rd!meta');
  await page.getByLabel('Confirm password').fill('Passw0rd!meta');
  await page.getByLabel(/agree to the LNKup terms/i).check();
  await page.getByTestId('lnkup-signup-submit').click();
  await page.waitForURL((url) => !url.pathname.startsWith('/sign-up'));
}

test.describe('Meta measurement', () => {
  test.beforeEach(async ({ context }) => {
    await stubMetaScript(context);
  });

  test('campaign landing initializes the Pixel once and stores first-party attribution', async ({ page, context }) => {
    await page.goto('/sign-up/?role=vendor&utm_source=meta&utm_medium=paid_social&utm_campaign=houston_food&ad_id=120200&fbclid=IwARe2eTest123');
    const calls = await fbqCalls(page);

    expect(calls.filter((c) => c[0] === 'init')).toHaveLength(1);
    expect(calls.filter((c) => c[0] === 'init')[0][1]).toBe('2099891494063746');
    const pageViews = eventIds(calls, 'PageView');
    expect(pageViews).toHaveLength(1);
    expect(pageViews[0]).toMatch(/^[0-9a-f-]{36}$/);

    const cookies = await context.cookies();
    const ft = cookies.find((c) => c.name === 'lnkup_ft');
    const fbc = cookies.find((c) => c.name === '_fbc');
    expect(ft, 'first-touch cookie').toBeTruthy();
    expect(decodeURIComponent(ft!.value)).toContain('houston_food');
    expect(fbc?.value).toMatch(/^fb\.1\.\d{13}\.IwARe2eTest123$/);
  });

  test('registration fires CompleteRegistration in the browser with the server event ID, once', async ({ page, browser }) => {
    test.setTimeout(120_000);
    const email = `meta-e2e-${Date.now()}@example.com`;
    await page.goto('/sign-up/?role=vendor&utm_source=meta&utm_campaign=e2e_vendor');
    await registerVendor(page, email);

    const calls = await fbqCalls(page);
    const registrationIds = eventIds(calls, 'CompleteRegistration');
    expect(registrationIds).toHaveLength(1);
    expect(eventIds(calls, 'VendorRegistered')).toHaveLength(1);

    await page.reload();
    expect(eventIds(await fbqCalls(page), 'CompleteRegistration'), 'refresh does not re-fire').toHaveLength(0);

    const admin = await browser.newPage();
    await loginAsAdmin(admin);
    await admin.goto('/wp-admin/admin.php?page=lnkup-meta-health&tab=debugger&event=CompleteRegistration');
    await expect(admin.getByText(registrationIds[0]), 'server row has the same event_id').toBeVisible();
    await expect(admin.getByText(email)).toHaveCount(0);
    await admin.close();
  });

  test('Global Privacy Control blocks the Pixel and attribution cookies', async ({ browser }) => {
    const context = await browser.newContext({ extraHTTPHeaders: { 'Sec-GPC': '1' } });
    await stubMetaScript(context);
    await context.addInitScript(() => {
      Object.defineProperty(navigator, 'globalPrivacyControl', { get: () => true });
    });
    const page = await context.newPage();
    await page.goto('/sign-up/?role=organizer&utm_source=meta&fbclid=IwARgpc123');

    expect(await fbqCalls(page)).toHaveLength(0);
    const cookies = await context.cookies();
    expect(cookies.find((c) => c.name === 'lnkup_ft')).toBeUndefined();
    expect(cookies.find((c) => c.name === '_fbc')).toBeUndefined();
    await context.close();
  });

  test('admin Meta Integration page shows masked status and requires a capability', async ({ page }) => {
    await loginAsAdmin(page);
    await page.goto('/wp-admin/admin.php?page=lnkup-meta-health');
    await expect(page.getByRole('heading', { name: 'Meta Integration', level: 1 })).toBeVisible();
    await expect(page.getByText('act_405302106865918')).toBeVisible();
    await expect(page.getByText(/EAA[A-Za-z0-9]{20,}/)).toHaveCount(0);
  });
});
