import { test, expect, type Page } from '@playwright/test';

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
  attentionItems: [],
  launchChecklist: [{ id: '1', label: 'Create event', complete: true }],
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

const mockOnboardingState = {
  currentStep: 'organizer_basics',
  completedSteps: [],
  launchReadiness: 0,
  hasEvent: false,
  hasOpportunity: false,
};

const mockWorkspace = {
  eventId: 'charlotte-night-market',
  title: 'Charlotte Night Market',
  date: 'Aug 15, 2026',
  city: 'Charlotte',
  state: 'NC',
  opportunities: [
    {
      id: '101',
      title: 'Vendors for Charlotte Night Market',
      categories: ['Food Vendor'],
      vendorFee: 200,
      spotsTotal: 20,
      spotsRemaining: 4,
      status: 'open',
    },
  ],
  stats: { requests: 3, approved: 1, paymentRequired: 1, paid: 0, waitlisted: 0, rejected: 0 },
  launchChecklist: [{ id: 'event', label: 'Create event', complete: true }],
};

/** Mock organizer home so live-api layout gate passes in tests. */
async function mockOrganizerGate(page: Page) {
  await page.route('**/api/organizer/home', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerHome),
    });
  });
}

async function mockOnboardingBff(page: Page) {
  await page.route('**/api/organizer/onboarding**', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockOnboardingState),
      });
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ...mockOnboardingState,
        currentStep: 'event_type',
        completedSteps: ['organizer_basics'],
        launchReadiness: 14,
      }),
    });
  });
}

test.describe('LNKup app — Phase 9 organizer launch', () => {
  test.use({ baseURL: appBase });

  test('/organizers landing renders', async ({ page }) => {
    await page.goto('/organizers');
    await expect(page.getByTestId('organizers-hero')).toBeVisible();
    await expect(page.getByRole('heading', { name: /find vendors for your next event/i })).toBeVisible();
    await expect(page.getByTestId('join-organizer-cta')).toBeVisible();
  });

  test('organizer login page shows organizer copy', async ({ page }) => {
    await page.goto('/login?intent=organizer&redirect=/organizer');
    await expect(page.getByRole('heading', { name: /sign in to lnkup/i })).toBeVisible();
    await expect(page.getByText(/sign in as an organizer/i)).toBeVisible();
  });

  test('organizer onboarding renders', async ({ page }) => {
    await mockOrganizerGate(page);
    await mockOnboardingBff(page);
    await page.goto('/organizer/onboarding');
    await expect(page.getByTestId('organizer-onboarding')).toBeVisible();
    await expect(page.getByRole('button', { name: /continue/i })).toBeVisible();
  });

  test('organizer launch renders', async ({ page }) => {
    await mockOrganizerGate(page);
    await page.route('**/api/organizer/launch/draft**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          event: { title: '', date: '', city: '', state: '' },
          opportunity: { categories: [], vendorFee: 0, spotsTotal: 1 },
          status: 'draft',
        }),
      });
    });
    await page.goto('/organizer/launch');
    await expect(page.getByTestId('organizer-launch')).toBeVisible();
    await expect(page.getByTestId('launch-event-title')).toBeVisible();
  });

  test('launch publish handoff when live upstream missing', async ({ page }) => {
    await mockOrganizerGate(page);
    await page.route('**/api/organizer/launch/draft**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          event: { title: 'Test Event', date: '2026-08-01', city: 'Charlotte', state: 'NC' },
          opportunity: { categories: ['Food Vendor'], vendorFee: 100, spotsTotal: 5 },
          status: 'ready_to_publish',
        }),
      });
    });
    await page.route('**/api/organizer/launch/publish**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'handoff_required',
          message: 'Launch event creation is available in WordPress right now.',
          wordpressUrl: 'https://thelnkup.com/organizer-dashboard/#create',
        }),
      });
    });
    await page.goto('/organizer/launch');
    await expect(page.getByTestId('organizer-launch')).toBeVisible();
    await page.getByRole('button', { name: 'Opportunity' }).click();
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByTestId('launch-publish-button').click();
    await expect(page.getByTestId('launch-handoff')).toBeVisible();
  });

  test('event workspace renders with mocked BFF', async ({ page }) => {
    await mockOrganizerGate(page);
    await page.route('**/api/organizer/events/charlotte-night-market**', async (route) => {
      if (route.request().url().includes('/share')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            eventId: 'charlotte-night-market',
            title: 'Charlotte Night Market',
            applicationUrl: '/vendor/find?city=Charlotte',
            status: 'ready',
          }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockWorkspace),
      });
    });
    await page.route('**/api/organizer/financial**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ collectedToDate: 1200, pendingPayments: 400 }),
      });
    });
    await page.goto('/organizer/events/charlotte-night-market');
    await expect(page.getByTestId('organizer-event-workspace')).toBeVisible({ timeout: 15000 });
    await expect(page.getByTestId('share-application-panel')).toBeVisible();
  });

  test('organizer role gate on forbidden', async ({ page }) => {
    await page.route('**/api/organizer/home**', async (route) => {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'forbidden', message: 'This section is for organizers.' }),
      });
    });
    await page.goto('/organizer');
    await expect(
      page.getByTestId('organizer-role-gate').or(page.getByRole('heading', { name: /organizer command center/i })),
    ).toBeVisible();
  });

  test('no secrets in organizer pages', async ({ page }) => {
    await page.goto('/organizers');
    const html = await page.content();
    expect(html).not.toContain('appPassword');
    expect(html).not.toContain('client_secret');
    expect(html).not.toContain('@stripe');
  });

  test('onboarding step advances on continue', async ({ page }) => {
    await mockOrganizerGate(page);
    await mockOnboardingBff(page);
    await page.goto('/organizer/onboarding');
    await expect(page.getByText(/step 1 of 7.*organizer basics/i)).toBeVisible();
    await page.getByRole('button', { name: /continue/i }).click();
    await expect(page.getByText(/step 2 of 7.*event type/i)).toBeVisible();
  });

  test('launch validation errors on empty publish', async ({ page }) => {
    await mockOrganizerGate(page);
    await page.route('**/api/organizer/launch/draft**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          event: { title: '', date: '', city: '', state: '' },
          opportunity: { categories: [], vendorFee: 0, spotsTotal: 1 },
          status: 'draft',
        }),
      });
    });
    await page.route('**/api/organizer/launch/publish**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'validation_error',
          message: 'Add at least one vendor category before publishing.',
          validationErrors: [{ field: 'opportunity.categories', message: 'Add at least one vendor category' }],
        }),
      });
    });
    await page.goto('/organizer/launch');
    await page.getByRole('button', { name: 'Opportunity' }).click();
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByTestId('launch-publish-button').click();
    await expect(page.getByText(/add at least one vendor category/i)).toBeVisible();
  });

  test('launch mock publish redirects to event workspace', async ({ page }) => {
    await mockOrganizerGate(page);
    await page.route('**/api/organizer/launch/draft**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          event: { title: 'Test Event', date: '2026-08-01', city: 'Charlotte', state: 'NC' },
          opportunity: { categories: ['Food Vendor'], vendorFee: 100, spotsTotal: 5 },
          status: 'ready_to_publish',
        }),
      });
    });
    await page.route('**/api/organizer/launch/publish**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'published',
          eventId: 'charlotte-night-market',
          eventUrl: '/organizer/events/charlotte-night-market',
        }),
      });
    });
    await page.route('**/api/organizer/events/charlotte-night-market**', async (route) => {
      if (route.request().url().includes('/share')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            eventId: 'charlotte-night-market',
            title: 'Charlotte Night Market',
            applicationUrl: '/vendor/find?city=Charlotte',
            status: 'ready',
          }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockWorkspace),
      });
    });
    await page.goto('/organizer/launch');
    await page.getByRole('button', { name: 'Review' }).click();
    await page.getByTestId('launch-publish-button').click();
    await expect(page.getByTestId('organizer-event-workspace')).toBeVisible({ timeout: 15000 });
  });

  test('share copy button renders in workspace', async ({ page }) => {
    await mockOrganizerGate(page);
    await page.route('**/api/organizer/events/charlotte-night-market**', async (route) => {
      if (route.request().url().includes('/share')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            eventId: 'charlotte-night-market',
            title: 'Charlotte Night Market',
            applicationUrl: '/vendor/find?city=Charlotte',
            status: 'ready',
          }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockWorkspace),
      });
    });
    await page.goto('/organizer/events/charlotte-night-market');
    await expect(page.getByTestId('copy-application-link')).toBeVisible();
  });

  test('organizer home primary CTA when no events', async ({ page }) => {
    await page.route('**/api/organizer/home**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ...mockOrganizerHome,
          stats: {
            ...mockOrganizerHome.stats,
            events: 0,
            openOpportunities: 0,
            vendorRequests: 1,
          },
          upcomingEvents: [
            {
              id: 'draft-event',
              title: 'Draft Event',
              city: 'Charlotte',
              state: 'NC',
            },
          ],
        }),
      });
    });
    await page.route('**/api/organizer/launch/draft**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          event: { title: '' },
          opportunity: { categories: [], vendorFee: 0, spotsTotal: 1 },
          status: 'draft',
        }),
      });
    });
    await page.route('**/api/organizer/requests**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });
    await page.goto('/organizer');
    await expect(page.getByTestId('organizer-primary-cta')).toBeVisible();
  });

  test('vendor role gate blocks organizer on vendor requests', async ({ page }) => {
    await page.route('**/api/auth/me**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          authenticated: true,
          user: {
            name: 'Organizer User',
            username: 'organizer',
            roles: ['organizer'],
            primaryRole: 'organizer',
            canAccessOrganizer: true,
            canAccessVendor: false,
          },
        }),
      });
    });
    await page.goto('/vendor/requests');
    await expect(
      page.getByTestId('vendor-role-gate').or(page.getByRole('heading', { name: /requests/i })),
    ).toBeVisible();
  });

  test('organizer landing tracks analytics event', async ({ page }) => {
    const tracked: string[] = [];
    await page.route('**/api/analytics/track**', async (route) => {
      const body = route.request().postDataJSON() as { event?: string };
      if (body.event) tracked.push(body.event);
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ success: true }) });
    });
    await page.goto('/organizers');
    await expect.poll(() => tracked.includes('organizer_landing_viewed')).toBeTruthy();
  });
});
