import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockOrganizerHome = {
  stats: {
    events: 2,
    openOpportunities: 2,
    vendorRequests: 8,
    approvedVendors: 3,
    paymentRequired: 2,
    paidVendors: 1,
  },
  upcomingEvents: [
    {
      id: 'charlotte-night-market',
      title: 'Charlotte Night Market',
      date: 'Sat, Jul 18, 2026',
      city: 'Charlotte',
      state: 'NC',
      status: 'live',
      requestsCount: 12,
      approvedCount: 8,
      paidCount: 5,
    },
  ],
  attentionItems: [
    {
      id: 'pending-requests',
      label: '3 vendor requests awaiting review',
      href: '/organizer/vendors',
      severity: 'warning',
    },
  ],
  launchChecklist: [
    { id: '1', label: 'Create event', complete: true },
    { id: '2', label: 'Review vendor requests', complete: false },
  ],
  financial: {
    collectedToDate: 16140,
    pendingPayments: 8320,
    paidVendorCount: 1,
    paymentRequiredCount: 2,
    payoutStatus: 'active',
    currency: 'USD',
    wordpressFinancialUrl: 'https://thelnkup.com/organizer-dashboard/?view=financial',
  },
};

const mockOrganizerRequests = {
  items: [
    {
      id: 'in-1',
      applicationId: 'in-1',
      vendorId: 'sweet-heat-bbq',
      vendorProfileId: 'sweet-heat-bbq',
      vendorBusinessName: 'Sweet Heat BBQ',
      vendorCategory: 'Food Vendor',
      eventId: 'charlotte-night-market',
      eventName: 'Charlotte Night Market',
      submittedAt: 'Jun 2026',
      status: 'under_review',
      message: 'We would love to join.',
    },
    {
      id: 'in-5',
      applicationId: 'in-5',
      vendorBusinessName: 'Taco Temple',
      vendorCategory: 'Food Truck',
      eventName: 'Atlanta Food Fest',
      status: 'payment_required',
      paymentStatus: 'pending',
    },
    {
      id: 'in-6',
      applicationId: 'in-6',
      vendorBusinessName: 'Velvet Scoops',
      status: 'paid',
      eventName: 'Charlotte Night Market',
    },
    {
      id: 'in-7',
      applicationId: 'in-7',
      vendorBusinessName: 'Waitlist Vendor',
      status: 'waitlisted',
      eventName: 'Charlotte Night Market',
    },
    {
      id: 'in-8',
      applicationId: 'in-8',
      vendorBusinessName: 'Rejected Vendor',
      status: 'rejected',
      eventName: 'Charlotte Night Market',
    },
  ],
};

const mockOrganizerEvents = {
  items: [
    {
      id: 'charlotte-night-market',
      title: 'Charlotte Night Market',
      date: 'Sat, Jul 18, 2026',
      city: 'Charlotte',
      state: 'NC',
      status: 'live',
      requestsCount: 12,
      paidCount: 5,
      wordpressUrl: 'https://thelnkup.com/organizer-dashboard/?view=operations&event=charlotte-night-market',
    },
  ],
};

const mockOrganizerProfile = {
  id: 'sweet-heat-bbq',
  businessName: 'Sweet Heat BBQ',
  category: 'Food Vendor',
  citiesServed: ['Charlotte, NC'],
  statesServed: ['NC'],
  trustBadges: ['verified'],
  completedEvents: 34,
  rating: 4.9,
  applicationMessage: 'Excited to join your event lineup.',
};

/** Mock organizer BFF routes so live-api layout gate passes in tests. */
async function mockOrganizerBff(page: import('@playwright/test').Page) {
  await page.route('**/api/organizer/home', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerHome),
    });
  });
  await page.route('**/api/organizer/requests', async (route) => {
    if (route.request().method() !== 'GET') {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerRequests),
    });
  });
  await page.route('**/api/organizer/events', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerEvents),
    });
  });
  await page.route('**/api/organizer/financial', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerHome.financial),
    });
  });
  await page.route('**/api/organizer/vendors/**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerProfile),
    });
  });
}

test.describe('LNKup app — organizer Phase 4', () => {
  test.use({ baseURL: appBase });

  test('organizer home supports empty QA override', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer?empty=1');
    await expect(page.getByText('Welcome, organizer')).toBeVisible();
  });

  test('organizer home supports error QA override', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer?error=1');
    await expect(page.getByText('Something went wrong')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  });

  test('organizer home shows checklist and financial snapshot', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer');
    await expect(page.getByRole('heading', { name: 'Organizer Command Center' })).toBeVisible();
    await expect(page.getByText('Launch checklist')).toBeVisible();
    await expect(page.getByTestId('organizer-financial-snapshot')).toBeVisible();
  });

  test('organizer events list and WP dashboard link', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer/events');
    await expect(page.getByRole('heading', { name: 'Events' })).toBeVisible();
    await expect(page.getByTestId('organizer-event-card').first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'View requests' }).first()).toBeVisible();
  });

  test('organizer events empty QA override', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer/events?empty=1');
    await expect(page.getByText('No events yet')).toBeVisible();
  });

  test('organizer vendor requests show status chips', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer/vendors');
    await expect(page.getByRole('heading', { name: 'Vendors' })).toBeVisible();
    await expect(page.getByTestId('organizer-vendor-request-card').first()).toBeVisible();
    await expect(page.getByText('Under review').first()).toBeVisible();
  });

  test('organizer reject requires reason', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer/vendors');
    await page.getByTestId('organizer-reject').first().click();
    await expect(page.getByTestId('organizer-reject-dialog')).toBeVisible();
    await expect(page.getByTestId('organizer-reject-confirm')).toBeDisabled();
    await page.getByLabel('Reason').fill('Category is full.');
    await expect(page.getByTestId('organizer-reject-confirm')).toBeEnabled();
  });

  test('organizer profile drawer opens from request card', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer/vendors');
    await page.getByTestId('organizer-view-profile').first().click();
    await expect(page.getByTestId('organizer-profile-drawer')).toBeVisible();
    await expect(
      page.getByTestId('organizer-profile-drawer').getByRole('heading', { name: 'Sweet Heat BBQ' }),
    ).toBeVisible();
  });

  test('organizer money page shows read-only financial snapshot', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer/money');
    await expect(page.getByRole('heading', { name: 'Money' })).toBeVisible();
    await expect(page.getByTestId('organizer-financial-snapshot')).toBeVisible();
    await expect(page.getByRole('tab', { name: 'Wallet' })).toBeDisabled();
    await expect(page.getByRole('tab', { name: 'Cashout' })).toBeDisabled();
  });

  test('live organizer home shows role gate when forbidden', async ({ page }) => {
    await page.route('**/api/organizer/home', async (route) => {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'forbidden', message: "You don't have access." }),
      });
    });

    await page.goto('/organizer');
    await expect(page.getByTestId('organizer-role-gate')).toBeVisible();
    await expect(page.getByText('This section is for organizers')).toBeVisible();
  });

  test('live organizer home shows sign-in gate when unauthenticated', async ({ page }) => {
    await page.route('**/api/organizer/home', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'not_authenticated', message: 'Please sign in.' }),
      });
    });

    await page.goto('/organizer');
    await expect(page.getByTestId('organizer-role-gate')).toBeVisible();
    await expect(page.getByText('Sign in to continue')).toBeVisible();
  });

  test('organizer review approve via mocked BFF', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.route('**/api/organizer/requests/in-1/review', async (route) => {
      const body = route.request().postDataJSON() as { action?: string };
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, applicationId: 'in-1', status: body.action }),
      });
    });

    await page.goto('/organizer/vendors');
    await page.getByTestId('organizer-approve').first().click();
    await expect(page.getByText('Approved').first()).toBeVisible({ timeout: 5000 });
  });

  test('organizer review unavailable disables actions message', async ({ page }) => {
    await page.route('**/api/organizer/home', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockOrganizerHome),
      });
    });
    await page.route('**/api/organizer/requests', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ...mockOrganizerRequests, reviewUnavailable: true }),
      });
    });

    await page.goto('/organizer/vendors');
    await expect(page.getByText('Review actions are available in the WordPress dashboard.')).toBeVisible();
  });

  test('organizer login redirect uses neutral copy', async ({ page }) => {
    await page.goto('/login?redirect=/organizer');
    await expect(page.getByRole('heading', { name: 'Sign in to LNKup' })).toBeVisible();
  });

  test('mocked organizer API responses omit secrets', async ({ page }) => {
    await mockOrganizerBff(page);
    await page.goto('/organizer');
    const content = await page.content();
    expect(content).not.toContain('client_secret');
    expect(content).not.toContain('appPassword');
  });
});
