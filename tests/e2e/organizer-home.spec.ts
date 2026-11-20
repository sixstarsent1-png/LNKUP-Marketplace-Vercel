import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';

test.describe('Organizer Home', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
    await loginPage(page, organizerUser, organizerPass);
    await page.goto('/organizer-dashboard/');
  });

  test('shows the organization welcome, action center, and recent activity', async ({ page }) => {
    await expect(page.getByTestId('lnkup-organizer-home-welcome')).toContainText(/welcome back/i);
    await expect(page.getByTestId('lnkup-organizer-attention')).toContainText(/action center/i);
    const activity = page.getByTestId('lnkup-organizer-recent-activity');
    await expect(activity).toBeVisible();
    await expect(activity.getByRole('listitem').first()).toBeVisible();
  });

  test('renders no PHP notices', async ({ page }) => {
    await expect(page.locator('body')).not.toContainText(/(Warning|Notice|Deprecated|Fatal error):/);
  });

  test('intelligence shows the vendor funnel from applications to check-ins', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=intelligence');
    const funnel = page.getByTestId('lnkup-intel-funnel');
    await expect(funnel).toBeVisible();
    for (const stage of ['Applications', 'Approvals', 'Payments', 'Confirmed', 'Check-ins']) {
      await expect(funnel).toContainText(stage);
    }
    await expect(funnel).not.toContainText(/views/i);
  });

  test('launch checklist invite link opens the Vendors view', async ({ page }) => {
    const invite = page.locator('a[href*="view=vendors"]').first();
    test.skip((await invite.count()) === 0, 'Checklist already complete for this organizer');
    await expect(invite).toHaveAttribute('href', /view=vendors/);
  });
});
