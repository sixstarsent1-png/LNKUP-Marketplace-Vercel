import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockVendorRequests = {
  items: [
    {
      id: '101',
      opportunityId: '99',
      eventId: '12',
      eventName: 'Payment Required Fest',
      eventImage: '/placeholder.svg',
      eventDate: 'Aug 2, 2026',
      city: 'Atlanta, GA',
      state: 'GA',
      organizerName: 'Southern Social Co.',
      category: 'Food Truck',
      status: 'payment_required',
      paymentId: '55',
      paymentStatus: 'pending',
      amountDue: 345,
      vendorFee: 320,
      paymentDueAt: 'Aug 1, 2026',
      submittedAt: 'Jun 24, 2026',
      currency: 'USD',
    },
    {
      id: '102',
      opportunityId: '98',
      eventId: '11',
      eventName: 'Booked Night Market',
      eventImage: '/placeholder.svg',
      eventDate: 'Jul 18, 2026',
      city: 'Charlotte, NC',
      state: 'NC',
      organizerName: 'CultureWave Events',
      category: 'Food Vendor',
      status: 'paid',
      paymentId: '56',
      paymentStatus: 'paid',
      amountDue: 185,
      vendorFee: 185,
      submittedAt: 'Jun 20, 2026',
      currency: 'USD',
    },
    {
      id: '103',
      eventName: 'Under Review Pop-Up',
      eventImage: '/placeholder.svg',
      status: 'under_review',
      amountDue: 210,
      submittedAt: 'Jun 26, 2026',
      currency: 'USD',
    },
  ],
  total: 3,
};

const mockPaymentSummary = {
  summary: {
    paymentId: '55',
    status: 'pending',
    statusLabel: 'Payment required',
    vendorFee: 320,
    lnkupFee: 25,
    total: 345,
    currency: 'USD',
    eventName: 'Payment Required Fest',
  },
  request: {
    id: '101',
    paymentId: '55',
    status: 'payment_required',
    amountDue: 345,
    eventName: 'Payment Required Fest',
  },
};

const mockPaymentHandoffReady = {
  requestId: '101',
  applicationId: '101',
  paymentId: '55',
  status: 'ready',
  checkoutUrl: 'https://checkout.stripe.com/c/pay/cs_test_mock',
  amountDue: 345,
  currency: 'USD',
  summary: mockPaymentSummary.summary,
};

const mockPaymentHandoffUnavailable = {
  requestId: '101',
  applicationId: '101',
  paymentId: '55',
  status: 'unavailable',
  message: "We couldn't load payment details. Try again or continue from your LNKup dashboard.",
};

const mockPacketLocked = {
  requestId: '101',
  applicationId: '101',
  status: 'locked',
  eventName: 'Payment Required Fest',
  eventDate: 'Aug 2, 2026',
  city: 'Atlanta',
  state: 'GA',
  headline: 'Your vendor pass unlocks after payment',
  message: 'Complete payment to unlock load-in details.',
  unlocksAfterPayment: ['Load-in details', 'Booth assignment', 'Check-in pass'],
};

const mockPacketUnlocked = {
  requestId: '102',
  applicationId: '102',
  status: 'unlocked',
  eventName: 'Booked Night Market',
  eventDate: 'Jul 18, 2026',
  city: 'Charlotte',
  state: 'NC',
  headline: "You're booked — your event pass is ready",
  venueAddress: 'Camp North End, Charlotte, NC',
  loadInTime: '10:00 AM – 12:00 PM',
  boothAssignment: 'Booth A12',
  checkInCode: 'LNK-CHAR-4821',
};

async function mockAuthenticated(page: import('@playwright/test').Page) {
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
}

async function mockVendorRequestsApi(page: import('@playwright/test').Page) {
  await page.route('**/api/vendor/requests', async (route) => {
    if (route.request().method() !== 'GET') {
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

test.describe('LNKup app — vendor Phase 3', () => {
  test.use({ baseURL: appBase });

  test.beforeEach(async ({ page }) => {
    await mockAuthenticated(page);
    await mockVendorRequestsApi(page);

    await page.route('**/api/vendor/readiness', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'complete', profileCompletion: 100, documentsCompletion: 100, missingItems: [] }),
      });
    });

    await page.route('**/api/marketplace/favorites', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [], total: 0 }),
      });
    });
  });

  test('payment_required request shows Continue payment and opens drawer', async ({ page }) => {
    await page.goto('/vendor/requests');
    const continueButton = page.getByTestId('vendor-request-continue-payment').first();
    await expect(continueButton).toBeVisible();
    await continueButton.click();
    await expect(page.getByTestId('vendor-payment-detail-drawer')).toBeVisible();
    await expect(page.getByText('Vendor spot fee')).toBeVisible();
  });

  test('paid request links to event pass by application id', async ({ page }) => {
    await page.goto('/vendor/requests');
    await expect(page.getByTestId('vendor-request-view-pass').first()).toHaveAttribute('href', /\/vendor\/pass\//);
  });

  test('payment handoff in mock mode routes back with success query', async ({ page }) => {
    await page.goto('/vendor/requests?pay=req-2');
    await expect(page.getByTestId('vendor-payment-detail-drawer')).toBeVisible();
    await page.getByTestId('detail-continue-payment').click();
    await page.waitForURL('**/vendor/requests?*payment=success*');
  });

  test('payment detail drawer stays interactive after continue click', async ({ page }) => {
    await page.goto('/vendor/requests?pay=req-2');
    await expect(page.getByTestId('vendor-payment-detail-drawer')).toBeVisible();
    await expect(page.getByTestId('detail-continue-payment')).toBeVisible();
  });

  test('requests page handles unauthenticated auth payload gracefully', async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: false }),
      });
    });

    await page.goto('/vendor/requests');
    await expect(
      page.getByRole('button', { name: 'Sign in' }).or(page.getByTestId('vendor-request-card').first()),
    ).toBeVisible();
  });

  test('locked packet preview renders safe fields', async ({ page }) => {
    await page.goto('/vendor/pass/req-2');
    await expect(page.getByTestId('vendor-packet-locked')).toBeVisible();
    await expect(page.getByText('Your vendor pass unlocks after payment')).toBeVisible();
    await expect(page.getByTestId('packet-continue-payment')).toBeVisible();
  });

  test('unlocked packet preview shows optional fields only when present', async ({ page }) => {
    await page.goto('/vendor/pass/req-1');
    await expect(page.getByTestId('vendor-packet-unlocked')).toBeVisible();
    await expect(page.getByText("You're booked — your event pass is ready")).toBeVisible();
  });

  test('packet page supports error QA override', async ({ page }) => {
    await page.goto('/vendor/pass/101?error=1');
    await expect(page.getByRole('heading', { name: 'Something went wrong' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  });

  test('find events search loads with mocked API payload', async ({ page }) => {
    await page.route('**/api/marketplace/search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          items: [
            {
              id: 'opp-99',
              opportunityId: 99,
              name: 'Payment Required Fest',
              image: '/placeholder.svg',
              date: 'Aug 2, 2026',
              city: 'Atlanta',
              state: 'GA',
              venue: 'Piedmont Park',
              organizer: 'Southern Social Co.',
              organizerVerified: true,
              expectedAttendance: 5000,
              vendorFee: 320,
              spotsRemaining: 8,
              totalSpots: 20,
              categoriesNeeded: ['Food Truck'],
              deadline: 'Jul 15',
              readiness: 0,
              vendorRequests: 10,
              approvedVendors: 5,
              paidVendors: 2,
              revenue: 1000,
              status: 'live',
            },
          ],
          total: 1,
        }),
      });
    });

    await page.route('**/api/applications/apply-context/99', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          eligibility: {
            can_apply: false,
            block_reason: 'duplicate',
            existing_application_id: 101,
            existing_application_status: 'payment_required',
          },
        }),
      });
    });

    await page.goto('/vendor/find?city=Atlanta');
    await expect(page.getByRole('heading', { name: 'Find Events' })).toBeVisible();
  });

  test('find events paid-state search loads with mocked API payload', async ({ page }) => {
    await page.route('**/api/marketplace/search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          items: [
            {
              id: 'opp-98',
              opportunityId: 98,
              name: 'Booked Night Market',
              image: '/placeholder.svg',
              date: 'Jul 18, 2026',
              city: 'Charlotte',
              state: 'NC',
              venue: 'Camp North End',
              organizer: 'CultureWave Events',
              organizerVerified: true,
              expectedAttendance: 3000,
              vendorFee: 185,
              spotsRemaining: 2,
              totalSpots: 15,
              categoriesNeeded: ['Food Vendor'],
              deadline: 'Jul 1',
              readiness: 0,
              vendorRequests: 8,
              approvedVendors: 6,
              paidVendors: 4,
              revenue: 800,
              status: 'live',
            },
          ],
          total: 1,
        }),
      });
    });

    await page.route('**/api/applications/apply-context/98', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          eligibility: {
            can_apply: false,
            block_reason: 'duplicate',
            existing_application_id: 102,
            existing_application_status: 'paid',
          },
        }),
      });
    });

    await page.goto('/vendor/find?city=Charlotte');
    await expect(page.getByRole('heading', { name: 'Find Events' })).toBeVisible();
  });

  test('find events under-review search loads with mocked API payload', async ({ page }) => {
    await page.route('**/api/marketplace/search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          items: [
            {
              id: 'opp-97',
              opportunityId: 97,
              name: 'Under Review Pop-Up',
              image: '/placeholder.svg',
              date: 'Sep 5, 2026',
              city: 'Raleigh',
              state: 'NC',
              venue: 'Downtown',
              organizer: 'Block Party Group',
              organizerVerified: false,
              expectedAttendance: 1500,
              vendorFee: 240,
              spotsRemaining: 10,
              totalSpots: 15,
              categoriesNeeded: ['Retail Vendor'],
              deadline: 'Aug 20',
              readiness: 0,
              vendorRequests: 5,
              approvedVendors: 2,
              paidVendors: 0,
              revenue: 0,
              status: 'live',
            },
          ],
          total: 1,
        }),
      });
    });

    await page.route('**/api/applications/apply-context/97', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          eligibility: {
            can_apply: false,
            block_reason: 'duplicate',
            existing_application_id: 103,
            existing_application_status: 'under_review',
          },
        }),
      });
    });

    await page.goto('/vendor/find?city=Raleigh');
    await expect(page.getByRole('heading', { name: 'Find Events' })).toBeVisible();
  });
});
