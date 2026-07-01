import { test, expect } from '@playwright/test';

test.describe('Launch homepage', () => {
  test('homepage shows launch bar, countdown, and sign-up CTAs', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('lnkup-launch-bar')).toBeVisible();
    await expect(page.getByTestId('lnkup-hero-launch-pill')).toBeVisible();
    await expect(page.getByTestId('lnkup-launch-countdown')).toBeVisible();
    await expect(page.getByTestId('lnkup-header-signup')).toBeVisible();
    await expect(page.getByTestId('lnkup-hero-cta-primary')).toBeVisible();
  });

  test('featured section shows locked preview cards before launch', async ({ page }) => {
    await page.goto('/');
    const card = page.getByTestId('lnkup-featured-card').first();
    if (await card.count()) {
      await expect(card).toHaveClass(/is-locked/);
      await expect(card.locator('.lnkup-event-card__media img')).toHaveCount(1);
    } else {
      await expect(page.getByTestId('lnkup-featured-signup')).toBeVisible();
      await expect(page.locator('.lnkup-event-card__media img').first()).toBeVisible();
    }
  });

  test('sign-up page renders role selector', async ({ page }) => {
    await page.goto('/sign-up/');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByTestId('lnkup-role-toggle')).toBeVisible();
    await expect(page.getByTestId('lnkup-signup-submit')).toBeVisible();
  });

  test('opportunities page shows prelaunch gate when marketplace closed', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    const liveSearch = page.locator('#lnkup-search-input');
    await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
  });

  test('trust section shows launch goals not fake achievement stats', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('lnkup-trust-eyebrow')).toHaveText(/launch goals/i);
    await expect(page.getByTestId('lnkup-trust-disclaimer')).toBeVisible();
    await expect(page.getByText('4,000+')).toBeVisible();
    await expect(page.getByText('500+')).toBeVisible();
    await expect(page.getByText('98%')).toHaveCount(0);
    await expect(page.getByText('$2.4M')).toHaveCount(0);
  });

  test('hero shows launch goal card instead of fake vendor count', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('lnkup-hero-visual')).toBeVisible();
    await expect(page.getByTestId('lnkup-hero-goal-card')).toBeVisible();
    await expect(page.getByText('4,800+')).toHaveCount(0);
    await expect(page.getByText('active vendors')).toHaveCount(0);
    await expect(page.getByText('LNKup Event')).toHaveCount(0);
  });
});
