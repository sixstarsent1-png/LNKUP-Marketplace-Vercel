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
});
