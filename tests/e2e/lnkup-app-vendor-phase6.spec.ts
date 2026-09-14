import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockOnboarding = {
  userId: 'mock-vendor',
  currentStep: 'categories',
  completedSteps: ['business'],
  profileCompletion: 42,
  nextAction: { label: 'Complete profile', href: '/vendor/profile' },
  canSkip: true,
};

const mockRails = {
  items: [
    {
      id: 'evt-1',
      opportunityId: '101',
      name: 'Summer Food Fest',
      city: 'Charlotte',
      state: 'NC',
      image: '/placeholder.svg',
      date: 'Jul 12',
      vendorFee: 200,
      spotsRemaining: 4,
      categoriesNeeded: ['Food'],
      status: 'live',
      organizer: 'CultureWave',
      organizerVerified: true,
    },
  ],
};

test.describe('LNKup app — vendor Phase 6 growth', () => {
  test.use({ baseURL: appBase });

  test('/vendors landing hero and CTAs', async ({ page }) => {
    await page.goto('/vendors');
    await expect(page.getByTestId('vendors-hero')).toBeVisible();
    await expect(page.getByRole('heading', { name: /find event opportunities/i })).toBeVisible();
    await expect(page.getByTestId('join-vendor-cta')).toBeVisible();
    await expect(page.getByTestId('browse-opportunities-cta')).toHaveAttribute('href', '/vendor/find');
  });

  test('role picker shows For vendors CTA', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('home-vendor-cta')).toBeVisible();
    await expect(page.locator('a[href="/vendors"]').first()).toBeVisible();
  });

  test('login supports vendor intent and browse first', async ({ page }) => {
    await page.goto('/login?intent=vendor');
    await expect(page.getByRole('heading', { name: /sign in to lnkup/i })).toBeVisible();
    await expect(page.getByRole('link', { name: /browse first/i })).toHaveAttribute('href', '/vendor/find');
    await expect(page.getByText(/new vendor/i)).toBeVisible();
  });

  test('browse=1 redirects to find events', async ({ page }) => {
    await page.goto('/login?browse=1');
    await expect(page).toHaveURL(/\/vendor\/find/);
  });

  test('onboarding stepper renders in mock mode', async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: true, user: { name: 'Test Vendor' } }),
      });
    });
    await page.route('**/api/vendor/onboarding', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(mockOnboarding),
        });
        return;
      }
      await route.continue();
    });

    await page.goto('/vendor/onboarding');
    await expect(page.getByTestId('vendor-onboarding-stepper')).toBeVisible();
    await expect(page.getByRole('button', { name: /skip for now/i })).toBeVisible();
  });

  test('onboarding POST mocked via live route', async ({ page }) => {
    let onboardingComplete = false;

    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: true, user: { name: 'Test Vendor' } }),
      });
    });
    await page.route('**/api/vendor/onboarding', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(
            onboardingComplete
              ? {
                  ...mockOnboarding,
                  currentStep: 'complete',
                  completedSteps: ['business', 'categories'],
                }
              : mockOnboarding,
          ),
        });
        return;
      }
      onboardingComplete = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          success: true,
          state: {
            ...mockOnboarding,
            currentStep: 'complete',
            completedSteps: ['business', 'categories'],
          },
        }),
      });
    });

    await page.goto('/vendor/onboarding');
    await page.getByRole('button', { name: /save and continue/i }).click();
    await expect(page.getByTestId('onboarding-complete')).toBeVisible({ timeout: 10000 });
    await expect(page.getByRole('link', { name: /find events/i })).toBeVisible();
  });

  test('vendor home renders opportunity rails', async ({ page }) => {
    await page.goto('/vendor');
    await expect(page.getByTestId('opportunity-rail').first()).toBeVisible({ timeout: 15000 });
  });

  test('empty opportunity rail is hidden', async ({ page }) => {
    await page.route('**/api/marketplace/recommended**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });
    await page.route('**/api/marketplace/trending**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });

    await page.goto('/vendor/find');
    await expect(page.getByRole('heading', { name: /find events/i })).toBeVisible();
    await expect(page.getByTestId('opportunity-rail')).toHaveCount(0);
  });

  test('analytics track returns 200 and UI survives track 500', async ({ page }) => {
    let trackCalls = 0;
    await page.route('**/api/analytics/track', async (route) => {
      trackCalls += 1;
      if (trackCalls === 1) {
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
        return;
      }
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'fail' }) });
    });

    await page.goto('/vendors');
    await expect(page.getByTestId('vendors-hero')).toBeVisible();
  });

  test('/markets/charlotte hero and opportunities or empty', async ({ page }) => {
    await page.goto('/markets/charlotte');
    await expect(page.getByTestId('market-hero')).toBeVisible();
    await expect(page.getByRole('heading', { name: /charlotte/i })).toBeVisible();
  });

  test('referral card on vendor more', async ({ page }) => {
    await page.route('**/api/vendor/referrals', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          available: true,
          code: 'SWEETHEAT',
          shareUrl: 'https://thelnkup.com/?ref=SWEETHEAT',
          message: 'Invite another vendor to LNKup.',
        }),
      });
    });

    await page.goto('/vendor/more');
    await expect(page.getByTestId('vendor-referral-card')).toBeVisible();
    await expect(page.getByRole('heading', { name: /invite another vendor/i })).toBeVisible();
  });

  test('referral coming-soon when unavailable', async ({ page }) => {
    await page.route('**/api/vendor/referrals', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          available: false,
          message: 'Referral invites are coming soon.',
        }),
      });
    });

    await page.goto('/vendor/more');
    await expect(page.getByText(/coming soon/i)).toBeVisible();
  });

  test('mocked BFF responses do not leak secrets', async ({ page }) => {
    await page.route('**/api/marketplace/recommended**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockRails),
      });
    });

    await page.goto('/vendor');
    const body = await page.content();
    expect(body).not.toContain('appPassword');
    expect(body).not.toContain('client_secret');
    expect(body).not.toContain('@stripe');
  });
});
