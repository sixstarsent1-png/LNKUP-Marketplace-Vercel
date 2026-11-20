import { createHmac } from 'node:crypto';
import { test, expect, type Page } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const webhookSecret = process.env.PLAYWRIGHT_STRIPE_WEBHOOK_SECRET;

const WEBHOOK_PATH = '/wp-json/lnkup/v1/stripe-webhook';
const ONBOARDING_ERROR = "We couldn't open payout setup. Try again in a minute.";

/**
 * Log into WordPress with the given credentials.
 */
async function loginUser(page: Page, username: string, password: string) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(username);
  await page.getByLabel(/^password$/i).fill(password);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

/**
 * Build a Stripe-Signature header for a payload, signed at the given unix timestamp.
 */
function stripeSignature(payload: string, secret: string, timestamp: number) {
  const signature = createHmac('sha256', secret).update(`${timestamp}.${payload}`).digest('hex');
  return `t=${timestamp},v1=${signature}`;
}

/**
 * A harmless event payload the webhook router ignores after verification.
 */
function noopEventPayload() {
  return JSON.stringify({
    id: `evt_e2e_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
    type: 'lnkup.e2e.noop',
    data: { object: {} },
  });
}

test.describe('Stripe Connect marketplace', () => {
  test.describe('webhook signature verification', () => {
    test('forged signature is rejected with JSON, never 500', async ({ request }) => {
      const payload = noopEventPayload();
      const response = await request.post(WEBHOOK_PATH, {
        headers: {
          'Content-Type': 'application/json',
          'Stripe-Signature': `t=${Math.floor(Date.now() / 1000)},v1=${'0'.repeat(64)}`,
        },
        data: payload,
      });
      expect(response.status()).toBeLessThan(500);
      expect(response.headers()['content-type'] || '').toMatch(/json/);
    });

    test.describe('with known signing secret', () => {
      test.beforeEach(() => {
        test.skip(!webhookSecret, 'Set PLAYWRIGHT_STRIPE_WEBHOOK_SECRET to the site webhook signing secret');
      });

      test('unsigned payload is rejected', async ({ request }) => {
        const response = await request.post(WEBHOOK_PATH, {
          headers: { 'Content-Type': 'application/json' },
          data: noopEventPayload(),
        });
        expect(response.status()).toBe(400);
      });

      test('stale timestamp is rejected as a replay', async ({ request }) => {
        const payload = noopEventPayload();
        const staleTimestamp = Math.floor(Date.now() / 1000) - 3600;
        const response = await request.post(WEBHOOK_PATH, {
          headers: {
            'Content-Type': 'application/json',
            'Stripe-Signature': stripeSignature(payload, webhookSecret!, staleTimestamp),
          },
          data: payload,
        });
        expect(response.status()).toBe(400);
      });

      test('correctly signed payload is accepted', async ({ request }) => {
        const payload = noopEventPayload();
        const response = await request.post(WEBHOOK_PATH, {
          headers: {
            'Content-Type': 'application/json',
            'Stripe-Signature': stripeSignature(payload, webhookSecret!, Math.floor(Date.now() / 1000)),
          },
          data: payload,
        });
        expect(response.status()).toBe(200);
        expect(await response.json()).toMatchObject({ received: true });
      });
    });
  });

  test.describe('organizer Connect REST', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginUser(page, organizerUser!, organizerPass!);
    });

    test('connect status returns JSON with settlement_mode', async ({ page }) => {
      const response = await page.request.get('/wp-json/lnkup/v1/financial/connect/status', {
        headers: { Accept: 'application/json' },
      });
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('status');
      expect(body).toHaveProperty('settlement_mode');
      expect(['destination', 'platform_treasury']).toContain(body.settlement_mode);
    });

    test('connect status explains itself when Connect is unavailable', async ({ page }) => {
      const response = await page.request.get('/wp-json/lnkup/v1/financial/connect/status', {
        headers: { Accept: 'application/json' },
      });
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      test.skip(body.available !== false, 'Connect is configured on this site');
      expect(['connect_not_configured', 'stripe_not_configured']).toContain(body.code);
      expect(typeof body.message).toBe('string');
      expect(body.message).not.toMatch(/destination|settlement/i);
    });

    test('onboarding link returns structured JSON when Connect unavailable', async ({ page }) => {
      const response = await page.request.post('/wp-json/lnkup/v1/financial/connect/onboarding-link', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: {},
      });
      expect(response.status()).toBeLessThan(500);
      const body = await response.json();
      expect(typeof body).toBe('object');
      if (body.success === false) {
        expect(body).toHaveProperty('code');
        expect(body).toHaveProperty('message');
      }
    });

    test('organizer financial shell shows Connect banner area', async ({ page }) => {
      await page.goto('/organizer-dashboard/#financial');
      await expect(page.locator('#lnkup-fin-connect-banner')).toBeAttached();
      await expect(page.locator('#lnkup-organizer-financial')).toBeVisible();
    });

    test('onboarding failure shows an inline message and re-enables the button', async ({ page }) => {
      await page.route('**/lnkup/v1/financial/connect/status**', (route) =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'not_started',
            available: true,
            charges_enabled: false,
            payouts_enabled: false,
            settlement_mode: 'platform_treasury',
          }),
        })
      );
      await page.route('**/lnkup/v1/financial/connect/onboarding-link**', (route) =>
        route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            success: false,
            code: 'stripe_api_error',
            message: ONBOARDING_ERROR,
            status: 'not_started',
          }),
        })
      );

      await page.goto('/organizer-dashboard/#financial');
      const banner = page.locator('#lnkup-fin-connect-banner');
      const setupButton = banner.getByRole('button', { name: /finish payout setup/i });
      await expect(setupButton).toBeVisible();

      await setupButton.click();

      await expect(banner.getByRole('alert')).toHaveText(ONBOARDING_ERROR);
      await expect(setupButton).toBeEnabled();
      await expect(page).toHaveURL(/organizer-dashboard/);
    });

    test('connect refresh endpoint returns JSON', async ({ page }) => {
      const response = await page.request.post('/wp-json/lnkup/v1/financial/connect/refresh', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: {},
      });
      expect(response.status()).toBeLessThan(500);
      const contentType = response.headers()['content-type'] || '';
      expect(contentType).toMatch(/json/);
    });
  });

  test.describe('vendor isolation from Connect', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginUser(page, vendorUser!, vendorPass!);
    });

    test('vendor financial shell has no Connect onboarding CTA', async ({ page }) => {
      await page.goto('/vendor-dashboard/#financial');
      await expect(page.locator('#lnkup-vendor-financial')).toBeVisible();
      await expect(page.getByRole('button', { name: /connect stripe/i })).toHaveCount(0);
      await expect(page.locator('#lnkup-fin-connect-banner')).toHaveCount(0);
    });

    test('vendor cannot access organizer connect onboarding route', async ({ page }) => {
      const response = await page.request.post('/wp-json/lnkup/v1/financial/connect/onboarding-link', {
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        data: {},
      });
      expect([401, 403]).toContain(response.status());
    });
  });
});
