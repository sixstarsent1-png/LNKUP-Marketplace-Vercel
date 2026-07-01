import { test, expect } from '@playwright/test';

async function isRankMathActive(page: import('@playwright/test').Page) {
  const generator = page.locator('meta[name="generator"][content*="Rank Math"]');
  const description = page.locator('meta[name="description"]');
  const hasGenerator = (await generator.count()) > 0;
  const hasDescription = (await description.count()) > 0;
  return hasGenerator || hasDescription;
}

test.describe('Rank Math SEO', () => {
  test('homepage has description and is indexable when SEO plugin active', async ({ page }) => {
    await page.goto('/');
    if (!(await isRankMathActive(page))) {
      test.skip();
    }
    const description = page.locator('meta[name="description"]');
    await expect(description).toHaveAttribute('content', /.+/, { timeout: 10000 });
    const robots = page.locator('meta[name="robots"]');
    if (await robots.count()) {
      await expect(robots).not.toHaveAttribute('content', /noindex/i);
    }
  });

  test('homepage og:image uses block-party illustration', async ({ page }) => {
    await page.goto('/');
    if (!(await isRankMathActive(page))) {
      test.skip();
    }
    const ogImage = page.locator('meta[property="og:image"]');
    await expect(ogImage).toHaveAttribute('content', /block-party|events\//i);
  });

  test('opportunities page is noindex pre-launch', async ({ page }) => {
    await page.goto('/opportunities/');
    if (!(await isRankMathActive(page))) {
      test.skip();
    }
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    const liveSearch = page.locator('#lnkup-search-input');
    await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
    if (await prelaunch.isVisible()) {
      const robots = page.locator('meta[name="robots"]');
      await expect(robots).toHaveAttribute('content', /noindex/i);
    }
  });

  test('vendor dashboard is noindex', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    if (!(await isRankMathActive(page))) {
      test.skip();
    }
    const robots = page.locator('meta[name="robots"]');
    await expect(robots).toHaveAttribute('content', /noindex/i);
  });
});
