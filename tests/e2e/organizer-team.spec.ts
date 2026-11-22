import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const reviewer = process.env.PLAYWRIGHT_REVIEWER_USER ?? '';
const TEAM = '/organizer-dashboard/?view=team';
const UI_WAIT = 60_000;

test.describe('Organization team access', () => {
  test.describe.configure({ timeout: 120_000 });
  test.skip(!organizer || !reviewer, 'Run scripts/e2e/seed-organizer-os.php on after seed.php');

  test('owner manages the team with review roles and sees the activity log', async ({ page }) => {
    await loginPage(page, organizer, password);
    await page.goto(TEAM);
    const team = page.getByRole('region', { name: 'Team access' });
    await expect(team.getByText('E2E Reviewer')).toBeVisible({ timeout: UI_WAIT });
    const role = team.getByRole('combobox', { name: 'Role' });
    await expect(role.getByRole('option', { name: 'Application Reviewer' })).toHaveCount(1);
    await expect(role.getByRole('option', { name: 'Vendor Manager' })).toHaveCount(1);
    await expect(page.getByRole('region', { name: 'Team activity' })).toBeVisible({ timeout: UI_WAIT });
  });

  test('a team member sees where they have access instead of an error', async ({ page }) => {
    await loginPage(page, reviewer, password);
    await page.goto(TEAM);
    const team = page.getByRole('region', { name: 'Team access' });
    await expect(team.getByText('Teams you belong to')).toBeVisible({ timeout: UI_WAIT });
    await expect(team.getByText('Application Reviewer')).toBeVisible();
    await expect(page.getByRole('region', { name: 'Organization verification' })).toBeHidden();
  });

  test('a team member sees only the staff navigation', async ({ page }) => {
    await loginPage(page, reviewer, password);
    await page.goto('/organizer-dashboard/');
    const nav = page.getByRole('navigation').filter({ has: page.getByRole('link', { name: 'My work' }) }).first();
    await expect(nav.getByRole('link', { name: 'My work' })).toBeVisible();
    await expect(nav.getByRole('link', { name: 'Review' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Money', exact: true })).toHaveCount(0);
  });
});
