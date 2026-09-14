import { test, expect } from '@playwright/test';

test.describe('City Connector program', () => {
  test('connector interest page renders form with market context', async ({ page }) => {
    const response = await page.goto('/connector-interest/?market=charlotte');
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('lnkup-connector-interest-form')).toBeVisible();
    await expect(page.getByLabel('Email')).toBeEditable();
    await expect(page.getByText(/Charlotte/i)).toBeVisible();
  });

  test('market page connector CTA links to interest form with market', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    const connectorCta = page.getByRole('link', { name: /Become a City Connector/i });
    await expect(connectorCta).toBeVisible();
    await expect(connectorCta).toHaveAttribute('href', /connector-interest/);
    await expect(connectorCta).toHaveAttribute('href', /market=charlotte/);
  });

  test('sign-up with ref query includes hidden ref field', async ({ page }) => {
    await page.goto('/sign-up/?ref=TEST-CODE&role=vendor&market=charlotte');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByTestId('lnkup-signup-ref')).toHaveValue('TEST-CODE');
    await expect(page.getByTestId('lnkup-signup-market')).toHaveValue('charlotte');
  });

  test('sign-up with outreach and ref both render without blocking', async ({ page }) => {
    await page.goto('/sign-up/?outreach=invalid-token&ref=TEST-CODE&role=vendor');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByTestId('lnkup-signup-ref')).toHaveValue('TEST-CODE');
    await expect(page.getByLabel('Email')).toBeEditable();
  });

  test('public referral resolve endpoint returns JSON', async ({ request }) => {
    const response = await request.get('/wp-json/lnkup/v1/referrals/resolve?code=INVALID');
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body).toHaveProperty('valid');
  });

  test('connector dashboard requires login', async ({ page }) => {
    await page.goto('/connector-dashboard/');
    await expect(page).toHaveURL(/wp-login|login/);
  });

  test('connector dashboard mobile viewport has no horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/connector-dashboard/');
    if (!page.url().includes('login')) {
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      expect(overflow).toBe(false);
    }
  });

  test('unauthorized users cannot read connector earnings or calculator', async ({ request }) => {
    const earnings = await request.get('/wp-json/lnkup/v1/connector/earnings');
    expect(earnings.status()).toBeGreaterThanOrEqual(401);
    const calc = await request.post('/wp-json/lnkup/v1/connector/calculator', {
      data: { booth_price: 250, paid_bookings: 20, organizers: 1 },
    });
    expect(calc.status()).toBeGreaterThanOrEqual(401);
  });

  test('unauthorized users cannot read connector rank or leaderboard', async ({ request }) => {
    const rank = await request.get('/wp-json/lnkup/v1/connector/rank');
    expect(rank.status()).toBeGreaterThanOrEqual(401);
    const board = await request.get('/wp-json/lnkup/v1/connector/leaderboard');
    expect(board.status()).toBeGreaterThanOrEqual(401);
  });

  test('onboarding and terms endpoints reject anonymous JSON clients', async ({ request }) => {
    for (const path of [
      '/wp-json/lnkup/v1/connector/onboarding',
      '/wp-json/lnkup/v1/connector/funnel',
      '/wp-json/lnkup/v1/connector/terms',
      '/wp-json/lnkup/v1/connector/resources',
      '/wp-json/lnkup/v1/connector/studio',
      '/wp-json/lnkup/v1/connector/studio/analytics',
    ]) {
      const response = await request.get(path);
      expect(response.status(), path).toBeGreaterThanOrEqual(401);
    }
  });

  test('unauthorized users cannot read connector studio', async ({ request }) => {
    const studio = await request.get('/wp-json/lnkup/v1/connector/studio');
    expect(studio.status()).toBeGreaterThanOrEqual(401);
    const publish = await request.post('/wp-json/lnkup/v1/connector/studio/posts', {
      data: { url: 'https://www.instagram.com/reel/test', status: 'published' },
    });
    expect(publish.status()).toBeGreaterThanOrEqual(401);
  });

  for (const width of [320, 375, 768, 1024, 1440]) {
    test(`connector dashboard does not overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/connector-dashboard/?view=start');
      if (page.url().includes('login')) {
        return;
      }
      await expect(page.getByTestId('lnkup-connector-dashboard')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      expect(overflow).toBe(false);
    });

    test(`connector studio view does not overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/connector-dashboard/?view=studio');
      if (page.url().includes('login')) {
        return;
      }
      await expect(page.getByTestId('lnkup-connector-studio')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      expect(overflow).toBe(false);
    });

    test(`connector ranks view does not overflow at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 800 });
      await page.goto('/connector-dashboard/?view=ranks');
      if (page.url().includes('login')) {
        return;
      }
      await expect(page.getByTestId('lnkup-connector-roadmap')).toBeVisible();
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      expect(overflow).toBe(false);
    });
  }

  test('logged-in connector Get Started shows a launch plan without generic Continue', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=start');
    if (page.url().includes('login')) {
      test.skip(true, 'Requires a logged-in connector session');
      return;
    }
    await expect(page.getByTestId('lnkup-connector-launch-plan')).toBeVisible();
    await expect(page.getByTestId('lnkup-connector-current-action')).toBeVisible();
    await expect(page.getByTestId('lnkup-connector-growth-styles')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Continue', exact: true })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Continue', exact: true })).toHaveCount(0);
    await expect(page.getByText(/performance-based|not guaranteed|no base payment/i).first()).toBeVisible();
  });

  test('logged-in connector can open a studio template and submit a URL', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=studio');
    if (page.url().includes('login')) {
      test.skip(true, 'Requires a logged-in connector session');
      return;
    }
    await expect(page.getByTestId('lnkup-connector-studio')).toBeVisible();
    const open = page.getByRole('button', { name: /Open template|Build This Video/i }).first();
    await expect(open).toBeVisible();
    await open.click();
    await expect(page.getByTestId('lnkup-connector-builder')).toBeVisible();
    await page.getByRole('button', { name: 'Copy Caption' }).click();
    await expect(page.getByRole('button', { name: 'Close' })).toBeVisible();
    await page.getByLabel('Link').fill('https://example.com/not-allowed');
    await page.getByRole('button', { name: 'Submit published post' }).click();
    await expect(page.getByText(/Instagram, TikTok, LinkedIn, Facebook, or YouTube/i)).toBeVisible();
  });

  test('logged-in connector rank card and roadmap stay on-page', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=home');
    if (page.url().includes('login')) {
      test.skip(true, 'Requires a logged-in connector session');
      return;
    }
    await expect(page.getByTestId('lnkup-connector-rank-card')).toBeVisible();
    await page.getByRole('link', { name: 'View All Ranks' }).click();
    await expect(page).toHaveURL(/view=ranks/);
    await expect(page.getByTestId('lnkup-connector-roadmap')).toBeVisible();
    await expect(page.getByTestId('lnkup-connector-leaderboard')).toBeVisible();
  });

  test('logged-in connector Share sheet opens without reload', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=home');
    if (page.url().includes('login')) {
      test.skip(true, 'Requires a logged-in connector session');
      return;
    }
    const before = page.url();
    await page.getByTestId('lnkup-connector-copy-organizer').click();
    await expect(page.getByTestId('lnkup-connector-share-sheet')).toBeVisible();
    expect(page.url()).toBe(before);
    await expect(page.getByRole('heading', { name: /Share Your Link/i })).toBeVisible();
  });

  test('logged-in connector referrals filters and earnings calculator stay on-page', async ({ page }) => {
    await page.goto('/connector-dashboard/?view=referrals');
    if (page.url().includes('login')) {
      test.skip(true, 'Requires a logged-in connector session');
      return;
    }
    await expect(page.getByTestId('lnkup-connector-filters')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Organizers' })).toBeVisible();
    await page.getByRole('link', { name: 'Earnings' }).first().click();
    await expect(page).toHaveURL(/view=earnings/);
    await expect(page.getByTestId('lnkup-connector-calculator')).toBeVisible();
    const calc = page.waitForResponse((res) => res.url().includes('/connector/calculator') && res.request().method() === 'POST');
    await page.getByRole('button', { name: 'Estimate' }).click();
    const response = await calc;
    expect(response.ok()).toBeTruthy();
  });
});
