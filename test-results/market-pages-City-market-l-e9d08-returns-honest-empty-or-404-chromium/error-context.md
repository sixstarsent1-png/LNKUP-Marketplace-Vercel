# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: market-pages.spec.ts >> City market landing pages >> unknown market slug returns honest empty or 404
- Location: tests\e2e\market-pages.spec.ts:28:7

# Error details

```
Error: page.goto: net::ERR_CONNECTION_REFUSED at http://localhost:8888/markets/not-a-real-city-slug/
Call log:
  - navigating to "http://localhost:8888/markets/not-a-real-city-slug/", waiting until "load"

```

# Test source

```ts
  1  | import { test, expect } from '@playwright/test';
  2  | 
  3  | test.describe('City market landing pages', () => {
  4  |   test('GET /markets/charlotte/ returns 200 with market hero', async ({ page }) => {
  5  |     const response = await page.goto('/markets/charlotte/');
  6  |     expect(response?.status()).toBe(200);
  7  |     await expect(page.getByTestId('lnkup-market-page')).toBeVisible();
  8  |     await expect(page.getByTestId('lnkup-market-hero')).toBeVisible();
  9  |   });
  10 | 
  11 |   test('hero contains city name Charlotte', async ({ page }) => {
  12 |     await page.goto('/markets/charlotte/');
  13 |     await expect(page.getByTestId('lnkup-market-hero')).toContainText('Charlotte');
  14 |   });
  15 | 
  16 |   test('prelaunch shows waitlist copy not fully active marketplace', async ({ page }) => {
  17 |     await page.goto('/markets/charlotte/');
  18 |     const prelaunch = page.getByTestId('lnkup-market-prelaunch-copy');
  19 |     const activeBadge = page.getByText('Active market', { exact: true });
  20 |     if (await prelaunch.count()) {
  21 |       await expect(prelaunch).toBeVisible();
  22 |       await expect(prelaunch).toContainText(/prelaunch|onboarding|launch/i);
  23 |     } else {
  24 |       await expect(activeBadge).toHaveCount(0);
  25 |     }
  26 |   });
  27 | 
  28 |   test('unknown market slug returns honest empty or 404', async ({ page }) => {
> 29 |     const response = await page.goto('/markets/not-a-real-city-slug/');
     |                                 ^ Error: page.goto: net::ERR_CONNECTION_REFUSED at http://localhost:8888/markets/not-a-real-city-slug/
  30 |     const status = response?.status() ?? 0;
  31 |     expect([404, 200]).toContain(status);
  32 |     if (status === 200) {
  33 |       await expect(page.getByTestId('lnkup-market-page')).toHaveCount(0);
  34 |     }
  35 |   });
  36 | 
  37 |   test('vendor CTA href includes market=charlotte', async ({ page }) => {
  38 |     await page.goto('/markets/charlotte/');
  39 |     const vendorCta = page.getByTestId('lnkup-market-vendor-cta');
  40 |     await expect(vendorCta).toBeVisible();
  41 |     await expect(vendorCta).toHaveAttribute('href', /market=charlotte/);
  42 |   });
  43 | 
  44 |   test('organizer CTA href includes role and market when present', async ({ page }) => {
  45 |     await page.goto('/markets/charlotte/');
  46 |     const organizerCta = page.getByTestId('lnkup-market-organizer-cta');
  47 |     if (await organizerCta.count()) {
  48 |       const href = await organizerCta.getAttribute('href');
  49 |       expect(href).toMatch(/market=charlotte/);
  50 |       if (href?.includes('sign-up')) {
  51 |         expect(href).toMatch(/role=organizer/);
  52 |       }
  53 |     }
  54 |   });
  55 | 
  56 |   test('title or meta description is city-specific', async ({ page }) => {
  57 |     await page.goto('/markets/charlotte/');
  58 |     await expect(page).toHaveTitle(/Charlotte/i);
  59 |     const description = page.locator('meta[name="description"]');
  60 |     if (await description.count()) {
  61 |       await expect(description).toHaveAttribute('content', /Charlotte/i);
  62 |     }
  63 |   });
  64 | 
  65 |   test('mobile viewport has no horizontal overflow', async ({ page }) => {
  66 |     await page.setViewportSize({ width: 390, height: 844 });
  67 |     await page.goto('/markets/charlotte/');
  68 |     const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  69 |     expect(overflow).toBe(false);
  70 |   });
  71 | 
  72 |   test('homepage and opportunities still load', async ({ page }) => {
  73 |     await page.goto('/');
  74 |     await expect(page.locator('.lnkup-site')).toBeVisible();
  75 |     await page.goto('/opportunities/');
  76 |     const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
  77 |     const liveSearch = page.locator('#lnkup-search-input');
  78 |     await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
  79 |   });
  80 | 
  81 |   test('opportunities with Charlotte filters shows market page chip when registry matches', async ({ page }) => {
  82 |     await page.goto('/opportunities/?city=Charlotte&state=NC');
  83 |     const chip = page.getByTestId('lnkup-market-page-chip');
  84 |     const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
  85 |     const liveSearch = page.locator('#lnkup-search-input');
  86 |     await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
  87 |     if (await liveSearch.count()) {
  88 |       await expect(chip).toBeVisible();
  89 |       await expect(chip.locator('a')).toHaveAttribute('href', /\/markets\/charlotte\//);
  90 |     }
  91 |   });
  92 | });
  93 | 
```