# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: landing-launch.spec.ts >> Launch homepage >> homepage shows launch bar, countdown, and sign-up CTAs
- Location: tests\e2e\landing-launch.spec.ts:4:7

# Error details

```
Error: page.goto: net::ERR_CONNECTION_REFUSED at http://localhost:8888/
Call log:
  - navigating to "http://localhost:8888/", waiting until "load"

```

# Test source

```ts
  1  | import { test, expect } from '@playwright/test';
  2  | 
  3  | test.describe('Launch homepage', () => {
  4  |   test('homepage shows launch bar, countdown, and sign-up CTAs', async ({ page }) => {
> 5  |     await page.goto('/');
     |                ^ Error: page.goto: net::ERR_CONNECTION_REFUSED at http://localhost:8888/
  6  |     await expect(page.getByTestId('lnkup-launch-bar')).toBeVisible();
  7  |     await expect(page.getByTestId('lnkup-hero-launch-pill')).toBeVisible();
  8  |     await expect(page.getByTestId('lnkup-launch-countdown')).toBeVisible();
  9  |     await expect(page.getByTestId('lnkup-header-signup')).toBeVisible();
  10 |     await expect(page.getByTestId('lnkup-hero-cta-primary')).toBeVisible();
  11 |   });
  12 | 
  13 |   test('featured section shows locked preview cards before launch', async ({ page }) => {
  14 |     await page.goto('/');
  15 |     const card = page.getByTestId('lnkup-featured-card').first();
  16 |     if (await card.count()) {
  17 |       await expect(card).toHaveClass(/is-locked/);
  18 |       await expect(card.locator('.lnkup-event-card__media img')).toHaveCount(1);
  19 |     } else {
  20 |       await expect(page.getByTestId('lnkup-featured-signup')).toBeVisible();
  21 |       await expect(page.locator('.lnkup-event-card__media img').first()).toBeVisible();
  22 |     }
  23 |   });
  24 | 
  25 |   test('sign-up page renders role selector', async ({ page }) => {
  26 |     await page.goto('/sign-up/');
  27 |     await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
  28 |     await expect(page.getByTestId('lnkup-role-toggle')).toBeVisible();
  29 |     await expect(page.getByTestId('lnkup-signup-submit')).toBeVisible();
  30 |   });
  31 | 
  32 |   test('opportunities page shows prelaunch gate when marketplace closed', async ({ page }) => {
  33 |     await page.goto('/opportunities/');
  34 |     const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
  35 |     const liveSearch = page.locator('#lnkup-search-input');
  36 |     await expect(prelaunch.or(liveSearch)).toBeVisible({ timeout: 15000 });
  37 |   });
  38 | 
  39 |   test('trust section shows launch goals not fake achievement stats', async ({ page }) => {
  40 |     await page.goto('/');
  41 |     await expect(page.getByTestId('lnkup-trust-eyebrow')).toHaveText(/launch goals/i);
  42 |     await expect(page.getByTestId('lnkup-trust-disclaimer')).toBeVisible();
  43 |     await expect(page.getByText('4,000+')).toBeVisible();
  44 |     await expect(page.getByText('500+')).toBeVisible();
  45 |     await expect(page.getByText('98%')).toHaveCount(0);
  46 |     await expect(page.getByText('$2.4M')).toHaveCount(0);
  47 |   });
  48 | 
  49 |   test('hero shows launch goal card instead of fake vendor count', async ({ page }) => {
  50 |     await page.goto('/');
  51 |     await expect(page.getByTestId('lnkup-hero-visual')).toBeVisible();
  52 |     await expect(page.getByTestId('lnkup-hero-goal-card')).toBeVisible();
  53 |     await expect(page.getByText('4,800+')).toHaveCount(0);
  54 |     await expect(page.getByText('active vendors')).toHaveCount(0);
  55 |     await expect(page.getByText('LNKup Event')).toHaveCount(0);
  56 |   });
  57 | });
  58 | 
```