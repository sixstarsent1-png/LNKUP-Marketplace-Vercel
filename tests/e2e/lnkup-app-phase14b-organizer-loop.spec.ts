import { test, expect } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

test.describe('Phase 14B — Organizer loop', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto(`${appBase}/?role=organizer`);
  });

  test('organizer desktop nav shows Launch event', async ({ page }) => {
    await page.goto(`${appBase}/organizer`);
    await expect(page.getByRole('link', { name: 'Launch event' })).toBeVisible();
  });

  test('event workspace requests tab renders queue and add opportunity CTA', async ({ page }) => {
    await page.goto(`${appBase}/organizer/events/charlotte-night-market`);
    await page.getByRole('tab', { name: 'Vendor Requests' }).click();
    await expect(page.getByRole('button', { name: 'View full request queue' })).toBeVisible();
    await page.getByRole('tab', { name: 'Opportunities' }).click();
    await expect(page.getByRole('button', { name: 'Add opportunity' })).toBeVisible();
  });

  test('organizer can approve a pending vendor request in app', async ({ page }) => {
    await page.goto(`${appBase}/organizer/vendors`);
    const requestCard = page.getByTestId('organizer-vendor-request-card').first();
    await expect(requestCard).toBeVisible();
    await requestCard.getByTestId('organizer-approve').click();
    await expect(requestCard.getByText('Approved')).toBeVisible();
  });

  test('event workspace requests tab supports filter and sort controls', async ({ page }) => {
    await page.goto(`${appBase}/organizer/events/charlotte-night-market`);
    await page.getByRole('tab', { name: 'Vendor Requests' }).click();
    await expect(page.getByTestId('organizer-request-filters')).toBeVisible();
    await page.getByRole('button', { name: 'Paid' }).click();
    await page.getByTestId('organizer-request-sort').selectOption('oldest');
    await expect(page.getByTestId('organizer-request-sort')).toHaveValue('oldest');
  });
});
