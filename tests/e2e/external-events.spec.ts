import { test, expect } from '@playwright/test';

const adminUser = process.env.PLAYWRIGHT_ADMIN_USER || process.env.PLAYWRIGHT_ORGANIZER_USER;
const adminPass = process.env.PLAYWRIGHT_ADMIN_PASS || process.env.PLAYWRIGHT_ORGANIZER_PASS;
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

async function loginAdmin(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(adminUser!);
  await page.getByLabel(/^password$/i).fill(adminPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('External event discovery', () => {
  test('claim URL pre-selects organizer role on sign-up', async ({ page }) => {
    await page.goto('/sign-up/?role=organizer&claim_external_event=invalid-token-smoke');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.locator('input[name="lnkup_role"][value="lnkup_organizer"]')).toBeChecked();
  });

  test('claim landing page renders for invalid token gracefully', async ({ page }) => {
    await page.goto('/claim-event/?token=invalid-token-smoke');
    await expect(page.getByText(/invalid|expired/i)).toBeVisible();
  });

  test('external events search REST returns JSON', async ({ request }) => {
    const res = await request.get('/wp-json/lnkup/v1/external-events/search');
    expect(res.ok()).toBeTruthy();
    const ct = res.headers()['content-type'] || '';
    expect(ct).toContain('application/json');
    const data = await res.json();
    expect(data).toHaveProperty('items');
    expect(data).toHaveProperty('html');
  });

  test('external events dashboard REST returns JSON', async ({ request }) => {
    test.skip(!adminUser || !adminPass, 'Admin creds required for dashboard REST');
    const login = await request.post('/wp-login.php', {
      form: {
        log: adminUser!,
        pwd: adminPass!,
        'wp-submit': 'Log In',
        redirect_to: '/wp-json/lnkup/v1/external-events/dashboard',
        testcookie: '1',
      },
    });
    expect(login.ok()).toBeTruthy();
    const res = await request.get('/wp-json/lnkup/v1/external-events/dashboard');
    expect(res.ok()).toBeTruthy();
    const ct = res.headers()['content-type'] || '';
    expect(ct).toContain('application/json');
    const data = await res.json();
    expect(data).toHaveProperty('kpis');
  });

  test.describe('admin', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!adminUser || !adminPass, 'Set PLAYWRIGHT_ADMIN_USER/PASS or organizer creds');
      await loginAdmin(page);
    });

    test('external events admin import tab loads', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=lnkup-external-events&tab=import');
      await expect(page.getByTestId('lnkup-external-event-form')).toBeVisible();
      await expect(page.getByTestId('lnkup-external-csv-form')).toBeVisible();
      await expect(page.getByTestId('lnkup-external-url-form')).toBeVisible();
    });

    test('import-url REST returns JSON error for invalid Eventbrite URL', async ({ request }) => {
      test.skip(!adminUser || !adminPass, 'Admin creds required for import-url REST');
      await request.post('/wp-login.php', {
        form: {
          log: adminUser!,
          pwd: adminPass!,
          'wp-submit': 'Log In',
          redirect_to: '/wp-admin/',
          testcookie: '1',
        },
      });
      const res = await request.post('/wp-json/lnkup/v1/external-events/import-url', {
        data: {
          url: 'https://www.eventbrite.com/e/lnkup-e2e-invalid-event-000000000000',
          preview: true,
        },
      });
      expect(res.status()).toBeGreaterThanOrEqual(400);
      const ct = res.headers()['content-type'] || '';
      expect(ct).toContain('application/json');
      const data = await res.json();
      expect(data.code).toBeTruthy();
      expect(data.message).toBeTruthy();
      expect(data.message).not.toBe('Could not parse Eventbrite URL.');
    });

    test('external events review queue tab loads', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=lnkup-external-events&tab=review');
      await expect(page.getByTestId('lnkup-ext-review-kpi')).toBeVisible();
      await expect(page.getByTestId('lnkup-ext-review-filters')).toBeVisible();
      await expect(page.getByTestId('lnkup-ext-review-sort')).toBeVisible();
      await expect(page.getByTestId('lnkup-ext-review-bulk-bar')).toBeVisible();
      await expect(page.getByTestId('lnkup-ext-review-table')).toBeVisible();
      const reviewForm = page.locator('[data-testid^="lnkup-ext-review-form-"]').first();
      if (await reviewForm.count()) {
        await expect(reviewForm.locator('input[name="organizer_email"]')).toBeVisible();
      }
    });

    test('external events analytics tab loads', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=lnkup-external-events&tab=analytics');
      await expect(page.getByTestId('lnkup-external-analytics')).toBeVisible();
    });

    test('external events auto sync tab loads', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=lnkup-external-events&tab=sync');
      await expect(page.getByTestId('lnkup-external-autosync')).toBeVisible();
      await expect(page.getByRole('button', { name: /save auto sync settings/i })).toBeVisible();
      await expect(page.getByRole('button', { name: /run sync now/i })).toBeVisible();
      await expect(page.getByLabel(/vendor terms/i)).toBeVisible();
      await expect(page.getByRole('checkbox', { name: /require vendor terms/i })).toBeVisible();
    });

    test('external events needs-email filter and published email form', async ({ page }) => {
      await page.goto('/wp-admin/admin.php?page=lnkup-external-events&tab=events&needs_email=1');
      await expect(page.getByTestId('lnkup-external-events-table')).toBeVisible();
      await expect(page.getByRole('checkbox', { name: /needs organizer email/i })).toBeChecked();
      const emailForm = page.locator('[data-testid^="lnkup-ext-email-form-"]').first();
      if (await emailForm.count()) {
        await expect(emailForm.locator('input[name="organizer_email"]')).toBeVisible();
        await expect(emailForm.getByRole('button', { name: /save & notify organizer/i })).toBeVisible();
      }
    });
  });

  test.describe('vendor feed', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('find events page loads marketplace grid', async ({ page }) => {
      await page.goto('/opportunities/');
      const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
      const liveSearch = page.locator('#lnkup-search-input');
      await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
      if (await prelaunch.isVisible().catch(() => false)) {
        test.skip(true, 'Marketplace prelaunch gate active');
      }
      await expect(page.locator('#lnkup-results-grid')).toBeVisible();
    });

    test('external event cards show media and type badge when present', async ({ page }) => {
      await page.goto('/opportunities/');
      const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
      if (await prelaunch.isVisible().catch(() => false)) {
        test.skip(true, 'Marketplace prelaunch gate active');
      }
      const externalCard = page.getByTestId('lnkup-external-event-card').first();
      if (!(await externalCard.count())) {
        test.skip(true, 'No external events in feed');
      }
      await expect(externalCard).toBeVisible();
      await expect(externalCard.locator('.lnkup-event-card__media img')).toBeVisible();
      await expect(externalCard.locator('.lnkup-badge--accent')).toBeVisible();
      await expect(externalCard.locator('.lnkup-badge--external')).toBeVisible();
    });

    test('external event interest modal opens with structured form', async ({ page }) => {
      await page.goto('/opportunities/');
      const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
      if (await prelaunch.isVisible().catch(() => false)) {
        test.skip(true, 'Marketplace prelaunch gate active');
      }
      const interestBtn = page.locator('.lnkup-btn--external-interest').first();
      if (!(await interestBtn.count())) {
        test.skip(true, 'No external events in feed');
      }
      await interestBtn.click();
      const modal = page.locator('#lnkup-external-interest-modal');
      await expect(modal).toBeVisible();
      await expect(page.locator('#lnkup-external-interest-title')).toBeVisible();
      await expect(page.locator('#lnkup-external-interest-notice')).toBeVisible();
      await expect(page.locator('#lnkup-external-interest-form input[name="vendor_category"]')).toBeVisible();
      await expect(page.locator('#lnkup-external-interest-form input[name="booth_type"]')).toBeVisible();
      await expect(page.locator('#lnkup-external-interest-form input[name="power_needs"]')).toBeVisible();
      await expect(page.locator('#lnkup-external-interest-form textarea[name="message"]')).toBeVisible();
      await expect(page.locator('.lnkup-modal__close')).toBeVisible();
    });
  });

  test('claim landing mobile viewport smoke', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/claim-event/?token=invalid-token-smoke');
    await expect(page.getByText(/invalid|expired/i)).toBeVisible();
  });
});
