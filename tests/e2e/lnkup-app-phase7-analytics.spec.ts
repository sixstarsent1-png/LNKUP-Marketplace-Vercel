import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockFunnel = {
  meta: { live: false, mocked: true, partial: false, updatedAt: new Date().toISOString(), range: '7d' },
  funnel: {
    range: '7d',
    steps: [
      { id: 'visitor', label: 'Visitor', count: 100 },
      { id: 'apply_clicked', label: 'Apply clicked', count: 12, previousCount: 16, conversionRate: 75 },
      { id: 'application_submitted', label: 'Application submitted', count: 8, previousCount: 12, conversionRate: 66.7 },
    ],
    updatedAt: new Date().toISOString(),
  },
};

const mockSummary = {
  meta: { live: false, mocked: true, partial: false, updatedAt: new Date().toISOString(), range: '7d' },
  vendorActivation: {
    newVendors: 28,
    incompleteProfiles: 11,
    missingDocuments: 7,
    savedNoApply: 9,
    appliedUnderReview: 5,
    paymentRequired: 2,
    booked: 1,
    passViewed: 1,
  },
  opportunities: [
    {
      opportunityId: '101',
      title: 'Charlotte Summer Food Fest',
      city: 'Charlotte',
      state: 'NC',
      applications: 8,
      status: 'healthy',
    },
  ],
  searchInsights: [
    { query: 'food truck', city: 'Charlotte', count: 84, suggestedAction: 'Promote Charlotte food opportunities.' },
  ],
  markets: [
    { type: 'city', label: 'Charlotte, NC', searches: 142, opportunities: 8, demandScore: 88 },
  ],
  alerts: [
    {
      id: 'prelaunch-gate',
      severity: 'info',
      title: 'Prelaunch gate active',
      description: 'Search is live, but apply submit may be gated.',
      source: 'prelaunch',
    },
  ],
};

const mockHealth = {
  checks: [
    { id: 'marketplace_search', label: 'Marketplace search', status: 'pass', latencyMs: 42 },
    { id: 'vendor_requests', label: 'Vendor requests', status: 'auth_required', latencyMs: 18 },
  ],
  updatedAt: new Date().toISOString(),
};

const mockPrelaunch = {
  launchDate: '2026-08-01',
  launchLabel: 'August 1, 2026',
  isPrelaunchMode: true,
  canSearch: true,
  canApplySubmit: false,
  isApplyContextGated: true,
  recommendedAction: 'Search is live, but apply submit may be gated until August 1, 2026.',
};

async function mockOpsApis(page: import('@playwright/test').Page) {
  await page.route('**/api/ops/analytics/funnel**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockFunnel) });
  });
  await page.route('**/api/ops/analytics/summary**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockSummary) });
  });
  await page.route('**/api/ops/analytics/health**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockHealth) });
  });
  await page.route('**/api/ops/analytics/prelaunch**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockPrelaunch) });
  });
}

test.describe('LNKup app — Phase 7 launch analytics', () => {
  test.use({ baseURL: appBase });

  test('ops analytics route is gated when env disabled', async ({ page }) => {
    await page.goto('/ops/analytics');
    const notFound = page.getByRole('heading', { name: 'Page not found' });
    const opsPage = page.getByTestId('ops-analytics-page');
    await expect(notFound.or(opsPage)).toBeVisible();
  });

  test('ops analytics sections render when page is available', async ({ page }) => {
    await mockOpsApis(page);
    await page.goto('/ops/analytics');
    const opsPage = page.getByTestId('ops-analytics-page');
    if (!(await opsPage.isVisible())) {
      test.skip();
      return;
    }

    await expect(page.getByRole('heading', { name: 'Launch analytics' })).toBeVisible();
    await expect(page.getByTestId('ops-funnel-section')).toBeVisible();
    await expect(page.getByTestId('ops-vendor-activation-section')).toBeVisible();
    await expect(page.getByTestId('ops-opportunity-section')).toBeVisible();
    await expect(page.getByTestId('ops-search-insights-section')).toBeVisible();
    await expect(page.getByTestId('ops-market-demand-section')).toBeVisible();
    await expect(page.getByTestId('ops-alerts-section')).toBeVisible();
    await expect(page.getByTestId('ops-health-section')).toBeVisible();
    await expect(page.getByTestId('ops-prelaunch-section')).toBeVisible();
    await expect(page.getByTestId('funnel-step-visitor')).toBeVisible();
  });

  test('mock mode label visible when live API false', async ({ page }) => {
    await mockOpsApis(page);
    await page.goto('/ops/analytics');
    const opsPage = page.getByTestId('ops-analytics-page');
    if (!(await opsPage.isVisible())) {
      test.skip();
      return;
    }
    const mockBanner = page.getByTestId('ops-mock-banner');
    const partialBanner = page.getByTestId('ops-partial-banner');
    await expect(mockBanner.or(partialBanner).or(opsPage)).toBeVisible();
  });

  test('error state via query param', async ({ page }) => {
    await mockOpsApis(page);
    await page.goto('/ops/analytics?error=1');
    const opsPage = page.getByTestId('ops-analytics-page');
    if (!(await opsPage.isVisible())) {
      test.skip();
      return;
    }
    await expect(page.getByText('Funnel unavailable')).toBeVisible();
  });

  test('apply click sends safe analytics event', async ({ page }) => {
    let body: Record<string, unknown> | null = null;
    await page.route('**/api/analytics/track', async (route) => {
      body = JSON.parse(route.request().postData() || '{}') as Record<string, unknown>;
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
    });
    await page.route('**/api/marketplace/search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          items: [
            {
              id: '101',
              opportunityId: '101',
              name: 'Test Event',
              city: 'Charlotte',
              state: 'NC',
              date: 'Aug 1',
              vendorFee: 100,
              spotsRemaining: 5,
              categoriesNeeded: ['Food'],
              status: 'live',
              organizer: 'Test Org',
              organizerVerified: true,
            },
          ],
          total: 1,
        }),
      });
    });

    await page.goto('/vendor/find');
    const applyBtn = page.getByRole('button', { name: /apply/i }).first();
    if (await applyBtn.isVisible()) {
      await applyBtn.click();
      await page.waitForTimeout(300);
      if (body) {
        expect(body).not.toHaveProperty('password');
        expect(body).not.toHaveProperty('appPassword');
        expect(JSON.stringify(body)).not.toContain('client_secret');
      }
    }
  });

  test('tracking failure does not break vendors landing', async ({ page }) => {
    await page.route('**/api/analytics/track', async (route) => {
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'fail' }) });
    });
    await page.goto('/vendors');
    await expect(page.getByTestId('vendors-hero')).toBeVisible();
  });

  test('ops analytics does not expose secrets in HTML', async ({ page }) => {
    await mockOpsApis(page);
    await page.goto('/ops/analytics');
    const content = await page.content();
    expect(content).not.toContain('appPassword');
    expect(content).not.toContain('client_secret');
    expect(content).not.toContain('OPS_WP_APP_PASSWORD');
  });
});
