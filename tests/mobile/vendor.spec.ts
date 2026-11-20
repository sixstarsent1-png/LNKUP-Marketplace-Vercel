import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';
import { appLogin, restAs, vendorUser } from './helpers/app-session';

const shots = path.join(__dirname, '..', '..', 'scripts', 'e2e', 'reports', 'mobile-shots', 'after');
const capacityOpportunityId = process.env.PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID || '57';

/**
 * Save a full-page screenshot for the report.
 */
async function snap(page: Page, name: string, testInfo: import('@playwright/test').TestInfo) {
  if (testInfo.project.name !== 'iphone-15') return;
  await page.screenshot({ path: path.join(shots, `vendor-${name}.png`) });
}

test.describe('Vendor experience', () => {
  test.beforeEach(async ({ page }) => {
    await appLogin(page, vendorUser);
  });

  test('tabs use the new names and Home leads with a next step', async ({ page }, testInfo) => {
    for (const name of ['Home', 'Explore', 'Bookings', 'Wallet', 'Profile']) {
      await expect(page.getByRole('tab', { name: new RegExp(name) })).toBeVisible();
    }
    await expect(page.getByTestId('vendor-next-step')).toBeVisible();
    await expect(page.getByText('Needs action')).toBeVisible();
    await snap(page, 'home', testInfo);
  });

  test('Home loads bookings once, sharing the tab badge request', async ({ page }) => {
    const calls: string[] = [];
    page.on('request', (r) => {
      if (/\/applications\/vendor(\?|$)/.test(r.url())) calls.push(r.url());
    });
    await page.goto('/(vendor)');
    await expect(page.getByTestId('vendor-next-step')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByRole('heading', { name: 'Recommended for you' })).toBeVisible({ timeout: 45_000 });
    expect(calls, 'one bookings request on a cold Home load').toHaveLength(1);
  });

  test('Explore cards show honest apply states', async ({ page }, testInfo) => {
    await page.goto('/(vendor)/find');
    await expect(page.getByRole('heading', { name: 'Explore' })).toBeVisible();
    const firstCard = page.getByRole('button', { name: /^Details for / }).first();
    await expect(firstCard).toBeVisible();
    await expect(page.getByRole('button', { name: /^(Apply to |Applied: |Full: |Applications closed: |Event ended: )/ }).first()).toBeVisible();
    await snap(page, 'explore', testInfo);
  });

  test('a booking opens with its progress timeline', async ({ page }, testInfo) => {
    await page.goto('/(vendor)/requests');
    await expect(page.getByRole('heading', { name: 'Bookings' })).toBeVisible();
    await page.getByRole('button', { name: /^Needs action/ }).click();
    const card = page.getByRole('button', { name: /^E2E Summer Market, / }).first();
    await expect(card).toBeVisible();
    await card.click();
    await expect(page).toHaveURL(/\/booking\/\d+/);
    const timeline = page.getByTestId('booking-timeline');
    await expect(timeline).toBeVisible();
    await expect(timeline.getByText('Applied', { exact: true })).toBeVisible();
    await expect(timeline.getByText(/Pay booth fee to confirm|Paid · booking confirmed/)).toBeVisible();
    await snap(page, 'booking', testInfo);
    await page.getByRole('button', { name: 'Back to Bookings' }).click();
    await expect(page).toHaveURL(/\/requests/);
  });

  test('an opportunity you applied to links to your booking instead of Apply', async ({ page }, testInfo) => {
    await page.goto(`/opportunity/${capacityOpportunityId}`);
    await expect(page.getByTestId('opportunity-already-applied')).toBeVisible();
    await page.getByRole('button', { name: 'View booking' }).click();
    await expect(page).toHaveURL(/\/booking\/\d+/);
    await snap(page, 'opportunity-applied', testInfo);
  });

  test('wallet groups due, history and receipts', async ({ page }, testInfo) => {
    await page.goto('/(vendor)/wallet');
    for (const tab of ['Due', 'History', 'Receipts']) {
      await expect(page.getByRole('tab', { name: new RegExp(`^${tab}`) })).toBeVisible();
    }
    await page.getByRole('tab', { name: /^History/ }).click();
    await expect(page.getByRole('heading', { name: 'Payments' })).toBeVisible();
    await snap(page, 'wallet', testInfo);
  });

  test('profile edits validate, persist across reload, and can be reverted', async ({ page }, testInfo) => {
    const before = await restAs(page, vendorUser, 'GET', '/vendor/profile');
    const original = (before.body as { city?: string }).city ?? '';
    await page.goto('/(vendor)/profile');
    const city = page.getByLabel('City');
    await expect(city).toBeVisible();
    const next = original === 'Raleigh' ? 'Durham' : 'Raleigh';
    await city.fill(next);
    await expect(page.getByText('You have unsaved changes')).toBeVisible();
    await page.getByLabel('Email').fill('not-an-email');
    await page.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByText('Enter a valid email.')).toBeVisible();
    await page.getByLabel('Email').fill('vendor-a@example.test');
    await page.getByRole('button', { name: 'Save profile' }).click();
    await expect(page.getByText('You have unsaved changes')).toHaveCount(0, { timeout: 45_000 });
    await snap(page, 'profile', testInfo);
    await page.reload();
    await page.goto('/(vendor)/profile');
    await expect(page.getByLabel('City')).toHaveValue(next);
    const restored = await restAs(page, vendorUser, 'POST', '/vendor/profile', { city: original });
    expect(restored.status).toBeLessThan(300);
  });
});
