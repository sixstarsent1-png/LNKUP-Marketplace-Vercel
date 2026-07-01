import { test, expect } from '@playwright/test';

test.describe('Outreach invite sign-up', () => {
  test('sign-up page renders normally without outreach token', async ({ page }) => {
    await page.goto('/sign-up/');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByLabel('Email')).toBeEditable();
    await expect(page.getByTestId('lnkup-outreach-token')).toHaveCount(0);
    await expect(page.getByTestId('lnkup-outreach-email')).toHaveCount(0);
  });

  test('invalid outreach token shows normal editable sign-up form', async ({ page }) => {
    await page.goto('/sign-up/?outreach=invalid-token-12345&role=vendor');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByLabel('Email')).toBeEditable();
    await expect(page.getByTestId('lnkup-outreach-token')).toHaveCount(0);
  });

  test('role query param pre-selects vendor on sign-up', async ({ page }) => {
    await page.goto('/sign-up/?role=vendor');
    await expect(page.getByTestId('lnkup-role-toggle')).toBeVisible();
    await expect(page.locator('input[name="lnkup_role"][value="lnkup_vendor"]')).toBeChecked();
  });

  test('role query param pre-selects organizer on sign-up', async ({ page }) => {
    await page.goto('/sign-up/?role=organizer');
    await expect(page.locator('input[name="lnkup_role"][value="lnkup_organizer"]')).toBeChecked();
  });

  test('ref query param is preserved alongside outreach token on sign-up', async ({ page }) => {
    await page.goto('/sign-up/?outreach=invalid-token-12345&ref=CONNECTOR-CODE&role=vendor');
    await expect(page.getByTestId('lnkup-signup-form')).toBeVisible();
    await expect(page.getByTestId('lnkup-signup-ref')).toHaveValue('CONNECTOR-CODE');
    await expect(page.getByLabel('Email')).toBeEditable();
  });
});
