import { test, expect, type Page } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;

/**
 * Log into WordPress with the given credentials.
 */
async function login(page: Page, user: string, pass: string) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill(pass);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

/**
 * Turn app suggestions off and back on, which also clears a "Not now" snooze.
 */
async function resetAppSuggestions(page: Page, settingsPath: string) {
  await page.goto(settingsPath);
  const toggle = page.getByLabel(/hide app suggestions/i);
  if (!(await toggle.count())) {
    return;
  }
  const status = page.getByTestId('lnkup-app-promo-pref').getByRole('status');
  if (!(await toggle.isChecked())) {
    await toggle.check();
    await expect(status).toHaveText(/hidden/i);
  }
  await toggle.uncheck();
  await expect(status).toHaveText(/turned back on/i);
}

/**
 * Close the dashboard app card and confirm it stays closed after reload.
 */
async function expectCardDismissPersists(page: Page, homePath: string) {
  await page.goto(homePath);
  const card = page.getByTestId('lnkup-app-promo-card');
  test.skip(!(await card.count()), 'App card not shown (app prompts off, app user, or opted out)');
  await expect(card).toBeVisible();
  await expect(card.getByRole('link', { name: /download on the app store/i })).toHaveAttribute('href', /apps\.apple\.com\/.*ct=lnkup-/);

  const saved = page.waitForResponse((response) => response.url().includes('admin-ajax.php') && response.request().method() === 'POST');
  await card.getByRole('button', { name: /not now/i }).click();
  await expect(card).toBeHidden();
  expect((await saved).ok()).toBeTruthy();

  await page.reload();
  await expect(page.getByTestId('lnkup-app-promo-card')).toHaveCount(0);
}

test.describe('Optional iPhone app prompts', () => {
  test('homepage offers the app without blocking the page', async ({ page }) => {
    await page.goto('/');
    const section = page.getByTestId('lnkup-get-the-app');
    test.skip(!(await section.count()), 'App prompts are off or the marketplace is not open');

    await expect(page.locator('meta[name="apple-itunes-app"]')).toHaveAttribute('content', /app-id=\d+/);
    await expect(section.getByTestId('lnkup-app-store-badge')).toHaveAttribute('href', /apps\.apple\.com/);
    await expect(page.getByTestId('lnkup-hero-cta-primary')).toBeVisible();
  });

  test('homepage app bar can be closed and stays closed', async ({ page }) => {
    await page.goto('/');
    const bar = page.getByTestId('lnkup-app-download-bar');
    test.skip(!(await bar.isVisible().catch(() => false)), 'App bar not shown');

    await bar.getByRole('button', { name: /dismiss/i }).click();
    await expect(bar).toBeHidden();
    await page.reload();
    await expect(page.getByTestId('lnkup-app-download-bar')).toBeHidden();
  });

  test.describe('organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await login(page, organizerUser!, organizerPass!);
      await resetAppSuggestions(page, '/organizer-dashboard/?view=help');
    });

    test.afterEach(async ({ page }) => {
      await resetAppSuggestions(page, '/organizer-dashboard/?view=help');
    });

    test('overview shows quick actions', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=home');
      await expect(page.getByTestId('lnkup-organizer-quick-actions')).toBeVisible();
      await expect(page.getByTestId('lnkup-organizer-quick-actions').getByRole('link', { name: /invite vendors/i })).toBeVisible();
    });

    test('app card can be closed and stays closed after reload', async ({ page }) => {
      await expectCardDismissPersists(page, '/organizer-dashboard/?view=home');
    });

    test('hiding app suggestions removes the card', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=help');
      const toggle = page.getByLabel(/hide app suggestions/i);
      test.skip(!(await toggle.count()), 'App prompts are off site-wide');
      await toggle.check();
      await expect(page.getByTestId('lnkup-app-promo-pref').getByRole('status')).toHaveText(/hidden/i);

      await page.goto('/organizer-dashboard/?view=home');
      await expect(page.getByTestId('lnkup-app-promo-card')).toHaveCount(0);
    });
  });

  test.describe('vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await login(page, vendorUser!, vendorPass!);
      await resetAppSuggestions(page, '/vendor-profile/');
    });

    test.afterEach(async ({ page }) => {
      await resetAppSuggestions(page, '/vendor-profile/');
    });

    test('app card can be closed and stays closed after reload', async ({ page }) => {
      await expectCardDismissPersists(page, '/vendor-dashboard/');
    });
  });
});
