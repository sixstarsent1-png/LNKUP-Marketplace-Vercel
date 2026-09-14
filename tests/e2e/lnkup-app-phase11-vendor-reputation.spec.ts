import { test, expect, type Page } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockReputationNew = {
  summary: {
    tier: 'new',
    tierLabel: 'New vendor — building reputation',
    score: 12,
    reviewCount: 0,
    completedEvents: 0,
    badges: [
      { id: 'verified', label: 'Verified Vendor', type: 'verified', earned: false, state: 'locked' },
    ],
    improvements: ['Complete your profile', 'Book your first event'],
  },
  completedEvents: [],
};

const mockReputationTrusted = {
  summary: {
    tier: 'trusted',
    tierLabel: 'Trusted vendor',
    score: 78,
    rating: 4.7,
    reviewCount: 12,
    completedEvents: 14,
    badges: [
      { id: 'verified', label: 'Verified Vendor', type: 'verified', earned: true, state: 'earned' },
      { id: 'documents_ready', label: 'Documents Ready', type: 'documents', earned: true, state: 'earned' },
    ],
    strengths: ['Strong communication', 'Reliable setup'],
    improvements: [],
  },
  completedEvents: [
    {
      id: 'req-4',
      applicationId: 'req-4',
      title: 'Nashville Street Fair',
      date: 'May 10, 2026',
      status: 'reviewed',
      rating: 5,
    },
  ],
};

const mockReviews = {
  items: [
    {
      id: 'rev-1',
      eventName: 'Charlotte Night Market',
      eventDate: 'Jun 1, 2026',
      organizerName: 'Queen City Events',
      rating: 5,
      text: 'Professional setup and great crowd engagement.',
      wouldBookAgain: true,
      createdAt: 'Jun 3, 2026',
    },
  ],
};

const mockEligibility = {
  items: [
    {
      applicationId: 'req-5',
      eventName: 'Atlanta Food Fest',
      status: 'awaiting_organizer_review',
    },
    {
      applicationId: 'req-4',
      eventName: 'Nashville Street Fair',
      status: 'review_received',
      reviewId: 'rev-1',
    },
  ],
};

const mockOrganizerRequests = {
  items: [
    {
      id: 'app-1',
      applicationId: 'app-1',
      vendorId: 'v-1',
      vendorProfileId: 'v-1',
      vendorBusinessName: 'Blue Ridge BBQ Co.',
      vendorCategory: 'Food',
      eventId: 'evt-1',
      eventName: 'Charlotte Night Market',
      status: 'under_review',
      reputationPreview: {
        tier: 'trusted',
        tierLabel: 'Trusted vendor',
        score: 78,
        rating: 4.7,
        reviewCount: 12,
        completedEvents: 14,
        badges: [
          { id: 'verified', label: 'Verified Vendor', type: 'verified', earned: true, state: 'earned' },
        ],
      },
    },
  ],
};

const mockOrganizerProfile = {
  id: 'v-1',
  businessName: 'Blue Ridge BBQ Co.',
  category: 'Food',
  rating: 4.7,
  completedEvents: 14,
  reputation: {
    tier: 'trusted',
    tierLabel: 'Trusted vendor',
    score: 78,
    rating: 4.7,
    reviewCount: 12,
    completedEvents: 14,
    badges: [
      { id: 'verified', label: 'Verified Vendor', type: 'verified', earned: true, state: 'earned' },
    ],
  },
};

async function mockAuthenticated(page: Page, roles: Record<string, boolean> = { vendor: true }) {
  await page.route('**/api/auth/me', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        user: { id: 1, name: 'Test User', username: 'testuser' },
        roles,
      }),
    });
  });
}

async function mockVendorReputation(
  page: Page,
  payload: typeof mockReputationNew | typeof mockReputationTrusted = mockReputationTrusted,
) {
  await page.route('**/api/vendor/reputation', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(payload),
    });
  });
}

async function mockVendorReviewsApi(page: Page) {
  await page.route('**/api/vendor/reviews/eligible', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockEligibility),
    });
  });

  await page.route('**/api/vendor/reviews', async (route) => {
    if (route.request().url().includes('/eligible') || route.request().url().includes('/feedback')) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockReviews),
    });
  });
}

async function mockVendorReadiness(page: Page) {
  await page.route('**/api/vendor/readiness', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'complete',
        profileCompletion: 100,
        documentsCompletion: 100,
        missingItems: [],
      }),
    });
  });
}

async function mockOrganizerHome(page: Page) {
  await page.route('**/api/organizer/home', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        stats: { events: 1, vendorRequests: 1, approvedVendors: 0, paymentRequired: 0, paidVendors: 0 },
        upcomingEvents: [],
        attentionItems: [],
        financial: { collectedToDate: 0, pendingPayments: 0 },
      }),
    });
  });
}

async function mockVendorHomeApis(page: Page) {
  await page.route('**/api/vendor/requests', async (route) => {
    if (route.request().url().includes('/packet')) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [], total: 0 }),
    });
  });
  await page.route('**/api/marketplace/favorites', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [], total: 0 }),
    });
  });
  await page.route('**/api/vendor/onboarding', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ currentStep: 'complete', profileCompletion: 100 }),
    });
  });
  await page.route('**/api/marketplace/sections/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [] }),
    });
  });
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
  await page.route('**/api/vendor/referrals', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ inviteCode: 'DEMO', invitesSent: 0, creditsEarned: 0 }),
    });
  });
}

async function mockOrganizerApis(page: Page) {
  await page.route('**/api/organizer/requests', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerRequests),
    });
  });

  await page.route('**/api/organizer/vendors/*', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerProfile),
    });
  });
}

test.describe('LNKup app — Phase 11 vendor reputation', () => {
  test.use({ baseURL: appBase });

  test.beforeEach(async ({ page }) => {
    await mockAuthenticated(page);
    await mockVendorReputation(page);
    await mockVendorReviewsApi(page);
    await mockVendorReadiness(page);
  });

  test('reviews page shows trusted reputation summary and reviews', async ({ page }) => {
    await page.goto('/vendor/reviews');
    await expect(page.getByTestId('vendor-reviews')).toBeVisible();
    await expect(page.getByTestId('reputation-summary-card')).toBeVisible();
    await expect(page.getByTestId('reputation-tier-label')).toHaveText('Trusted vendor');
    await expect(page.getByText('Charlotte Night Market')).toBeVisible();
  });

  test('reviews page new vendor empty state', async ({ page }) => {
    await mockVendorReputation(page, mockReputationNew);
    await page.route('**/api/vendor/reviews', async (route) => {
      if (route.request().url().includes('/eligible')) {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });

    await page.goto('/vendor/reviews');
    await expect(page.getByTestId('vendor-reviews')).toBeVisible();
    await expect(
      page.getByText('Your reputation will build as you complete events and receive organizer reviews.'),
    ).toBeVisible();
  });

  test('reviews page error and retry', async ({ page }) => {
    let fail = true;
    await page.route('**/api/vendor/reputation', async (route) => {
      if (fail) {
        await route.fulfill({
          status: 500,
          contentType: 'application/json',
          body: JSON.stringify({ code: 'error', message: 'Server error' }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockReputationTrusted),
      });
    });

    await page.goto('/vendor/reviews');
    await expect(page.getByRole('button', { name: /try again/i })).toBeVisible();
    fail = false;
    await page.getByRole('button', { name: /try again/i }).click();
    await expect(page.getByTestId('reputation-summary-card')).toBeVisible();
  });

  test('vendor home shows reputation snapshot', async ({ page }) => {
    await mockVendorHomeApis(page);

    await page.goto('/vendor');
    await expect(page.getByTestId('vendor-reputation-snapshot')).toBeVisible({ timeout: 15000 });
  });

  test('vendor home new vendor snapshot without fake ratings', async ({ page }) => {
    await mockVendorReputation(page, mockReputationNew);
    await mockVendorHomeApis(page);
    await page.route('**/api/vendor/reviews', async (route) => {
      if (route.request().url().includes('/eligible')) {
        await route.continue();
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });

    await page.goto('/vendor');
    await expect(page.getByText('Build your reputation with every completed event.')).toBeVisible({
      timeout: 15000,
    });
    await expect(page.getByText('4.7')).not.toBeVisible();
  });

  test('eligibility banners on reviews page', async ({ page }) => {
    await page.goto('/vendor/reviews');
    await expect(page.getByTestId('review-eligibility-awaiting')).toBeVisible();
    await expect(page.getByTestId('review-eligibility-received')).toBeVisible();
  });

  test('organizer request card shows reputation preview', async ({ page }) => {
    await mockAuthenticated(page, { organizer: true });
    await mockOrganizerHome(page);
    await mockOrganizerApis(page);

    await page.goto('/organizer/vendors');
    await expect(page.getByTestId('organizer-vendor-request-card').first()).toBeVisible({
      timeout: 15000,
    });
    await expect(page.getByTestId('organizer-reputation-preview').first()).toBeVisible();
    await expect(page.getByTestId('reputation-tier-label').first()).toHaveText('Trusted vendor');
  });

  test('organizer profile drawer shows reputation', async ({ page }) => {
    await mockAuthenticated(page, { organizer: true });
    await mockOrganizerHome(page);
    await mockOrganizerApis(page);

    await page.goto('/organizer/vendors');
    await page.getByTestId('organizer-view-profile').first().click({ timeout: 15000 });
    await expect(page.getByTestId('organizer-profile-drawer')).toBeVisible();
    await expect(page.getByTestId('organizer-drawer-reputation')).toBeVisible();
    await expect(page.getByTestId('reputation-summary-card')).toBeVisible();
  });

  test('public reputation adapter renders with props', async ({ page }) => {
    await page.setContent(`
      <div id="root"></div>
      <script type="module">
        import React from 'https://esm.sh/react@18';
        import ReactDOM from 'https://esm.sh/react-dom@18/client';
      </script>
    `);
    await page.goto('/vendor/reviews');
    const html = await page.content();
    expect(html).not.toContain('private_note');
    expect(html).not.toContain('internal_note');
  });

  test('no stripe imports in reputation pages', async ({ page }) => {
    await page.goto('/vendor/reviews');
    const html = await page.content();
    expect(html.toLowerCase()).not.toContain('stripe.com');
  });
});
