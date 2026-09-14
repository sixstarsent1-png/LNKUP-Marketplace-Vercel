import { test, expect, type Page } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockWallet = {
  summary: {
    amountDue: 345,
    totalPaid: 185,
    availableCredits: 25,
    paymentRequiredCount: 1,
    paidBookingsCount: 1,
    currency: 'USD',
    wordpressFinancialUrl: 'https://example.com/vendor-dashboard/?view=payments',
  },
  paymentRequired: [
    {
      id: 'req-2',
      eventName: 'Atlanta Food Fest',
      eventDate: 'Sun, Aug 2, 2026',
      status: 'payment_required',
      vendorFee: 320,
      amountDue: 345,
      currency: 'USD',
      paymentId: 'pay-mock-2',
      paymentDueAt: 'Aug 1, 2026',
    },
  ],
};

const mockActivity = {
  items: [
    {
      id: 'act-1',
      type: 'payment',
      label: 'Booth payment',
      description: 'Charlotte Night Market',
      amount: 185,
      currency: 'USD',
      status: 'paid',
      createdAt: 'Jun 2026',
    },
  ],
};

const mockInvoices = {
  items: [
    {
      id: 'inv-1',
      type: 'invoice',
      number: 'INV-1001',
      eventName: 'Atlanta Food Fest',
      amount: 345,
      currency: 'USD',
      status: 'open',
    },
  ],
};

const mockReceipts = {
  items: [
    {
      id: 'rec-1',
      type: 'receipt',
      number: 'REC-8842',
      eventName: 'Charlotte Night Market',
      amount: 185,
      currency: 'USD',
      status: 'paid',
    },
  ],
};

const mockPaymentDetail = {
  summary: {
    paymentId: 'pay-mock-2',
    status: 'pending',
    vendorFee: 320,
    lnkupFee: 25,
    total: 345,
    currency: 'USD',
  },
  detail: {
    requestId: 'req-2',
    paymentId: 'pay-mock-2',
    eventName: 'Atlanta Food Fest',
    status: 'payment_required',
    vendorFee: 320,
    lnkupFee: 25,
    total: 345,
    currency: 'USD',
    timeline: [
      { id: 'approved', label: 'Approved', status: 'complete' },
      { id: 'payment', label: 'Payment required', status: 'current' },
    ],
  },
  request: {
    id: 'req-2',
    paymentId: 'pay-mock-2',
    status: 'payment_required',
    amountDue: 345,
    eventName: 'Atlanta Food Fest',
  },
};

const mockOrganizerMoney = {
  summary: {
    collectedToDate: 16140,
    pendingPayments: 8320,
    paidVendorCount: 22,
    paymentRequiredCount: 2,
    payoutStatus: 'active',
    currency: 'USD',
  },
};

const mockOrganizerActivity = {
  items: [
    {
      id: 'org-act-1',
      type: 'payment',
      label: 'Vendor booth payment',
      amount: 185,
      currency: 'USD',
      status: 'completed',
    },
  ],
};

const mockOrganizerEventsMoney = {
  items: [
    {
      eventId: 'charlotte-night-market',
      eventName: 'Charlotte Night Market',
      collected: 4070,
      pending: 832,
      paymentRequiredCount: 2,
      paidVendorCount: 22,
      currency: 'USD',
    },
  ],
};

const mockEventMoney = {
  summary: {
    eventId: 'charlotte-night-market',
    eventName: 'Charlotte Night Market',
    collected: 4070,
    pending: 832,
    paymentRequiredCount: 2,
    paidVendorCount: 22,
    currency: 'USD',
    recentActivity: mockOrganizerActivity.items,
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

async function mockVendorWalletApis(page: Page) {
  await page.route('**/api/vendor/wallet', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockWallet) });
  });
  await page.route('**/api/vendor/wallet/activity', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockActivity) });
  });
  await page.route('**/api/vendor/wallet/invoices', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockInvoices) });
  });
  await page.route('**/api/vendor/wallet/receipts', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockReceipts) });
  });
}

async function mockOrganizerMoneyApis(page: Page) {
  await page.route('**/api/organizer/money', async (route) => {
    if (route.request().url().includes('/activity') || route.request().url().includes('/events')) {
      await route.continue();
      return;
    }
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockOrganizerMoney) });
  });
  await page.route('**/api/organizer/money/activity', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockOrganizerActivity) });
  });
  await page.route('**/api/organizer/money/events', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockOrganizerEventsMoney) });
  });
}

test.describe('Phase 12 — Financial read-only', () => {
  test('vendor wallet shows summary, payment required, activity, and documents', async ({ page }) => {
    await mockAuthenticated(page, { vendor: true });
    await mockVendorWalletApis(page);

    await page.goto(`${appBase}/vendor/wallet`);
    await expect(page.getByTestId('vendor-wallet')).toBeVisible();
    await expect(page.getByTestId('vendor-wallet-summary')).toBeVisible();
    await expect(page.getByTestId('vendor-wallet-payment-required')).toBeVisible();
    await expect(page.getByTestId('payment-required-list')).toBeVisible();
    await expect(page.getByTestId('vendor-wallet-activity')).toBeVisible();
    await expect(page.getByTestId('payment-activity-list')).toBeVisible();
    await expect(page.getByTestId('financial-handoff-card')).toBeVisible();
  });

  test('vendor payment detail drawer opens and shows continue payment', async ({ page }) => {
    await mockAuthenticated(page, { vendor: true });
    await mockVendorWalletApis(page);

    await page.route('**/api/vendor/requests/req-2/payment', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockPaymentDetail) });
    });
    await page.route('**/api/vendor/requests/req-2/payment-link', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          requestId: 'req-2',
          status: 'ready',
          checkoutUrl: 'https://checkout.example.com/pay',
        }),
      });
    });

    await page.goto(`${appBase}/vendor/wallet`);
    await page.getByTestId('continue-payment-button').first().click();
    await expect(page.getByTestId('vendor-payment-detail-drawer')).toBeVisible();
    await expect(page.getByTestId('detail-continue-payment')).toBeVisible();
    await expect(page.getByTestId('payment-timeline')).toBeVisible();
  });

  test('organizer money is read-only without cashout actions', async ({ page }) => {
    await mockAuthenticated(page, { organizer: true });
    await mockOrganizerMoneyApis(page);

    await page.goto(`${appBase}/organizer/money`);
    await expect(page.getByTestId('organizer-money')).toBeVisible();
    await expect(page.getByTestId('organizer-financial-snapshot')).toBeVisible();
    await expect(page.getByTestId('financial-handoff-card')).toBeVisible();
    await expect(page.getByRole('button', { name: /cash out/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /refund/i })).toHaveCount(0);

    await page.getByRole('tab', { name: 'Activity' }).click();
    await expect(page.getByTestId('organizer-money-activity')).toBeVisible();

    await page.getByRole('tab', { name: 'Payouts' }).click();
    await expect(page.getByTestId('organizer-payouts-info')).toBeVisible();
  });

  test('organizer event workspace payments snapshot renders', async ({ page }) => {
    await mockAuthenticated(page, { organizer: true });

    await page.route('**/api/organizer/events/charlotte-night-market', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          eventId: 'charlotte-night-market',
          title: 'Charlotte Night Market',
          stats: { requests: 10, approved: 5, paymentRequired: 2, paid: 3 },
          opportunities: [],
        }),
      });
    });
    await page.route('**/api/organizer/events/charlotte-night-market/money', async (route) => {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(mockEventMoney) });
    });

    await page.goto(`${appBase}/organizer/events/charlotte-night-market`);
    await page.getByRole('tab', { name: 'Payments' }).click();
    await expect(page.getByTestId('organizer-event-payments')).toBeVisible();
    await expect(page.getByTestId('payment-activity-list')).toBeVisible();
  });

  test('no stripe SDK references in rendered vendor wallet HTML', async ({ page }) => {
    await mockAuthenticated(page, { vendor: true });
    await mockVendorWalletApis(page);

    await page.goto(`${appBase}/vendor/wallet`);
    const html = await page.content();
    expect(html.toLowerCase()).not.toContain('sk_live');
    expect(html.toLowerCase()).not.toContain('pk_live');
  });
});
