import { test, expect, type Page, type TestInfo } from '@playwright/test';
import path from 'node:path';
import { appLogin, appLogout, expectNoHorizontalOverflow, organizerUser, restAs, trackPageErrors } from './helpers/app-session';

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
    await expect(page.getByRole('button', { name: /^Payouts/ }).first()).toBeVisible();
    await expect(page.getByText(/Stripe Connect|Settlement:|destination|about 1\.5%/i)).toHaveCount(0);
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

  test('a test-mode cash out is requested, listed, then cancelled with the balance restored', async ({ page }) => {
    const orgB = process.env.PLAYWRIGHT_ORGANIZER_B_USER || 'e2e_org_b';
    const before = await restAs(page, orgB, 'GET', '/financial/dashboard?role=organizer');
    const available = Number((before.body as { available_balance?: number })?.available_balance ?? 0);
    test.skip(available < 10, 'Seed did not give organizer B a test-mode balance');

    await appLogout(page);
    await appLogin(page, orgB);
    await page.goto('/(organizer)/money');
    await page.getByTestId('money-cashout').click();
    await page.getByLabel('Amount').fill('10');
    await page.getByTestId('cashout-review').click();
    await expect(page.getByTestId('cashout-preview')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/LNKup (instant )?fee/).first()).toBeVisible();
    await page.getByTestId('cashout-confirm').click();
    await expect(page.getByText('Cash out requested')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/^Paid$/)).toHaveCount(0);
    await page.getByRole('button', { name: 'View this cash out' }).click();
    const cancel = page.getByRole('button', { name: /^Cancel cash out of \$10/ });
    await expect(cancel).toBeVisible({ timeout: 45_000 });

    await cancel.click();
    await page.getByRole('button', { name: 'Cancel cash out', exact: true }).click();
    await expect(page.getByText('Cash out cancelled. Your balance was restored.')).toBeVisible({ timeout: 45_000 });
    await expect
      .poll(async () => {
        const after = await restAs(page, orgB, 'GET', '/financial/dashboard?role=organizer');
        return Number((after.body as { available_balance?: number })?.available_balance ?? 0);
      }, { timeout: 45_000 })
      .toBe(available);
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
    for (const name of ['To review', 'Needs info', 'Awaiting payment', 'Confirmed']) {
      await expect(page.getByRole('button', { name: new RegExp(`^${name}`) }).first()).toBeVisible();
    }
    await page.getByTestId('requests-more-filters').click();
    for (const name of ['To review', 'Needs info', 'Waitlisted', 'Awaiting payment', 'Confirmed', 'Declined', 'All']) {
      await expect(page.getByRole('radio', { name: new RegExp(`^${name}`) })).toBeVisible();
    }
    await page.getByRole('radio', { name: /^Waitlisted/ }).click();
    await expect(page.getByRole('button', { name: /^Waitlisted/ }).first()).toHaveAttribute('aria-selected', 'true');
    await page.getByRole('tab', { name: /^Invites/ }).click();
    await expect(page.getByTestId('invites-panel')).toBeVisible({ timeout: 45_000 });
    await snap(page, 'vendors-invites', testInfo);
  });

  test('requests under review show the booth fee, and only approved ones say due', async ({ page }) => {
    await page.goto('/(organizer)/vendors?tab=requests&filter=review');
    await expect(page.getByText(/booth fee$/).first()).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(/ due$/)).toHaveCount(0);
    await page.getByRole('button', { name: /^Awaiting payment/ }).click();
    await expect(page.getByText(/ due$/).first()).toBeVisible({ timeout: 45_000 });
  });

  test('Discover filters show as removable chips and the list ends honestly', async ({ page }) => {
    await page.goto('/(organizer)/vendors?tab=discover');
    await expect(page.getByRole('button', { name: /^View profile for / }).first()).toBeVisible({ timeout: 45_000 });
    await page.getByRole('button', { name: /^Filters/ }).click();
    await page.getByRole('button', { name: 'Verified only' }).click();
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    const chip = page.getByRole('button', { name: 'Remove filter Verified only' });
    await expect(chip).toBeVisible();
    await expect(page.getByText(/That’s every vendor matching your search|No vendors found/)).toBeVisible({ timeout: 45_000 });
    await chip.click();
    await expect(chip).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^View profile for / }).first()).toBeVisible({ timeout: 45_000 });
  });

  test('More is grouped and sign out asks before ending the session', async ({ page }, testInfo) => {
    await page.goto('/(organizer)/more');
    for (const section of ['Organization', 'Business tools', 'Notifications', 'Account', 'Help']) {
      await expect(page.getByText(section, { exact: true }).first()).toBeVisible();
    }
    await expect(page.getByText(/@e2e_org_a|EAS project/)).toHaveCount(0);
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
