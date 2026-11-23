import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';

test.describe('Organizer launch checklist', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
    await loginPage(page, organizerUser, organizerPass);
    await page.goto('/organizer-dashboard/');
  });

  test('checklist shows explainable steps or the complete banner', async ({ page }) => {
    const checklist = page.getByTestId('lnkup-launch-checklist');
    const complete = page.getByText(/checklist complete/i);
    // With guided setup (onboarding v2) on, the Get started banner replaces this checklist.
    const guidedBanner = page.getByTestId('lnkup-start-banner');
    await expect(checklist.or(complete).or(guidedBanner).first()).toBeVisible();
    if (await checklist.isVisible()) {
      await expect(checklist.getByText(/not started|in progress|complete/i).first()).toBeVisible();
      await expect(checklist.locator('[data-launch-checklist-item="create_event"]')).toHaveAttribute('data-status', /complete|in_progress/);
    }
  });

  test('payout step links to the Financial view', async ({ page }) => {
    const payoutCta = page.locator('[data-launch-checklist-item="payout_account"] [data-launch-checklist-cta]');
    test.skip((await payoutCta.count()) === 0, 'Payout step already complete for this organizer');
    await expect(payoutCta).toHaveAttribute('href', /view=financial/);
    await payoutCta.click();
    await expect(page).toHaveURL(/view=financial/);
    await expect(page.locator('#lnkup-organizer-financial')).toBeVisible();
  });

  test('invite step links to the Vendors view', async ({ page }) => {
    const inviteCta = page.locator('[data-launch-checklist-item="invite_vendors"] [data-launch-checklist-cta]');
    test.skip((await inviteCta.count()) === 0, 'Invite step not shown');
    await expect(inviteCta).toHaveAttribute('href', /view=vendors/);
  });

  test('mobile layout keeps checklist CTAs tappable', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.reload();
    const checklist = page.getByTestId('lnkup-launch-checklist');
    test.skip(!(await checklist.isVisible()), 'Checklist complete for this organizer');
    const firstCta = checklist.locator('[data-launch-checklist-cta]').first();
    await expect(firstCta).toBeVisible();
    const box = await firstCta.boundingBox();
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(28);
    const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflows).toBe(false);
  });
});
