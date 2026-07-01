import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Trust and verification', () => {
  test.describe('vendor dashboard', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('home shows trust status card', async ({ page }) => {
      await page.goto('/vendor-dashboard/');
      const card = page.getByTestId('lnkup-vendor-trust-card');
      await expect(card).toBeVisible();
      await expect(card.getByText(/your trust status/i)).toBeVisible();
      await expect(card.getByText(/basic vendor|profile complete|documents ready|verified|trust status/i)).toBeVisible({
        timeout: 15000,
      });
    });

    test('verification REST returns vendor status JSON', async ({ page }) => {
      const response = await page.request.get('/wp-json/lnkup/v1/verification/vendor', {
        headers: { Accept: 'application/json' },
      });
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('level');
      expect(body).toHaveProperty('percent_complete');
      expect(body).toHaveProperty('badges');
      expect(Array.isArray(body.not_applicable_documents)).toBeTruthy();
    });

    test('mobile trust card does not overflow', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/vendor-dashboard/');
      const card = page.getByTestId('lnkup-vendor-trust-card');
      await expect(card).toBeVisible();
      const box = await card.boundingBox();
      expect(box && box.width <= 390).toBeTruthy();
    });
  });

  test.describe('organizer dashboard', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('overview shows organizer trust strip', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      const strip = page.getByTestId('lnkup-organizer-trust-strip');
      await expect(strip).toBeVisible();
      await expect(strip.getByText(/organizer|payout|verification unavailable|trust/i)).toBeVisible({
        timeout: 15000,
      });
    });

    test('organizer verification REST returns payout fields', async ({ page }) => {
      const response = await page.request.get('/wp-json/lnkup/v1/verification/organizer', {
        headers: { Accept: 'application/json' },
      });
      expect(response.ok()).toBeTruthy();
      const body = await response.json();
      expect(body).toHaveProperty('level');
      expect(body).toHaveProperty('payout_connected');
      expect(body).toHaveProperty('badges');
    });

    test('create event form exposes document mode options', async ({ page }) => {
      await page.goto('/organizer-dashboard/#create');
      const mode = page.getByTestId('lnkup-document-mode');
      await expect(mode).toBeVisible({ timeout: 15000 });
      await expect(page.getByTestId('lnkup-doc-mode-none')).toBeVisible();
      await expect(page.getByTestId('lnkup-doc-mode-defaults')).toBeVisible();
      await expect(page.getByTestId('lnkup-doc-mode-custom')).toBeVisible();
    });
  });

  test.describe('application review trust signals', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('application cards may show trust badges and document context', async ({ page }) => {
      await page.goto('/organizer-dashboard/#applications');
      const cards = page.locator('.lnkup-crm-app-card--v2');
      const count = await cards.count();
      test.skip(count === 0, 'No application cards to inspect');
      const first = cards.first();
      await expect(first).toBeVisible();
      const trustBadges = first.getByTestId('lnkup-app-trust-badges');
      const docsContext = first.getByTestId('lnkup-app-docs-context');
      if (await trustBadges.count()) {
        await expect(trustBadges.locator('.lnkup-trust-badge').first()).toBeVisible();
      }
      if (await docsContext.count()) {
        await expect(docsContext).toContainText(/required documents|no required documents/i);
      }
    });
  });

  test.describe('marketplace opportunity cards', () => {
    test('public feed may show organizer trust badges when opportunities exist', async ({ page }) => {
      await page.goto('/opportunities/');
      const cards = page.locator('.lnkup-event-card--v2');
      const count = await cards.count();
      test.skip(count === 0, 'No opportunity cards in feed');
      const trust = cards.first().getByTestId('lnkup-opp-organizer-trust');
      if (await trust.count()) {
        await expect(trust.locator('.lnkup-trust-badge').first()).toBeVisible();
      }
    });
  });

  test.describe('authorization', () => {
    test('unauthenticated verification vendor route is forbidden', async ({ request }) => {
      const response = await request.get('/wp-json/lnkup/v1/verification/vendor', {
        headers: { Accept: 'application/json' },
      });
      expect([401, 403]).toContain(response.status());
    });
  });
});
