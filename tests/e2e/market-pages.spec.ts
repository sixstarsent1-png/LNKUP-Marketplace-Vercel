import { test, expect } from '@playwright/test';

test.describe('City market landing pages', () => {
  test('GET /markets/charlotte/ returns 200 with market hero', async ({ page }) => {
    const response = await page.goto('/markets/charlotte/');
    expect(response?.status()).toBe(200);
    await expect(page.getByTestId('lnkup-market-page')).toBeVisible();
    await expect(page.getByTestId('lnkup-market-hero')).toBeVisible();
  });

  test('hero contains city name Charlotte', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    await expect(page.getByTestId('lnkup-market-hero')).toContainText('Charlotte');
  });

  test('prelaunch shows waitlist copy not fully active marketplace', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    const prelaunch = page.getByTestId('lnkup-market-prelaunch-copy');
    const activeBadge = page.getByText('Active market', { exact: true });
    if (await prelaunch.count()) {
      await expect(prelaunch).toBeVisible();
      await expect(prelaunch).toContainText(/prelaunch|onboarding|launch/i);
    } else {
      await expect(activeBadge).toHaveCount(0);
    }
  });

  test('unknown market slug returns honest empty or 404', async ({ page }) => {
    const response = await page.goto('/markets/not-a-real-city-slug/');
    const status = response?.status() ?? 0;
    expect([404, 200]).toContain(status);
    if (status === 200) {
      await expect(page.getByTestId('lnkup-market-page')).toHaveCount(0);
    }
  });

  test('vendor CTA href includes market=charlotte', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    const vendorCta = page.getByTestId('lnkup-market-vendor-cta');
    await expect(vendorCta).toBeVisible();
    await expect(vendorCta).toHaveAttribute('href', /market=charlotte/);
  });

  test('organizer CTA href includes role and market when present', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    const organizerCta = page.getByTestId('lnkup-market-organizer-cta');
    if (await organizerCta.count()) {
      const href = await organizerCta.getAttribute('href');
      expect(href).toMatch(/market=charlotte/);
      if (href?.includes('sign-up')) {
        expect(href).toMatch(/role=organizer/);
      }
    }
  });

  test('title or meta description is city-specific', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    await expect(page).toHaveTitle(/Charlotte/i);
    const description = page.locator('meta[name="description"]');
    if (await description.count()) {
      await expect(description).toHaveAttribute('content', /Charlotte/i);
    }
  });

  test('mobile viewport has no horizontal overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/markets/charlotte/');
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
    expect(overflow).toBe(false);
  });

  test('homepage and opportunities still load', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('.lnkup-site')).toBeVisible();
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    const liveSearch = page.locator('#lnkup-search-input');
    await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
  });

  test('connector CTA links to interest form with Charlotte market', async ({ page }) => {
    await page.goto('/markets/charlotte/');
    const connectorCta = page.getByRole('link', { name: /Become a City Connector/i });
    await expect(connectorCta).toBeVisible();
    await expect(connectorCta).toHaveAttribute('href', /connector-interest/);
    await expect(connectorCta).toHaveAttribute('href', /market=charlotte/);
  });

  test('opportunities with Charlotte filters shows market page chip when registry matches', async ({ page }) => {
    await page.goto('/opportunities/?city=Charlotte&state=NC');
    const chip = page.getByTestId('lnkup-market-page-chip');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    const liveSearch = page.locator('#lnkup-search-input');
    await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
    if (await liveSearch.count()) {
      await expect(chip).toBeVisible();
      await expect(chip.locator('a')).toHaveAttribute('href', /\/markets\/charlotte\//);
    }
  });
});
