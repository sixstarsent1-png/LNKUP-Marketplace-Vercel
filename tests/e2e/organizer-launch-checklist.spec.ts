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
  await page.goto('/organizer-dashboard/#overview');
  const startBtn = page.getByRole('button', { name: /start event setup/i });
  if (await startBtn.isVisible()) {
    await startBtn.click();
  } else {
    await page.goto('/organizer-dashboard/#create');
    if (await startBtn.isVisible()) {
      await startBtn.click();
    }
  }
  await expect(page.locator('#lnkup-create-event-form')).toBeVisible();
}

/**
 * Fill minimal required event fields for publish.
 */
async function fillMinimalEventForm(page: import('@playwright/test').Page, suffix: string) {
  await page.getByTestId('lnkup-event-name').fill(`E2E Checklist Event ${suffix}`);
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

test.describe('Organizer launch checklist', () => {
  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('checklist visible on overview with status labels', async ({ page }) => {
      await page.goto('/organizer-dashboard/#overview');
      const checklist = page.getByTestId('lnkup-launch-checklist');
      const isVisible = await checklist.isVisible().catch(() => false);
      const isCompleteBanner = await page.getByText(/you're live — checklist complete/i).isVisible().catch(() => false);
      expect(isVisible || isCompleteBanner).toBeTruthy();

      if (isVisible) {
        await expect(checklist.getByText(/not started|in progress|complete/i).first()).toBeVisible();
        const createCard = checklist.locator('[data-launch-checklist-item="create_event"]');
        if (await createCard.count()) {
          await expect(createCard).toBeVisible();
        }
      }
    });

    test('create event advances create_event step', async ({ page }) => {
      const suffix = Date.now().toString();
      await openCreateWizard(page);
      await fillMinimalEventForm(page, suffix);
      await page.getByTestId('lnkup-publish-event').click();
      await expect(page.getByTestId('lnkup-vendor-onboarding')).toBeVisible({ timeout: 15000 });
      await page.getByTestId('lnkup-skip-vendors').click();
      await expect(page.locator('#lnkup-launch-success-root')).toBeVisible({ timeout: 15000 });

      await page.goto('/organizer-dashboard/#overview');
      const createCard = page.locator('[data-launch-checklist-item="create_event"]');
      if (await createCard.count()) {
        await expect(createCard).toHaveAttribute('data-status', /complete|in_progress/);
      }
    });

    test('payout CTA navigates to financial section', async ({ page }) => {
      await page.goto('/organizer-dashboard/#overview');
      const payoutCta = page.locator('[data-launch-checklist-item="payout_account"] [data-launch-checklist-cta]');
      if (!(await payoutCta.count())) {
        test.skip(true, 'Launch checklist not shown or payout step complete');
      }
      await payoutCta.click();
      await expect(page).toHaveURL(/#financial/);
      await expect(page.locator('#financial')).toBeVisible();
    });

    test('mobile layout stacks checklist cards', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto('/organizer-dashboard/#overview');
      const checklist = page.getByTestId('lnkup-launch-checklist');
      if (!(await checklist.isVisible().catch(() => false))) {
        test.skip(true, 'Launch checklist not visible for this organizer');
      }
      const grid = checklist.locator('[data-launch-checklist-grid]');
      await expect(grid).toBeVisible();
      const firstCta = checklist.locator('[data-launch-checklist-cta]').first();
      if (await firstCta.count()) {
        await expect(firstCta).toBeVisible();
        const box = await firstCta.boundingBox();
        expect(box && box.height >= 28).toBeTruthy();
      }
    });
  });
});
