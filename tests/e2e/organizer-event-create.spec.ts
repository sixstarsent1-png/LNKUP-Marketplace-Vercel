import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

/**
 * Log into WordPress with organizer credentials.
 */
async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

/**
 * Open create event wizard from organizer dashboard.
 */
async function openCreateWizard(page: import('@playwright/test').Page) {
  await page.goto('/organizer-dashboard/#create');
  const startBtn = page.getByRole('button', { name: /start event setup/i });
  if (await startBtn.isVisible()) {
    await startBtn.click();
  }
  await expect(page.locator('#lnkup-create-event-form')).toBeVisible();
}

/**
 * Fill minimal required event fields for publish.
 */
async function fillMinimalEventForm(page: import('@playwright/test').Page, suffix: string) {
  await page.getByTestId('lnkup-event-name').fill(`E2E Test Event ${suffix}`);
  const parentSelect = page.locator('#event_type_parent');
  await parentSelect.selectOption({ index: 1 });
  await page.waitForFunction(() => {
    const el = document.getElementById('event_type') as HTMLSelectElement | null;
    return el && !el.disabled && el.options.length > 1;
  });
  await page.locator('#event_type').selectOption({ index: 1 });
  await page.getByTestId('lnkup-event-date').fill('2026-12-15');
  await page.locator('#event_city').fill('Austin');
}

test.describe('Organizer event create flow', () => {
  test('create wizard requires login when unauthenticated', async ({ page }) => {
    await page.goto('/organizer-dashboard/#create');
    await expect(page).toHaveURL(/wp-login|login/);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('event only — skip vendor onboarding', async ({ page }) => {
      const suffix = Date.now().toString();
      await openCreateWizard(page);
      await fillMinimalEventForm(page, suffix);
      await page.getByTestId('lnkup-publish-event').click();
      await expect(page.getByTestId('lnkup-vendor-onboarding')).toBeVisible({ timeout: 15000 });
      await page.getByTestId('lnkup-skip-vendors').click();
      await expect(page.locator('#lnkup-launch-success-root')).toBeVisible();
      await expect(page.locator('#lnkup-launch-success-root')).toContainText(/event created/i);
    });

    test('event + opportunity — accept vendors and publish', async ({ page }) => {
      const suffix = Date.now().toString();
      await openCreateWizard(page);
      await fillMinimalEventForm(page, suffix);
      await page.getByTestId('lnkup-publish-event').click();
      await expect(page.getByTestId('lnkup-vendor-onboarding')).toBeVisible({ timeout: 15000 });
      await page.getByTestId('lnkup-accept-vendors').click();
      await expect(page.getByTestId('lnkup-first-opportunity-form')).toBeVisible();
      await page.getByTestId('lnkup-opp-fee').fill('300');
      await page.getByTestId('lnkup-opp-spots').fill('5');
      await page.getByTestId('lnkup-publish-opportunity').click();
      await expect(page.locator('#lnkup-launch-success-root')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('#lnkup-launch-success-root')).toContainText(/opportunity published/i);
    });

    test('onboarding UI stacks on mobile viewport', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      const suffix = Date.now().toString();
      await openCreateWizard(page);
      await fillMinimalEventForm(page, suffix);
      await page.getByTestId('lnkup-publish-event').click();
      await expect(page.getByTestId('lnkup-vendor-onboarding')).toBeVisible({ timeout: 15000 });
      const acceptBtn = page.getByTestId('lnkup-accept-vendors');
      const skipBtn = page.getByTestId('lnkup-skip-vendors');
      await expect(acceptBtn).toBeVisible();
      await expect(skipBtn).toBeVisible();
      const acceptBox = await acceptBtn.boundingBox();
      const skipBox = await skipBtn.boundingBox();
      expect(acceptBox).not.toBeNull();
      expect(skipBox).not.toBeNull();
      if (acceptBox && skipBox) {
        expect(skipBox.y).toBeGreaterThan(acceptBox.y);
      }
      await acceptBtn.click();
      await expect(page.getByTestId('lnkup-first-opportunity-form')).toBeVisible();
    });

    test('opportunity failure after event success shows partial error', async ({ page }) => {
      const suffix = Date.now().toString();
      await openCreateWizard(page);
      await fillMinimalEventForm(page, suffix);
      await page.getByTestId('lnkup-publish-event').click();
      await expect(page.getByTestId('lnkup-vendor-onboarding')).toBeVisible({ timeout: 15000 });
      await page.getByTestId('lnkup-accept-vendors').click();
      await page.evaluate(() => {
        const form = document.getElementById('lnkup-create-first-opportunity-form');
        if (form) {
          form.setAttribute('data-test-block-opp', '1');
        }
      });
      await page.getByTestId('lnkup-publish-opportunity').click();
      await expect(page.getByTestId('lnkup-onboarding-opp-error')).toBeVisible({ timeout: 15000 });
      await expect(page.getByTestId('lnkup-onboarding-opp-error')).toContainText(/could not be created/i);
    });
  });
});
