import { test, expect, type APIRequestContext, type Page } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const pendingPaymentId = process.env.PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID;
const foreignIntentId = process.env.PLAYWRIGHT_FOREIGN_PAYMENT_INTENT_ID;
const foreignPaymentId = process.env.PLAYWRIGHT_FOREIGN_PAYMENT_ID;
const foreignDocumentId = process.env.PLAYWRIGHT_FOREIGN_DOCUMENT_ID;
const freshOpportunityId = process.env.PLAYWRIGHT_FRESH_OPPORTUNITY_ID;
const freePaymentId = process.env.PLAYWRIGHT_FREE_BOOTH_PAYMENT_ID;
const simulationEnabled = process.env.PLAYWRIGHT_PAYMENT_SIMULATION === '1';

const PRIVATE_TYPES = [
  'lnkup_application',
  'lnkup_payment',
  'lnkup_document',
  'lnkup_packet',
  'lnkup_vendor_profile',
  'lnkup_vendor_note',
  'lnkup_vendor_flag',
  'lnkup_vendor_invite',
  'lnkup_vendor_list',
  'lnkup_review',
  'lnkup_dispute',
];

/**
 * Log into WordPress as the test vendor and return the REST nonce the dashboard exposes.
 */
async function loginVendor(page: Page): Promise<string> {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(vendorUser!);
    await page.getByLabel(/^password$/i).fill(vendorPass!);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForLoadState('domcontentloaded');
    if (!/wp-login/.test(page.url())) break;
  }
  await expect(page).not.toHaveURL(/wp-login/);
  await page.goto('/vendor-dashboard/?view=payments');
  const nonce = await page.evaluate(() => {
    const w = window as unknown as {
      lnkupAjax?: { restNonce?: string };
      wpApiSettings?: { nonce?: string };
    };
    return w.lnkupAjax?.restNonce || w.wpApiSettings?.nonce || '';
  });
  expect(nonce, 'vendor dashboard should expose a REST nonce').not.toBe('');
  return nonce;
}

/**
 * POST JSON to an lnkup/v1 route with the vendor's cookie session.
 */
function postJson(request: APIRequestContext, nonce: string, path: string, data: unknown) {
  return request.post(`/wp-json/lnkup/v1${path}`, {
    headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
    data,
  });
}

test.describe('Vendor data exposure', () => {
  for (const type of PRIVATE_TYPES) {
    test(`anonymous wp/v2/${type} is not routable`, async ({ request }) => {
      const response = await request.get(`/wp-json/wp/v2/${type}?per_page=1`);
      expect(response.status()).toBe(404);
      const body = await response.json();
      expect(body.code).toBe('rest_no_route');
    });
  }

  test('public event and opportunity listings stay readable', async ({ request }) => {
    const events = await request.get('/wp-json/wp/v2/lnkup_event?per_page=1&_fields=id');
    expect(events.ok()).toBeTruthy();
  });

  test('anonymous document download is refused', async ({ request }) => {
    test.skip(!foreignDocumentId, 'Set PLAYWRIGHT_FOREIGN_DOCUMENT_ID');
    const response = await request.get(`/wp-json/lnkup/v1/documents/${foreignDocumentId}/file`);
    expect([401, 403]).toContain(response.status());
  });

  test('native checkout return bounces to the app scheme without changing state', async ({ request }) => {
    const response = await request.get(
      '/wp-json/lnkup/v1/financial/checkout/native-return?payment=success&application_id=12',
      { maxRedirects: 0 },
    );
    expect(response.status()).toBe(302);
    expect(response.headers().location).toBe('lnkup://stripe-redirect?payment=success&applicationId=12');
  });

  test('native checkout return normalizes unknown states to cancelled', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/financial/checkout/native-return?payment=paid', {
      maxRedirects: 0,
    });
    expect(response.headers().location).toBe('lnkup://stripe-redirect?payment=cancelled');
  });

  test('unsigned Stripe webhook payloads are rejected', async ({ request }) => {
    const response = await request.post('/wp-json/lnkup/v1/stripe-webhook', {
      headers: { 'Content-Type': 'application/json' },
      data: { id: 'evt_test_forged', type: 'payment_intent.succeeded', data: { object: { id: 'pi_forged', metadata: {} } } },
    });
    expect(response.status()).toBe(400);
  });
});

test.describe('Vendor payment integrity', () => {
  let nonce = '';

  test.beforeEach(async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
    nonce = await loginVendor(page);
  });

  test('a simulated intent id cannot mark a real payment paid', async ({ page }) => {
    test.skip(!pendingPaymentId, 'Set PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID');
    test.skip(simulationEnabled, 'Simulation is intentionally enabled on this environment');
    const response = await postJson(page.request, nonce, `/financial/checkout/${pendingPaymentId}/confirm`, {
      intent_id: 'sim_forged_by_client',
    });
    expect(response.status()).toBe(400);
    const summary = await page.request.get(`/wp-json/lnkup/v1/financial/checkout/${pendingPaymentId}/summary`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    const body = await summary.json();
    expect(body.status).not.toBe('paid');
  });

  test('a succeeded intent from another booking is refused', async ({ page }) => {
    test.skip(!pendingPaymentId || !foreignIntentId, 'Set PLAYWRIGHT_VENDOR_PENDING_PAYMENT_ID and PLAYWRIGHT_FOREIGN_PAYMENT_INTENT_ID');
    const response = await postJson(page.request, nonce, `/financial/checkout/${pendingPaymentId}/confirm`, {
      intent_id: foreignIntentId,
    });
    expect(response.status()).toBe(400);
    const body = await response.json();
    expect(body.code).toBe('payment_mismatch');
  });

  test('credits cannot be applied to another vendor’s payment', async ({ page }) => {
    test.skip(!foreignPaymentId, 'Set PLAYWRIGHT_FOREIGN_PAYMENT_ID');
    const response = await postJson(page.request, nonce, '/financial/credits/apply', {
      payment_id: Number(foreignPaymentId),
      amount: 1,
    });
    expect(response.status()).toBe(403);
  });

  test('another vendor’s document cannot be downloaded', async ({ page }) => {
    test.skip(!foreignDocumentId, 'Set PLAYWRIGHT_FOREIGN_DOCUMENT_ID');
    const response = await page.request.get(`/wp-json/lnkup/v1/documents/${foreignDocumentId}/file`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    expect(response.status()).toBe(403);
  });

  test('a free booth confirms without a charge or vendor fee', async ({ page }) => {
    test.skip(!freePaymentId, 'Set PLAYWRIGHT_FREE_BOOTH_PAYMENT_ID');
    const prepare = await postJson(page.request, nonce, `/financial/checkout/${freePaymentId}/prepare`, {});
    expect(prepare.ok()).toBeTruthy();
    const body = await prepare.json();
    expect(body.paid).toBe(true);
    const summary = await page.request.get(`/wp-json/lnkup/v1/financial/checkout/${freePaymentId}/summary`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    const breakdown = (await summary.json()).breakdown || {};
    expect(Number(breakdown.vendor_checkout_fee || 0)).toBe(0);
    expect(Number(breakdown.total || 0)).toBe(0);
  });
});

test.describe('Vendor application integrity', () => {
  test.describe.configure({ mode: 'serial' });
  let nonce = '';
  let category = '';

  test.beforeEach(async ({ page }) => {
    test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
    test.skip(!freshOpportunityId, 'Set PLAYWRIGHT_FRESH_OPPORTUNITY_ID to an open opportunity the vendor has not applied to');
    nonce = await loginVendor(page);
  });

  test('parallel submissions create exactly one application', async ({ page }) => {
    const context = await page.request.get(`/wp-json/lnkup/v1/applications/apply-context/${freshOpportunityId}`, {
      headers: { 'X-WP-Nonce': nonce },
    });
    const ctx = await context.json();
    category = ctx.default_category || (ctx.categories || [])[0] || 'general';
    const payload = {
      opportunity_id: Number(freshOpportunityId),
      selected_category: category,
      accepted_terms: true,
      application_message: 'Playwright duplicate-submission check',
    };

    const responses = await Promise.all([
      postJson(page.request, nonce, '/applications/apply-with-profile', payload),
      postJson(page.request, nonce, '/applications/apply-with-profile', payload),
      postJson(page.request, nonce, '/applications/apply-with-profile', payload),
    ]);
    const statuses = responses.map((r) => r.status());
    expect(statuses.filter((s) => s === 200)).toHaveLength(1);
    for (const status of statuses.filter((s) => s !== 200)) {
      expect([409, 429]).toContain(status);
    }

    const list = await page.request.get('/wp-json/lnkup/v1/applications/vendor', { headers: { 'X-WP-Nonce': nonce } });
    const items: Array<{ opportunity_id: number }> = (await list.json()).items || [];
    expect(items.filter((item) => item.opportunity_id === Number(freshOpportunityId))).toHaveLength(1);
  });

  test('resubmitting after success returns the existing application', async ({ page }) => {
    const response = await postJson(page.request, nonce, '/applications/apply-with-profile', {
      opportunity_id: Number(freshOpportunityId),
      selected_category: category || 'Food Vendor',
      accepted_terms: true,
    });
    expect([409, 429]).toContain(response.status());
  });
});
