import { test, expect } from '@playwright/test';

test.describe('Role switch vendor → organizer', () => {
  test('does not white-screen when organizer API returns 403', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await page.route('**/api/organizer/home', async (route) => {
      await route.fulfill({
        status: 403,
        contentType: 'application/json',
        body: JSON.stringify({ code: 'forbidden', message: 'This section is for organizers.' }),
      });
    });

    await page.goto('/vendor');
    await page.getByRole('button', { name: 'Vendor' }).click();
    await page.getByRole('menuitem', { name: 'Organizer' }).click();
    await expect(page).toHaveURL(/\/organizer/);
    await expect(page.getByTestId('organizer-role-gate')).toBeVisible({ timeout: 10000 });
    expect(errors).toEqual([]);
  });

  test('live organizer home without mock does not throw client errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));

    await page.goto('/vendor');
    await page.getByRole('button', { name: 'Vendor' }).click();
    await page.getByRole('menuitem', { name: 'Organizer' }).click();
    await expect(page).toHaveURL(/\/organizer/);
    await expect(
      page.getByText(/Organizer Command Center|This section is for organizers|Sign in to continue|Welcome, organizer|Something went wrong/),
    ).toBeVisible({ timeout: 15000 });
    expect(errors).toEqual([]);
  });
});
