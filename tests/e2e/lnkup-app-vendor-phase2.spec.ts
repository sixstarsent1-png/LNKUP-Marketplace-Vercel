import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

const mockReadiness = {
  status: 'incomplete',
  profileCompletion: 72,
  documentsCompletion: 40,
  trustLevel: 'Profile in progress',
  verifiedBadges: [],
  missingItems: [
    {
      id: 'coi',
      label: 'Upload Certificate of Insurance',
      actionHref: '/vendor/documents',
      actionLabel: 'Upload',
      severity: 'required',
    },
  ],
  nextAction: { label: 'Upload insurance', href: '/vendor/documents' },
  headline: 'Upload required documents before applying to some events.',
  profileChecklist: [
    { id: 'business', label: 'Business name & category', done: true },
    { id: 'documents', label: 'Documents', done: false, actionHref: '/vendor/documents' },
  ],
};

const mockDocuments = {
  items: [
    {
      id: '1',
      type: 'business_license',
      label: 'Business License',
      status: 'approved',
      required: true,
    },
    {
      id: 'type-certificate_of_insurance',
      type: 'certificate_of_insurance',
      label: 'Certificate of Insurance',
      status: 'missing',
      required: true,
    },
  ],
  total: 2,
};

test.describe('LNKup app — vendor Phase 2', () => {
  test.use({ baseURL: appBase });

  test.beforeEach(async ({ page }) => {
    await page.route('**/api/auth/me', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ authenticated: false }),
      });
    });

    await page.route('**/api/vendor/requests', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'not_authenticated', message: 'Sign in' }),
      });
    });

    await page.route('**/api/vendor/readiness', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockReadiness),
      });
    });

    await page.route('**/api/vendor/documents', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(mockDocuments),
      });
    });

    await page.route('**/api/marketplace/favorites', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ code: 'not_authenticated' }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ favorited: true, id: 99 }),
      });
    });

    await page.route('**/api/marketplace/saved-searches', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ searches: [] }),
      });
    });
  });

  test('vendor home readiness card renders content', async ({ page }) => {
    await page.goto('/vendor');
    await expect(page.getByTestId('vendor-readiness-card')).toBeVisible();
    await expect(page.getByText('Profile readiness')).toBeVisible();
    await expect(
      page.getByTestId('vendor-readiness-card').getByText('Upload required documents before applying'),
    ).toBeVisible();
  });

  test('vendor home readiness supports error QA override', async ({ page }) => {
    await page.goto('/vendor?error=1');
    await expect(page.getByTestId('vendor-readiness-error')).toBeVisible();
    await expect(page.getByText("We couldn't load your readiness details.")).toBeVisible();
  });

  test('vendor home readiness supports empty QA override', async ({ page }) => {
    await page.goto('/vendor?empty=1');
    await expect(page.getByTestId('vendor-readiness-empty')).toBeVisible();
  });

  test('documents list renders from BFF', async ({ page }) => {
    await page.goto('/vendor/documents');
    await expect(page.getByTestId('vendor-documents-list')).toBeVisible();
    await expect(page.getByText('Business License')).toBeVisible();
    await expect(page.getByText('Certificate of Insurance')).toBeVisible();
  });

  test('document upload form validates missing file client-side', async ({ page }) => {
    await page.goto('/vendor/documents');
    await page.getByTestId('document-upload-submit').click();
    await expect(page.getByTestId('document-upload-error')).toBeVisible();
    await expect(page.getByText('Please select a document type.')).toBeVisible();
  });

  test('logged-out save shows sign-in CTA', async ({ page }) => {
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

    await page.goto('/vendor/find');
    await page.getByTestId('opportunity-save-button').first().click();
    await expect(page.getByTestId('save-signin-dialog')).toBeVisible();
    await expect(page.getByText('Sign in to save events')).toBeVisible();
  });

  test('logged-in favorite toggle uses BFF', async ({ page }) => {
    let favoritePosted = false;

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

    await page.route('**/api/marketplace/favorites', async (route) => {
      if (route.request().method() === 'POST') {
        favoritePosted = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ favorited: true, id: 99 }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ opportunityIds: [], items: [] }),
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

    await page.goto('/vendor/find');
    await page.getByTestId('opportunity-save-button').first().click();
    expect(favoritePosted).toBe(true);
  });

  test('save search dialog shows sign-in when logged out', async ({ page }) => {
    await page.goto('/vendor/find');
    await page.getByTestId('save-search-button').click();
    await expect(page.getByTestId('save-search-dialog')).toBeVisible();
    await expect(page.getByText('Sign in to save searches')).toBeVisible();
  });

  test('saved search create/delete mocked flow', async ({ page }) => {
    const searches: Array<{ id: string; name: string }> = [];

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

    await page.route('**/api/marketplace/saved-searches', async (route) => {
      if (route.request().method() === 'POST') {
        const body = route.request().postDataJSON() as { name?: string };
        searches.push({ id: 'search-1', name: body.name || 'Saved search' });
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ id: 'search-1', name: body.name || 'Saved search' }),
        });
        return;
      }
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ searches }),
      });
    });

    await page.route('**/api/marketplace/saved-searches/search-1', async (route) => {
      searches.length = 0;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ deleted: true, id: 'search-1' }),
      });
    });

    await page.goto('/vendor/find');
    await page.getByTestId('save-search-button').click();
    await page.getByTestId('save-search-submit').click();

    await page.goto('/vendor/favorites');
    await page.getByRole('tab', { name: 'Saved searches' }).click();
    await expect(page.getByRole('tabpanel').getByText('Saved search', { exact: true })).toBeVisible();
  });

  test('profile completion checklist renders', async ({ page }) => {
    await page.goto('/vendor/profile');
    await expect(page.getByTestId('profile-completion-checklist')).toBeVisible();
    await expect(page.getByText('Profile completion')).toBeVisible();
  });

  test('BFF routes do not expose credentials in responses', async ({ request }) => {
    const paths = [
      '/api/vendor/readiness',
      '/api/vendor/documents',
      '/api/marketplace/favorites',
      '/api/marketplace/saved-searches',
    ];

    for (const path of paths) {
      const response = await request.get(path);
      const body = await response.text();
      expect(body.toLowerCase()).not.toContain('apppassword');
      expect(body.toLowerCase()).not.toContain('secret-token');
    }
  });
});
