import { test, expect, type Page, type TestInfo } from '@playwright/test';
import path from 'node:path';
import { appLogin, expectNoHorizontalOverflow, organizerUser, restAs, trackPageErrors } from './helpers/app-session';

const shots = path.join(__dirname, '..', '..', 'scripts', 'e2e', 'reports', 'mobile-shots', 'after');

type MoneyCategory = { key: string; label: string; amount: number; hint?: string };

/**
 * Save a screenshot for the report (one device only).
 */
async function snap(page: Page, name: string, testInfo: TestInfo) {
  if (testInfo.project.name !== 'iphone-15') return;
  await page.screenshot({ path: path.join(shots, `organizer-${name}.png`), fullPage: true });
}

test.describe('Organizer vendors, money and more', () => {
  test.beforeEach(async ({ page }) => {
    await appLogin(page, organizerUser);
  });

  test('Money shows the server money categories with their hints and no payment-provider jargon', async ({ page }, testInfo) => {
    const errors = trackPageErrors(page);
    const failed: string[] = [];
    page.on('response', (r) => {
      if (r.status() >= 400 && r.url().includes('/wp-json/')) failed.push(`${r.status()} ${r.url().replace(/^.*\/wp-json/, '')}`);
    });
    const { body } = await restAs(page, organizerUser, 'GET', '/financial/dashboard?role=organizer');
    const categories = ((body as { money_categories?: MoneyCategory[] })?.money_categories ?? []) as MoneyCategory[];
    expect(categories.length, 'seeded dashboard returns money categories').toBeGreaterThan(0);

    await page.goto('/(organizer)/money');
    await expect(page.getByRole('heading', { name: 'Money' })).toBeVisible();
    const available = categories.find((c) => c.key === 'available');
    if (available) await expect(page.getByText(available.label, { exact: true }).first()).toBeVisible();
    const net = categories.find((c) => c.key === 'net');
    if (net) await expect(page.getByText(new RegExp(`^${net.label}: `))).toBeVisible();
    for (const category of categories.filter((c) => c.key !== 'available' && c.key !== 'net')) {
      await expect(page.getByText(category.label, { exact: true }).first()).toBeVisible();
      if (category.hint) await expect(page.getByText(category.hint).first()).toBeVisible();
    }
    await expect(page.getByText('Cash out history')).toBeVisible();
    await expect(page.getByText(/Stripe Connect|Settlement:|destination/i)).toHaveCount(0);
    await expect(page.getByText(/Send a reminder/i)).toHaveCount(0);
    await expect(page.getByText(/^E2E Draft/)).toHaveCount(0);
    await expectNoHorizontalOverflow(page, 'Money');
    await snap(page, 'money', testInfo);
    expect(failed, 'Money REST calls').toEqual([]);
    expect(errors.filter((e) => !/favicon|DevTools/.test(e))).toEqual([]);
  });

  test('cash out stays disabled with nothing available instead of pretending to succeed', async ({ page }) => {
    const { body } = await restAs(page, organizerUser, 'GET', '/financial/dashboard?role=organizer');
    const available = Number((body as { available_balance?: number })?.available_balance ?? 0);
    test.skip(available > 0, 'Seed has an available balance; covered by the payout test in staging');
    await page.goto('/(organizer)/money');
    await expect(page.getByText('Nothing to cash out yet.', { exact: false })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cash out', exact: true })).toBeDisabled();
  });

  test('a Money deep link opens Vendors on the Awaiting payment filter', async ({ page }) => {
    await page.goto('/(organizer)/vendors?tab=requests&filter=awaiting_payment');
    const chip = page.getByRole('button', { name: /^Awaiting payment/ });
    await expect(chip).toBeVisible();
    await expect(chip).toHaveAttribute('aria-selected', 'true');
  });

  test('Vendors has Requests, Discover and Invites, and the old approved queue is gone', async ({ page }, testInfo) => {
    await page.goto('/(organizer)/vendors');
    for (const name of ['Requests', 'Discover', 'Invites']) {
      await expect(page.getByRole('tab', { name: new RegExp(`^${name}`) })).toBeVisible();
    }
    for (const name of ['To review', 'Needs info', 'Waitlisted', 'Awaiting payment', 'Confirmed', 'Declined', 'All']) {
      await expect(page.getByRole('button', { name: new RegExp(`^${name}`) }).first()).toBeVisible();
    }
    await page.getByRole('tab', { name: /^Invites/ }).click();
    await expect(
      page.getByTestId('organizer-invites-list').or(page.getByText('No invites yet')),
    ).toBeVisible({ timeout: 45_000 });
    await snap(page, 'vendors-invites', testInfo);
  });

  test('More is grouped and sign out asks before ending the session', async ({ page }, testInfo) => {
    await page.goto('/(organizer)/more');
    for (const section of ['Organization', 'Event tools', 'Payments', 'Notifications', 'Help']) {
      await expect(page.getByText(section, { exact: true }).first()).toBeVisible();
    }
    await snap(page, 'more', testInfo);
    await page.getByTestId('organizer-more-sign-out').click();
    await expect(page.getByRole('heading', { name: 'Sign out?' })).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page).toHaveURL(/more/);
  });

  test('public profile edits use inline validation, not blocking alerts', async ({ page }) => {
    await page.goto('/(organizer)/profile');
    await page.getByRole('button', { name: 'Edit profile' }).click();
    const name = page.getByLabel('Organization name');
    const original = await name.inputValue();
    await name.fill('');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Add your organization name.')).toBeVisible();
    await name.fill(original);
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByRole('button', { name: 'Edit profile' })).toBeVisible();
  });
});
