import { test, expect, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const onboardingUser = process.env.PLAYWRIGHT_ONBOARDING_ORGANIZER_USER ?? 'e2e_org_onb';
const START = '/organizer-dashboard/?view=start';

/**
 * Get started root for the signed-in organizer.
 */
function startView(page: Page) {
  return page.getByTestId('lnkup-organizer-start');
}

test.describe('Organizer guided setup (onboarding v2)', () => {
  test.skip(!password, 'Run scripts/e2e/seed.php and seed-onboarding.php first');
  test.describe.configure({ mode: 'serial' });

  test('organizer sign-up asks for organization basics and lands on Get started', async ({ page }) => {
    test.setTimeout(90_000);
    await page.goto('/sign-up/?role=organizer');
    const orgFields = page.getByTestId('lnkup-signup-org-fields');
    await expect(orgFields).toBeVisible();
    const roles = page.getByTestId('lnkup-role-toggle');
    await roles.getByText('Vendor', { exact: true }).click();
    await expect(orgFields).toBeHidden();
    await roles.getByText('Organizer', { exact: true }).click();
    await expect(orgFields).toBeVisible();

    const email = `onb_${Date.now()}@example.test`;
    await page.getByLabel('Organization or business name').fill('Playwright Productions');
    await page.getByLabel('Primary city').fill('Atlanta');
    await page.getByLabel('State', { exact: true }).fill('GA');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('Passw0rd!long');
    await page.getByLabel('Confirm password').fill('Passw0rd!long');
    await page.getByLabel(/I agree to the LNKup terms/).check();
    await page.getByTestId('lnkup-signup-submit').click();

    await expect(page).toHaveURL(/view=start/, { timeout: 30_000 });
    await expect(startView(page)).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('lnkup-start-stage-organization').getByText('Done')).toBeVisible();
    await expect(page.getByTestId('lnkup-start-stage-format')).toBeVisible();
  });

  test('choosing Multi-city tour routes the first event step to the tour wizard', async ({ page }) => {
    await loginPage(page, onboardingUser, password);
    await page.goto(START);
    const format = page.getByTestId('lnkup-start-stage-format');
    await format.getByTestId('lnkup-start-format-tour').check();
    await format.getByRole('button', { name: 'Save and continue' }).click();
    await expect(format.getByRole('status')).toContainText('Saved', { timeout: 20_000 });
    await expect(format.getByText('Done')).toBeVisible();

    const firstEvent = page.getByTestId('lnkup-start-stage-first_event');
    await expect(firstEvent.getByRole('heading', { name: 'Set up your tour' })).toBeVisible();
    await firstEvent.getByRole('link', { name: /Create event|Finish event setup/ }).click();
    await expect(page).toHaveURL(/view=tours.*new=tour/);
    await expect(page.getByTestId('lnkup-portfolio-create')).toBeVisible({ timeout: 20_000 });
  });

  test('setup choices resume in a different browser session', async ({ browser }) => {
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginPage(page, onboardingUser, password);
    await page.goto(START);
    await expect(page.getByTestId('lnkup-start-format-tour')).toBeChecked();
    await expect(page.getByTestId('lnkup-start-stage-first_event').getByRole('heading', { name: 'Set up your tour' })).toBeVisible();
    await context.close();
  });

  test('Overview shows the setup banner instead of the old launch checklist', async ({ page }) => {
    await loginPage(page, onboardingUser, password);
    await page.goto('/organizer-dashboard/?view=home');
    await expect(page.getByTestId('lnkup-start-banner')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('lnkup-launch-checklist')).toHaveCount(0);
  });
});
