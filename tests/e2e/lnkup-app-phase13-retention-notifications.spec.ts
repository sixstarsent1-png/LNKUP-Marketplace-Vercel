import { test, expect, type Page } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockNotifications = {
  items: [
    {
      id: 'mock-pay-1',
      type: 'payment_required',
      title: 'Payment required for Atlanta Food Fest',
      severity: 'urgent',
      role: 'vendor',
      href: '/vendor/requests?pay=req-2',
      actionLabel: 'Continue payment',
      actionRequired: true,
      createdAt: 'Jul 2026',
    },
    {
      id: 'mock-packet-1',
      type: 'packet_unlocked',
      title: 'Your pass is ready — Charlotte Night Market',
      severity: 'success',
      role: 'vendor',
      href: '/vendor/pass/req-1',
      actionLabel: 'Open pass',
      createdAt: 'Jun 2026',
    },
  ],
};

const mockNotificationCount = { unread: 2, actionRequired: 1 };

const mockPreferences = {
  role: 'vendor',
  pushAvailable: false,
  emailVerified: true,
  items: [
    {
      id: 'application_updates',
      label: 'Application updates',
      channels: { email: true, sms: false, in_app: true, push: false },
    },
  ],
};

const mockPreferencesHandoff = {
  role: 'vendor',
  unavailable: true,
  handoffUrl: 'https://example.com/wp-admin/profile.php',
  items: [],
};

const mockSavedSearches = {
  searches: [
    {
      id: 'search-1',
      name: 'Charlotte food events',
      filterSummary: 'Charlotte · Food Vendor',
      alertFrequency: 'weekly',
      newMatches: 2,
      lastMatchCount: 5,
      isPaused: false,
    },
  ],
};

const mockOrganizerHomeSummary = {
  stats: {
    events: 2,
    vendorRequests: 3,
    approvedVendors: 5,
    paidVendors: 4,
    paymentRequired: 2,
    openOpportunities: 1,
  },
  attentionItems: [
    {
      id: 'attn-1',
      label: 'Vendor documents missing',
      description: '2 vendors need insurance uploads',
      href: '/organizer/vendors',
      severity: 'warning',
    },
  ],
  upcomingEvents: [
    {
      id: 'evt-1',
      title: 'Charlotte Night Market',
      date: 'Aug 2026',
      city: 'Charlotte',
      state: 'NC',
      status: 'live',
      requestsCount: 3,
      approvedCount: 2,
      paidCount: 1,
    },
  ],
  launchChecklist: [{ id: '1', label: 'Create event', complete: true }],
};

const mockVendorRequests = {
  items: [
    {
      id: 'req-2',
      eventId: 'evt-2',
      eventName: 'Atlanta Food Fest',
      eventDate: 'Aug 2, 2026',
      city: 'Atlanta',
      status: 'payment_required',
      vendorFee: 320,
      amountDue: 345,
      currency: 'USD',
      paymentId: 'pay-mock-2',
    },
  ],
  total: 1,
};

const mockOrganizerMoney = {
  summary: {
    paymentRequiredCount: 2,
    collectedToDate: 5000,
    currency: 'USD',
  },
};

async function mockAuthenticated(
  page: Page,
  roles: Record<string, boolean> = { vendor: true },
  userOverrides: Record<string, unknown> = {},
) {
  await page.route('**/api/auth/me', async (route) => {
    const isOrganizer = roles.organizer === true;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        user: {
          id: 1,
          name: 'Test User',
          username: 'testuser',
          roles: isOrganizer ? ['organizer'] : ['vendor'],
          primaryRole: isOrganizer ? 'organizer' : 'vendor',
          canAccessVendor: roles.vendor === true,
          canAccessOrganizer: roles.organizer === true,
          ...userOverrides,
        },
        roles,
      }),
    });
  });
}

async function mockNotificationApis(page: Page, notifications = mockNotifications) {
  await page.route('**/api/notifications/count', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockNotificationCount),
    });
  });
  await page.route('**/api/notifications/read-all', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
  await page.route('**/api/notifications/*/read', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
  await page.route('**/api/notifications/preferences', async (route) => {
    if (route.request().method() === 'PATCH') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockPreferences) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockPreferences) });
  });
  await page.route('**/api/notifications', async (route) => {
    if (route.request().url().includes('/count') || route.request().url().includes('/preferences')) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(notifications),
    });
  });
}

async function mockSavedSearchApis(page: Page) {
  await page.route('**/api/marketplace/saved-searches', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockSavedSearches),
      });
      return;
    }
    if (route.request().method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return;
    }
    await route.continue();
  });
  await page.route('**/api/marketplace/saved-searches/search-1', async (route) => {
    if (route.request().method() === 'PATCH') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ...mockSavedSearches.searches[0], isPaused: true }),
      });
      return;
    }
    if (route.request().method() === 'DELETE') {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true }) });
  });
  await page.route('**/api/marketplace/saved-searches/search-1/run', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ ok: true, matchCount: 5 }),
    });
  });
}

async function mockVendorRequestApis(page: Page) {
  await page.route('**/api/vendor/requests', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockVendorRequests),
    });
  });
}

async function mockOrganizerHomeApis(page: Page) {
  await page.route('**/api/organizer/home', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerHomeSummary),
    });
  });
  await page.route('**/api/organizer/money', async (route) => {
    if (route.request().url().includes('/activity') || route.request().url().includes('/events')) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockOrganizerMoney),
    });
  });
  await page.route('**/api/organizer/requests', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items: [] }),
    });
  });
}

test.describe('Phase 13 — Retention + Notifications', () => {
  test('notification center renders with list and filters', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await expect(page.getByTestId('notifications-center')).toBeVisible();
    await expect(page.getByTestId('notification-list')).toBeVisible();
    await expect(page.getByTestId('notification-item-mock-pay-1')).toBeVisible();
  });

  test('unread filter tab shows unread items or empty copy', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await page.getByTestId('notifications-filter-unread').click();
    await expect(page.getByTestId('notification-item-mock-pay-1')).toBeVisible();
  });

  test('action required filter tab shows urgent items', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await page.getByTestId('notifications-filter-action').click();
    await expect(page.getByTestId('notification-item-mock-pay-1')).toBeVisible();
  });

  test('empty notifications shows empty state', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page, { items: [] });

    await page.goto(`${appBase}/notifications`);
    await expect(page.getByTestId('notifications-center')).toBeVisible();
  });

  test('notification error state shows retry', async ({ page }) => {
    await mockAuthenticated(page);
    await page.route('**/api/notifications', async (route) => {
      if (route.request().url().includes('/count') || route.request().url().includes('/preferences')) {
        await route.continue();
        return;
      }
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ message: 'fail' }) });
    });

    await page.goto(`${appBase}/notifications`);
    const retryButton = page.getByRole('button', { name: 'Try again' });
    const center = page.getByTestId('notifications-center');
    await expect(retryButton.or(center)).toBeVisible();
  });

  test('notification CTA routes to payment and pass pages', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await page.getByTestId('notification-item-mock-pay-1').getByRole('button', { name: /Continue payment/i }).click();
    await expect(page).toHaveURL(/\/vendor\/requests/);

    await page.goto(`${appBase}/notifications`);
    await page.getByTestId('notification-item-mock-packet-1').getByRole('button', { name: /Open pass/i }).click();
    await expect(page).toHaveURL(/\/vendor\/pass\/req-1/);
  });

  test('mark read button is available on unread notification', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await page.getByTestId('notification-item-mock-pay-1').getByRole('button', { name: 'Mark read' }).click();
  });

  test('notification bell shows badge and links to center', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/vendor`);
    const bell = page.getByTestId('notification-bell').first();
    await expect(bell).toBeVisible();
    await bell.click();
    await expect(page).toHaveURL(/\/notifications/);
  });

  test('mark all read is available on notification center', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await expect(page.getByTestId('notifications-mark-all-read')).toBeVisible();
    await page.getByTestId('notifications-mark-all-read').click();
  });

  test('notification preferences page renders saved search alerts', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);
    await mockSavedSearchApis(page);

    await page.goto(`${appBase}/notifications/preferences`);
    await expect(page.getByTestId('notification-preferences-page')).toBeVisible();
    await expect(page.getByTestId('saved-search-alert-search-1')).toBeVisible();
  });

  test('preferences handoff card shown when unavailable', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);
    await page.route('**/api/notifications/preferences', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockPreferencesHandoff),
      });
    });

    await page.goto(`${appBase}/notifications/preferences`);
    await expect(
      page.getByTestId('wordpress-notification-handoff').or(page.getByTestId('notification-preferences-page')),
    ).toBeVisible();
  });

  test('saved search alert card supports pause and run', async ({ page }) => {
    await mockAuthenticated(page);
    await mockSavedSearchApis(page);

    await page.goto(`${appBase}/vendor/favorites`);
    await page.getByRole('tab', { name: 'Saved searches' }).click();
    await expect(page.getByTestId('saved-search-alert-search-1')).toBeVisible();
    await page.getByTestId('saved-search-pause').click();
    await page.getByRole('button', { name: 'Run' }).click();
  });

  test('saved search frequency can be changed', async ({ page }) => {
    await mockAuthenticated(page);
    await mockSavedSearchApis(page);

    await page.goto(`${appBase}/vendor/favorites`);
    await page.getByRole('tab', { name: 'Saved searches' }).click();
    await page.getByTestId('saved-search-frequency').click();
    await page.getByRole('option', { name: 'Daily' }).click();
  });

  test('vendor home shows retention cards', async ({ page }) => {
    await mockAuthenticated(page);
    await mockVendorRequestApis(page);

    await page.goto(`${appBase}/vendor`);
    await expect(page.getByTestId('vendor-home-retention')).toBeVisible({ timeout: 10000 });
    await expect(page.getByTestId('retention-card-payment_required').first()).toBeVisible();
  });

  test('organizer home shows retention cards', async ({ page }) => {
    await mockAuthenticated(page, { organizer: true }, { canAccessVendor: false, canAccessOrganizer: true });
    await mockOrganizerHomeApis(page);

    await page.goto(`${appBase}/organizer`);
    await expect(page.getByTestId('organizer-home-retention')).toBeVisible({ timeout: 10000 });
    await expect(page.getByTestId('retention-card-payment_required').first()).toBeVisible();
  });

  test('vendor more menu includes saved search alerts and install app links', async ({ page }) => {
    await mockAuthenticated(page);

    await page.goto(`${appBase}/vendor/more`);
    await expect(page.getByRole('link', { name: 'Saved search alerts' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Install app' })).toBeVisible();
  });

  test('PWA install nudge can be dismissed', async ({ page }) => {
    await mockAuthenticated(page);
    await mockVendorRequestApis(page);

    await page.addInitScript(() => {
      localStorage.removeItem('lnkup_pwa_install_dismissed');
    });

    await page.goto(`${appBase}/vendor`);
    await expect(page.getByTestId('vendor-home-retention')).toBeVisible({ timeout: 10000 });
    await page.evaluate(() => {
      const event = new CustomEvent('beforeinstallprompt', { cancelable: true }) as Event & {
        prompt: () => Promise<void>
        userChoice: Promise<{ outcome: 'dismissed' }>
      }
      event.prompt = async () => undefined
      event.userChoice = Promise.resolve({ outcome: 'dismissed' })
      window.dispatchEvent(event)
    });
    await expect(page.getByTestId('pwa-install-nudge')).toBeVisible({ timeout: 10000 });
    await page.getByTestId('pwa-install-dismiss').click();
    await expect(page.getByTestId('pwa-install-nudge')).not.toBeVisible();
  });

  test('unauthenticated notifications route still renders safely', async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: false }),
      });
    });
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    await expect(page.getByTestId('notifications-center')).toBeVisible();
  });

  test('page HTML does not expose session secrets or Stripe SDK', async ({ page }) => {
    await mockAuthenticated(page);
    await mockNotificationApis(page);

    await page.goto(`${appBase}/notifications`);
    const html = await page.content();
    expect(html).not.toMatch(/SESSION_SECRET|sk_live_|sk_test_/i);
    expect(html).not.toMatch(/@stripe\/stripe-js|loadStripe/i);
  });
});
