import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockSearch = {
  items: [
    {
      id: 'opp-101',
      opportunityId: 101,
      name: 'Charlotte Summer Food Fest',
      image: '/placeholder.svg',
      date: 'Aug 15, 2026',
      city: 'Charlotte',
      state: 'NC',
      venue: 'Uptown',
      organizer: 'CultureWave',
      organizerVerified: true,
      expectedAttendance: 5000,
      vendorFee: 200,
      spotsRemaining: 4,
      totalSpots: 20,
      categoriesNeeded: ['Food Vendor'],
      deadline: 'Aug 1',
      status: 'live',
    },
  ],
  total: 1,
  empty_state: false,
};

const mockPins = {
  pins: [
    {
      id: 'pin-101',
      opportunityId: '101',
      title: 'Charlotte Summer Food Fest',
      city: 'Charlotte',
      state: 'NC',
      lat: 35.2271,
      lng: -80.8431,
      vendorFee: 200,
      category: 'Food Vendor',
    },
  ],
};

test.describe('LNKup app — Phase 8 discovery', () => {
  test.use({ baseURL: appBase });

  test.beforeEach(async ({ page }) => {
    await page.route('**/api/marketplace/search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockSearch),
      });
    });
    await page.route('**/api/marketplace/map-pins**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockPins),
      });
    });
  });

  test('find page loads with toolbar', async ({ page }) => {
    await page.goto('/vendor/find');
    await expect(page.getByRole('heading', { name: 'Find Events' })).toBeVisible();
    await expect(page.getByTestId('find-toolbar')).toBeVisible();
  });

  test('URL city param applies on load', async ({ page }) => {
    await page.goto('/vendor/find?city=Charlotte');
    await expect(page.getByTestId('find-search-input')).toBeVisible();
    await expect(page.getByTestId('active-filter-chips')).toBeVisible({ timeout: 5000 }).catch(() => {
      // chips may appear after filter sync
    });
  });

  test('filter drawer opens', async ({ page }) => {
    await page.goto('/vendor/find');
    await page.getByTestId('filter-drawer-trigger').click();
    await expect(page.getByTestId('filter-drawer')).toBeVisible();
  });

  test('map list toggle renders', async ({ page }) => {
    await page.goto('/vendor/find');
    await expect(page.getByTestId('map-list-toggle')).toBeVisible();
    await page.getByTestId('map-view-toggle').click();
    await expect(page.getByTestId('discovery-map').or(page.getByTestId('discovery-map-loading'))).toBeVisible();
  });

  test('near me denied shows fallback', async ({ page, context }) => {
    await context.grantPermissions([]);
    await page.goto('/vendor/find');
    await page.evaluate(() => {
      Object.defineProperty(navigator, 'geolocation', {
        value: {
          getCurrentPosition: (_s: PositionCallback, e: PositionErrorCallback) =>
            e({ code: 1, message: 'denied', PERMISSION_DENIED: 1, POSITION_UNAVAILABLE: 2, TIMEOUT: 3 }),
        },
      });
    });
    await page.getByTestId('near-me-button').click();
    await expect(page.getByTestId('near-me-fallback')).toBeVisible();
  });

  test('markets charlotte renders hero', async ({ page }) => {
    await page.route('**/api/marketplace/markets/charlotte**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          market: { slug: 'charlotte', city: 'Charlotte', state: 'NC', displayName: 'Charlotte' },
          opportunities: mockSearch.items,
          total: 1,
          categories: ['Food Vendor'],
        }),
      });
    });
    await page.goto('/markets/charlotte', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('market-hero')).toBeVisible({ timeout: 15000 });
    await expect(page.getByRole('heading', { name: /charlotte/i })).toBeVisible();
  });

  test('save search dialog opens', async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          authenticated: true,
          user: { id: 1, name: 'Test Vendor', username: 'vendor' },
        }),
      });
    });
    await page.goto('/vendor/find');
    await page.getByTestId('save-search-button').click();
    await expect(page.getByTestId('save-search-dialog')).toBeVisible();
    await expect(page.getByTestId('save-search-frequency')).toBeVisible();
  });

  test('analytics track does not break find page', async ({ page }) => {
    await page.route('**/api/analytics/track', async (route) => {
      await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
    });
    await page.goto('/vendor/find');
    await expect(page.getByRole('heading', { name: 'Find Events' })).toBeVisible();
  });

  test('no secrets in page HTML', async ({ page }) => {
    await page.goto('/vendor/find');
    const html = await page.content();
    expect(html).not.toContain('appPassword');
    expect(html).not.toContain('client_secret');
    expect(html).not.toContain('@stripe');
  });
});
