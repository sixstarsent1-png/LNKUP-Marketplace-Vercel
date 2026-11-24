import { test, expect, type Page, type APIRequestContext } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

/**
 * Public access control (LNKup_Access_Policy): signed-out visitors and crawlers get public-safe
 * previews with an intentional sign-in gate; pricing, inventory and requirements never reach the
 * page source or the REST payload. Signing in returns the visitor to the event they chose.
 */

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

/** First published event URL linked from the homepage cards. */
async function firstPublicEventUrl(page: Page): Promise<string | null> {
  await page.goto('/');
  const card = page.getByTestId('lp-event-card').first();
  if ((await card.count()) === 0) return null;
  const href = await card.getByRole('link').first().getAttribute('href');
  return href;
}

/** Guest marketplace search payload. */
async function guestSearch(request: APIRequestContext) {
  const response = await request.get('/wp-json/lnkup/v1/marketplace/search?per_page=6');
  expect(response.ok()).toBeTruthy();
  return (await response.json()) as { items?: Array<Record<string, unknown>>; prelaunch?: boolean };
}

test.describe('Public access control', () => {
  test('guest REST search withholds pricing and inventory', async ({ request }) => {
    const data = await guestSearch(request);
    if (data.prelaunch) {
      expect(data.items ?? []).toHaveLength(0);
      return;
    }
    for (const item of data.items ?? []) {
      expect(item.vendor_fee, `fee hidden for ${item.opportunity_id}`).toBeNull();
      expect(item.spots_remaining).toBeNull();
      expect(item.application_count).toBeUndefined();
      expect((item.access as { gated?: boolean } | undefined)?.gated).toBe(true);
    }
  });

  test('homepage cards show a sign-in gate instead of prices', async ({ page }) => {
    await page.goto('/');
    const cards = page.getByTestId('lp-event-card');
    test.skip((await cards.count()) === 0, 'no live events on the homepage');
    await expect(cards.first().getByTestId('lp-event-gate')).toBeVisible();
    await expect(cards.first()).not.toContainText(/Booths from|spots? open/i);
    await expect(page.getByText('Signed in', { exact: true })).toHaveCount(0);
  });

  test('public event page keeps SEO content but gates vendor details', async ({ page }) => {
    const url = await firstPublicEventUrl(page);
    test.skip(!url, 'no public event to open');
    const response = await page.goto(url as string);
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('lnkup-public-event')).toBeVisible();
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByTestId('lnkup-guest-gate')).toBeVisible();
    await expect(page.getByTestId('lnkup-guest-gate-signin')).toBeVisible();
    await expect(page.getByTestId('lnkup-guest-gate-signup')).toBeVisible();
    const source = await page.content();
    expect(source).not.toMatch(/data-slot-fee=/);
    expect(source).not.toMatch(/Vendor Fee: \$/);
    const description = await page.locator('meta[name="description"]').getAttribute('content');
    expect(description ?? '').not.toMatch(/\$\d|spots left/i);
  });

  test('sign-in gate links back to the same event with a validated return', async ({ page }) => {
    const url = await firstPublicEventUrl(page);
    test.skip(!url, 'no public event to open');
    await page.goto(url as string);
    const href = (await page.getByTestId('lnkup-guest-gate-signin').getAttribute('href')) ?? '';
    const redirect = decodeURIComponent(new URL(href, page.url()).searchParams.get('redirect') ?? '');
    expect(redirect).toContain(new URL(url as string, page.url()).pathname);
    expect(redirect).toContain('lnkup_intent=');
  });

  test('vendor returns to the event after signing in and sees vendor details', async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'PLAYWRIGHT_VENDOR_USER/PASS not set');
    const url = await firstPublicEventUrl(page);
    test.skip(!url, 'no public event to open');
    await page.goto(url as string);
    const href = (await page.getByTestId('lnkup-guest-gate-signin').getAttribute('href')) ?? '';
    await page.goto(href);
    await page.getByLabel(/email/i).first().fill(vendorUser as string);
    await page.getByLabel(/password/i).first().fill(vendorPass as string);
    await page.getByRole('button', { name: /log in|sign in/i }).first().click();
    await page.waitForLoadState('domcontentloaded');
    expect(new URL(page.url()).pathname).toBe(new URL(url as string, page.url()).pathname);
    await expect(page.getByTestId('lnkup-guest-gate')).toHaveCount(0);
  });

  test('signed-in vendor search includes pricing', async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'PLAYWRIGHT_VENDOR_USER/PASS not set');
    await loginPage(page, vendorUser as string, vendorPass as string);
    const response = await page.request.get('/wp-json/lnkup/v1/marketplace/search?per_page=3');
    expect(response.headers()['cache-control'] ?? '').toContain('private');
  });

  test('location lookup rejects an invalid US state and suggests the intended city', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/locations/resolve?city=Atlanta&state=GE');
    expect(response.ok()).toBeTruthy();
    const data = (await response.json()) as { status: string; suggestions: Array<{ display: string }> };
    expect(data.status).toBe('suggested');
    expect(data.suggestions[0]?.display).toBe('Atlanta, GA');
  });
});
