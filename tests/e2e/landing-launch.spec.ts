import { test, expect, type Page } from '@playwright/test';

/**
 * Detect public marketplace state from the homepage launch chrome.
 */
async function marketplaceIsOpen(page: Page): Promise<boolean> {
  const launchBar = page.getByTestId('lnkup-launch-bar');
  return !(await launchBar.isVisible().catch(() => false));
}

test.describe('Launch homepage', () => {
  test('homepage launch chrome matches marketplace state', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('lnkup-header-signup')).toBeVisible();
    await expect(page.getByTestId('lnkup-hero-cta-primary')).toBeVisible();

    if (await marketplaceIsOpen(page)) {
      await expect(page.getByTestId('lnkup-launch-bar')).toHaveCount(0);
      await expect(page.getByTestId('lnkup-hero-launch-pill')).toHaveCount(0);
      await expect(page.getByTestId('lnkup-launch-countdown')).toHaveCount(0);
      await expect(page.getByText(/launching august 2, 2026/i)).toHaveCount(0);
      await expect(page.getByText(/join the prelaunch/i)).toHaveCount(0);
      return;
    }

    await expect(page.getByTestId('lnkup-launch-bar')).toBeVisible();
    await expect(page.getByTestId('lnkup-hero-launch-pill')).toBeVisible();
    await expect(page.getByTestId('lnkup-launch-countdown')).toBeVisible();
  });

  test('featured opportunities section is on the homepage', async ({ page }) => {
    await page.goto('/');
    const section = page.locator('#opportunities');
    await expect(section).toBeVisible();

    if (await marketplaceIsOpen(page)) {
      await expect(page.getByRole('link', { name: /view all events/i })).toBeVisible();
      const card = page.getByTestId('lnkup-featured-card').first();
      if (await card.count()) {
        await expect(card).not.toHaveClass(/is-locked/);
      }
      return;
    }

    const card = page.getByTestId('lnkup-featured-card').first();
    if (await card.count()) {
      await expect(card).toHaveClass(/is-locked/);
      await expect(card.locator('.lnkup-event-card__media img')).toHaveCount(1);
    } else {
      await expect(page.getByTestId('lnkup-featured-signup')).toBeVisible();
      await expect(page.locator('.lnkup-event-card__media img').first()).toBeVisible();
    }
  });

  test('opportunities nav targets the homepage section', async ({ page }) => {
    await page.goto('/');
    if (!(await marketplaceIsOpen(page))) {
      await expect(page.locator('a[href$="#launch-countdown"]').first()).toBeAttached();
      return;
    }

    await expect(page.locator('#opportunities')).toBeAttached();

    const viewport = page.viewportSize();
    const eventsLink = viewport && viewport.width < 768
      ? page.locator('#lnkup-mobile-nav').getByRole('link', { name: /^events$/i })
      : page.getByRole('navigation', { name: /primary/i }).getByRole('link', { name: /^events$/i });
    if (viewport && viewport.width < 768) {
      await page.getByRole('button', { name: /toggle menu/i }).click();
    }
    await expect(eventsLink).toBeVisible();
    await eventsLink.click();
    await expect(page).toHaveURL(/opportunities/);
  });

  test('sign-up page renders role selector and live copy when open', async ({ page }) => {
    await page.goto('/sign-up/');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByTestId('lnkup-role-toggle')).toBeVisible();
    await expect(page.getByTestId('lnkup-signup-submit')).toBeVisible();

    await page.goto('/');
    const isOpen = await marketplaceIsOpen(page);
    await page.goto('/sign-up/');
    if (isOpen) {
      await expect(page.getByText('Join LNKup')).toBeVisible();
      await expect(page.getByText(/launching august 2, 2026/i)).toHaveCount(0);
      await expect(page.getByText(/apply at launch/i)).toHaveCount(0);
    }
  });

  test('opportunities page shows prelaunch gate when marketplace closed', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    const liveSearch = page.locator('#lnkup-search-input');
    await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
  });

  test('trust section does not show fake achievement stats', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByText('98%')).toHaveCount(0);
    await expect(page.getByText('$2.4M')).toHaveCount(0);

    if (await marketplaceIsOpen(page)) {
      await expect(page.getByTestId('lnkup-trust-eyebrow')).toHaveText(/trusted by vendors/i);
      await expect(page.getByTestId('lnkup-trust-disclaimer')).toHaveCount(0);
      return;
    }

    await expect(page.getByTestId('lnkup-trust-eyebrow')).toHaveText(/launch goals/i);
    await expect(page.getByTestId('lnkup-trust-disclaimer')).toBeVisible();
    await expect(page.getByText('4,000+')).toBeVisible();
    await expect(page.getByText('500+')).toBeVisible();
  });

  test('hero avoids fake vendor counts', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('lnkup-hero-search')).toBeVisible();
    await expect(page.getByText('4,800+')).toHaveCount(0);
    await expect(page.getByText('LNKup Event', { exact: true })).toHaveCount(0);
    await expect(page.getByTestId('lnkup-hero-goal-card')).toHaveCount(0);
    await expect(page.getByTestId('lnkup-hero-stat-card')).toHaveCount(0);
  });

  test('login and lost-password pages are LNKup branded', async ({ page }) => {
    await page.goto('/wp-login.php');
    const logo = page.locator('#login h1 a');
    await expect(logo).toBeVisible();
    await expect(logo).toHaveAttribute('href', /https?:\/\//);
    await expect(page.locator('#lnkup-login, body.login')).toHaveCount(1);
    await expect(page.locator('link[href*="lnkup-theme/assets/css/login.css"]')).toHaveCount(1);

    await page.goto('/wp-login.php?action=lostpassword');
    await expect(page.getByText(/enter your account email/i)).toBeVisible();
    await expect(page.locator('#login h1 a')).toBeVisible();
  });
});
