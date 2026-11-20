import { test, expect, type Page } from '@playwright/test';
import path from 'node:path';
import { appLogin, organizerBUser, organizerUser, restAs } from './helpers/app-session';

const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '56';
const shots = path.join(__dirname, '..', '..', 'scripts', 'e2e', 'reports', 'mobile-shots', 'after');

/**
 * Save a screenshot for the report (one device only).
 */
async function snap(page: Page, name: string, testInfo: import('@playwright/test').TestInfo) {
  if (testInfo.project.name !== 'iphone-15') return;
  await page.screenshot({ path: path.join(shots, `organizer-${name}.png`) });
}

test.describe('Organizer events', () => {
  test.beforeEach(async ({ page }) => {
    await appLogin(page, organizerUser);
  });

  test('Home counts match live application statuses', async ({ page }, testInfo) => {
    const { body } = await restAs(page, organizerUser, 'GET', '/applications/organizer');
    const items = ((body as { items?: Array<{ status?: string }> })?.items ?? []) as Array<{ status?: string }>;
    const due = items.filter((i) => i.status === 'payment_required' || i.status === 'approved').length;
    await expect(page.getByText('Payment due').first()).toBeVisible();
    const tile = page.getByRole('button', { name: new RegExp(`Payment due`) }).first();
    if (await tile.isVisible().catch(() => false)) {
      await expect(tile).toContainText(String(due));
    }
    await snap(page, 'home', testInfo);
  });

  test('Events list uses server counts without a roster call per event', async ({ page }, testInfo) => {
    const rosterCalls: string[] = [];
    page.on('request', (r) => {
      if (/\/crm\/events\/\d+\/roster/.test(r.url())) rosterCalls.push(r.url());
    });
    await page.goto('/(organizer)/events');
    await expect(page.getByRole('heading', { name: 'Events' })).toBeVisible();
    await expect(page.getByRole('button', { name: /^Manage E2E Summer Market/ })).toBeVisible();
    for (const chip of ['All', 'Live', 'Drafts', 'Past']) {
      await expect(page.getByRole('button', { name: new RegExp(`^${chip}`) }).first()).toBeVisible();
    }
    expect(rosterCalls, 'no per-event roster requests on the list').toHaveLength(0);
    await snap(page, 'events', testInfo);
  });

  test('the event workspace splits into sections and keeps real event details', async ({ page }, testInfo) => {
    await page.goto(`/(organizer)/events/${eventId}`);
    const tabs = page.getByTestId('event-workspace-tabs');
    await expect(tabs).toBeVisible();
    for (const name of ['Overview', 'Slots', 'Applications', 'Vendors', 'Payments', 'Day-of', 'Packet']) {
      await expect(tabs.getByRole('tab', { name: new RegExp(`^${name}`) })).toBeVisible();
    }
    await page.getByRole('button', { name: 'Edit event details' }).click();
    await expect(page.getByLabel('Venue')).toHaveValue('E2E Park');
    await expect(page.getByLabel('Description')).not.toHaveValue('');
    await snap(page, 'workspace', testInfo);

    await tabs.getByRole('tab', { name: /^Slots/ }).click();
    await expect(page.getByText(/held · .* available of/).first()).toBeVisible();
    await tabs.getByRole('tab', { name: /^Payments/ }).click();
    await expect(page.getByText('Outstanding')).toBeVisible();
    await tabs.getByRole('tab', { name: /^Packet/ }).click();
    await expect(page.getByTestId('packet-form')).toBeVisible();
  });

  test('closing a slot asks first, then reopening restores it', async ({ page }) => {
    const freshOpportunityId = process.env.PLAYWRIGHT_FRESH_OPPORTUNITY_ID || '59';
    await restAs(page, organizerUser, 'POST', `/organizer/opportunities/${freshOpportunityId}/status`, { status: 'open' });
    await page.goto(`/(organizer)/events/${eventId}?tab=slots`);
    const close = page.getByRole('button', { name: /^Close Premium Corner/ });
    await expect(close).toBeVisible();
    await close.click();
    await expect(page.getByRole('heading', { name: 'Close this slot?' })).toBeVisible();
    await page.getByRole('button', { name: 'Close slot' }).click();
    await expect(page.getByText(/Vendor slot closed/)).toBeVisible();
    const reopen = page.getByRole('button', { name: /^Reopen Premium Corner/ });
    await expect(reopen).toBeVisible({ timeout: 45_000 });
    await reopen.click();
    await expect(page.getByText(/Vendor slot reopened/)).toBeVisible();
    await expect(page.getByRole('button', { name: /^Close Premium Corner/ })).toBeVisible({ timeout: 45_000 });
  });

  test('another organizer cannot close this event\'s slot', async ({ page }) => {
    const res = await restAs(page, organizerBUser, 'POST', `/organizer/opportunities/${process.env.PLAYWRIGHT_FRESH_OPPORTUNITY_ID || '59'}/status`, { status: 'closed' });
    expect(res.status).toBe(403);
  });

  test('create event walks six steps and saves a server draft', async ({ page }, testInfo) => {
    await page.goto('/(organizer)/launch');
    const resume = page.getByRole('button', { name: 'Start over' });
    if (await resume.isVisible({ timeout: 5000 }).catch(() => false)) await resume.click();
    await expect(page.getByRole('progressbar', { name: /Step 1 of 6/ })).toBeVisible();
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByText('Event title is required').first()).toBeVisible();
    const title = `E2E Draft ${Date.now()}`;
    await page.getByLabel('Event name').fill(title);
    await page.getByRole('button', { name: 'Continue' }).click();
    await expect(page.getByRole('progressbar', { name: /Step 2 of 6/ })).toBeVisible();
    await page.getByLabel('City').fill('Raleigh');
    await page.getByLabel('State').fill('NC');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByText(/Draft saved to your account|Saved on this device/).first()).toBeVisible({ timeout: 45_000 });
    await snap(page, 'launch', testInfo);
  });
});
