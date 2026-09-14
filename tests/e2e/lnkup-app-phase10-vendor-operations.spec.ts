import { test, expect, type Page } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockOperations = {
  items: [
    {
      requestId: 'req-2',
      eventName: 'Atlanta Food Fest',
      eventDate: 'Jul 15, 2026',
      city: 'Atlanta',
      state: 'GA',
      status: 'payment_required',
      passStatus: 'locked',
      readinessPercent: 25,
      readinessTier: 'at_risk',
      checklist: [{ id: 'payment', label: 'Payment complete', complete: false, severity: 'required' }],
      passUrl: '/vendor/pass/req-2',
      paymentUrl: '/vendor/requests?pay=req-2',
    },
    {
      requestId: 'req-1',
      eventName: 'Charlotte Night Market',
      eventDate: 'Jul 1, 2026',
      city: 'Charlotte',
      state: 'NC',
      status: 'event_day',
      passStatus: 'unlocked',
      readinessPercent: 88,
      readinessTier: 'ready',
      checklist: [{ id: 'pass', label: 'Pass unlocked', complete: true, severity: 'required' }],
      passUrl: '/vendor/pass/req-1',
      checkInCode: 'CHK-8842',
    },
    {
      requestId: 'req-3',
      eventName: 'Raleigh Summer Fair',
      eventDate: 'Aug 20, 2026',
      city: 'Raleigh',
      state: 'NC',
      status: 'pass_ready',
      passStatus: 'unlocked',
      readinessPercent: 75,
      readinessTier: 'on_track',
      checklist: [{ id: 'pass', label: 'Pass unlocked', complete: true, severity: 'required' }],
      passUrl: '/vendor/pass/req-3',
    },
  ],
};

const mockVendorRequests = {
  items: [
    {
      id: 'req-2',
      eventName: 'Atlanta Food Fest',
      eventDate: 'Jul 15, 2026',
      city: 'Atlanta',
      state: 'GA',
      status: 'payment_required',
      amountDue: 320,
      currency: 'USD',
    },
    {
      id: 'req-1',
      eventName: 'Charlotte Night Market',
      eventDate: 'Jul 1, 2026',
      city: 'Charlotte',
      state: 'NC',
      status: 'paid',
      amountDue: 185,
      currency: 'USD',
    },
  ],
  total: 2,
};

const mockPacketLocked = {
  requestId: 'req-2',
  applicationId: 'req-2',
  status: 'locked',
  headline: 'Your vendor pass unlocks after payment',
  message: 'Complete payment to unlock your full event pass.',
  eventName: 'Atlanta Food Fest',
  eventDate: 'Jul 15, 2026',
  city: 'Atlanta',
  state: 'GA',
  unlocks: ['Full venue address', 'Load-in and parking details'],
};

const mockPacketUnlocked = {
  requestId: 'req-1',
  applicationId: 'req-1',
  status: 'unlocked',
  headline: "You're booked — your event pass is ready",
  eventName: 'Charlotte Night Market',
  eventDate: 'Jul 1, 2026',
  city: 'Charlotte',
  state: 'NC',
  venueAddress: '301 E 7th St, Charlotte, NC 28202',
  loadInTime: '2:00 PM – 4:00 PM',
  boothAssignment: 'Booth A-12',
  checkInCode: 'CHK-8842',
  qrCodeUrl: 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=CHK-8842',
  vendorRules: ['No open flames without permit'],
};

async function mockAuthenticated(page: Page) {
  await page.route('**/api/auth/me', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        authenticated: true,
        user: { id: 1, name: 'Test Vendor', username: 'vendor' },
        roles: { vendor: true },
      }),
    });
  });
}

async function mockVendorOperations(page: Page, items = mockOperations.items) {
  await page.route('**/api/vendor/operations', async (route) => {
    if (route.request().method() !== 'GET') {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ items }),
    });
  });
}

async function mockVendorPackets(page: Page) {
  await page.route('**/api/vendor/requests/*/packet', async (route) => {
    const url = route.request().url();
    const body = url.includes('req-1') ? mockPacketUnlocked : mockPacketLocked;
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(body),
    });
  });
}

async function mockVendorSupport(page: Page) {
  await page.route('**/api/vendor/operations/*/support', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
      return;
    }
    const body = JSON.parse(route.request().postData() ?? '{}');
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        status: 'submitted',
        item: {
          id: 'sup-new',
          requestId: 'req-1',
          topic: body.topic ?? 'general',
          message: body.message ?? '',
          status: 'open',
        },
      }),
    });
  });
}

async function mockVendorRequestsApi(page: Page) {
  await page.route('**/api/vendor/requests', async (route) => {
    if (route.request().method() !== 'GET' || route.request().url().includes('/packet')) {
      await route.continue();
      return;
    }
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(mockVendorRequests),
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

test.describe('LNKup app — Phase 10 vendor operations', () => {
  test.use({ baseURL: appBase });

  test.beforeEach(async ({ page }) => {
    await mockAuthenticated(page);
    await mockVendorOperations(page);
    await mockVendorPackets(page);
    await mockVendorSupport(page);
    await mockVendorRequestsApi(page);
    await mockVendorReadiness(page);
    await page.route('**/api/marketplace/favorites', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [], total: 0 }),
      });
    });
  });

  test('operations list shows grouped sections', async ({ page }) => {
    await page.goto('/vendor/operations');
    await expect(page.getByTestId('vendor-operations')).toBeVisible();
    await expect(page.getByTestId('vendor-operations-payment-required')).toBeVisible();
    await expect(page.getByTestId('vendor-operations-event-day')).toBeVisible();
    await expect(page.getByTestId('vendor-operations-pass-ready')).toBeVisible();
    await expect(page.getByText('Atlanta Food Fest')).toBeVisible();
    await expect(page.getByText('Charlotte Night Market')).toBeVisible();
  });

  test('operations empty state', async ({ page }) => {
    await mockVendorOperations(page, []);
    await page.goto('/vendor/operations');
    await expect(page.getByText(/no active events/i)).toBeVisible();
  });

  test('operations error state', async ({ page }) => {
    await page.route('**/api/vendor/operations', async (route) => {
      await route.fulfill({
        status: 500,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'operations_failed', message: 'Server error' }),
      });
    });
    await page.goto('/vendor/operations');
    await expect(page.getByRole('button', { name: /try again/i })).toBeVisible();
  });

  test('locked pass hides logistics and shows payment CTA', async ({ page }) => {
    await page.goto('/vendor/pass/req-2');
    await expect(page.getByTestId('vendor-packet-locked')).toBeVisible();
    await expect(page.getByTestId('packet-continue-payment')).toBeVisible();
    await expect(page.getByTestId('vendor-pass-venue')).toHaveCount(0);
    await expect(page.getByTestId('vendor-pass-directions')).toHaveCount(0);
  });

  test('unlocked pass shows QR, logistics, and directions', async ({ page }) => {
    await page.goto('/vendor/pass/req-1');
    await expect(page.getByTestId('vendor-packet-unlocked')).toBeVisible();
    await expect(page.getByTestId('vendor-pass-qr')).toBeVisible();
    await expect(page.getByTestId('vendor-pass-venue')).toBeVisible();
    await expect(page.getByTestId('vendor-pass-loadin')).toBeVisible();
    await expect(page.getByTestId('vendor-pass-directions')).toBeEnabled();
    await expect(page.getByTestId('vendor-pass-calendar')).toBeEnabled();
  });

  test('calendar download triggers ICS fetch', async ({ page }) => {
    let calendarRequested = false;
    await page.route('**/api/vendor/pass/req-1/calendar.ics', async (route) => {
      calendarRequested = true;
      await route.fulfill({
        status: 200,
        contentType: 'text/calendar',
        body: 'BEGIN:VCALENDAR\nEND:VCALENDAR',
        headers: { 'Content-Disposition': 'attachment; filename="lnkup-event-req-1.ics"' },
      });
    });
    await page.goto('/vendor/pass/req-1');
    await page.getByTestId('vendor-pass-calendar').click();
    await expect.poll(() => calendarRequested).toBe(true);
  });

  test('support modal validates and submits', async ({ page }) => {
    await page.goto('/vendor/pass/req-1');
    await page.getByTestId('vendor-support-open').click();
    await page.getByTestId('vendor-support-submit').click();
    await expect(page.getByText(/please enter a message/i)).toBeVisible();
    await page.getByTestId('vendor-support-message').fill('Where is vendor parking?');
    await page.getByTestId('vendor-support-submit').click();
    await expect(page.getByTestId('vendor-support-panel')).toContainText('Where is vendor parking?');
  });

  test('support handoff when BFF returns handoff_required', async ({ page }) => {
    await page.route('**/api/vendor/operations/req-1/support', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ items: [] }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'handoff_required',
          message: 'Support requests are handled in WordPress right now.',
          wordpressUrl: 'https://thelnkup.com/vendor-dashboard/?view=operations',
        }),
      });
    });
    await page.goto('/vendor/pass/req-1');
    await page.getByTestId('vendor-support-open').click();
    await page.getByTestId('vendor-support-message').fill('Need help with load-in');
    await page.getByTestId('vendor-support-submit').click();
    await expect(page.getByTestId('vendor-support-handoff')).toBeVisible();
  });

  test('unauthenticated packet fetch shows sign-in CTA', async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: true, user: { id: 1 }, roles: { vendor: true } }),
      });
    });
    await page.route('**/api/vendor/requests/req-1/packet', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'auth_required', message: 'Sign in' }),
      });
    });
    await page.goto('/vendor/pass/req-1');
    await expect(page.getByTestId('vendor-pass-sign-in')).toBeVisible();
  });

  test('requests card routes to pass for booked vendor', async ({ page }) => {
    await page.goto('/vendor/requests');
    const passLink = page.getByTestId('vendor-request-view-pass').first();
    await expect(passLink).toBeVisible();
    await passLink.click();
    await expect(page).toHaveURL(/\/vendor\/pass\//);
  });

  test('locked pass HTML does not leak full venue address', async ({ page }) => {
    await page.goto('/vendor/pass/req-2');
    const html = await page.content();
    expect(html).not.toContain('301 E 7th St');
  });
});
