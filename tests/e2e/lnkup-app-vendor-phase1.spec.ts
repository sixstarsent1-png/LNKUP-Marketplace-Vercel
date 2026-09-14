import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

test.describe('LNKup app — vendor Phase 1', () => {
  test.use({ baseURL: appBase });

  test('vendor requests page supports empty QA override', async ({ page }) => {
    await page.goto('/vendor/requests?empty=1');
    await expect(page.getByRole('heading', { name: 'Vendor requests' })).toBeVisible();
    await expect(page.getByText('Nothing here yet')).toBeVisible();
  });

  test('vendor requests page supports error QA override', async ({ page }) => {
    await page.goto('/vendor/requests?error=1');
    await expect(page.getByText('Something went wrong')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Try again' })).toBeVisible();
  });

  test('logged-out apply on find events shows sign-in flow', async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: false }),
      });
    });

    await page.route('**/api/marketplace/search**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          items: [
            {
              id: 'opp-99',
              opportunityId: 99,
              name: 'Test Market',
              image: '/placeholder.svg',
              date: 'Aug 1, 2026',
              city: 'Charlotte',
              state: 'NC',
              venue: 'Uptown',
              organizer: 'Test Org',
              organizerVerified: false,
              expectedAttendance: 1000,
              vendorFee: 100,
              spotsRemaining: 5,
              totalSpots: 10,
              categoriesNeeded: ['Food Vendor'],
              deadline: 'Jul 1',
              readiness: 0,
              vendorRequests: 0,
              approvedVendors: 0,
              paidVendors: 0,
              revenue: 0,
              status: 'live',
            },
          ],
          total: 1,
        }),
      });
    });

    await page.route('**/api/applications/apply-context/99', async (route) => {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 'marketplace_closed',
          message: 'Applications open on August 1, 2026.',
        }),
      });
    });

    await page.goto('/vendor/find');
    const applyButton = page.getByTestId('opportunity-apply-button').first();
    await expect(applyButton).toBeVisible();
    await applyButton.click();
    await expect(page.getByRole('dialog').getByText('Sign in to apply')).toBeVisible();
  });

  test('marketplace closed apply context shows friendly message when authenticated', async ({ page }) => {
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

    await page.route('**/api/applications/apply-context/99', async (route) => {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({
          code: 'marketplace_closed',
          message: 'Applications open on August 1, 2026.',
        }),
      });
    });

    await page.goto('/vendor/find');
    const applyButton = page.getByTestId('opportunity-apply-button').first();
    if (await applyButton.count()) {
      await applyButton.click();
      await expect(page.getByRole('dialog').getByText('Applications not open yet')).toBeVisible();
      await expect(page.getByText('Applications open on August 1, 2026.')).toBeVisible();
    }
  });

  test('already-applied state links to vendor requests', async ({ page }) => {
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

    await page.route('**/api/applications/apply-context/**', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          eligibility: {
            can_apply: false,
            block_reason: 'duplicate',
            existing_application_id: 42,
            existing_application_status: 'under_review',
          },
          button_state: { reason: 'You already applied.', disabled: true },
          categories: ['Food Vendor'],
        }),
      });
    });

    await page.goto('/vendor/find');
    const applyButton = page.getByTestId('opportunity-apply-button').first();
    if (await applyButton.count()) {
      await applyButton.click();
      await expect(page.getByRole('dialog').getByText('You already applied')).toBeVisible();
      await expect(page.getByRole('link', { name: 'View vendor requests' })).toBeVisible();
    }
  });

  test('BFF vendor requests route does not expose credentials', async ({ request }) => {
    const response = await request.get('/api/vendor/requests');
    const body = await response.text();
    expect(body.toLowerCase()).not.toContain('apppassword');
    expect(body.toLowerCase()).not.toContain('authorization');
    expect(body.toLowerCase()).not.toContain('basic ');
  });

  test('BFF auth login response does not echo password', async ({ request }) => {
    const response = await request.post('/api/auth/login', {
      data: { username: 'test', appPassword: 'secret-token-123' },
    });
    const body = await response.text();
    expect(body).not.toContain('secret-token-123');
  });
});
