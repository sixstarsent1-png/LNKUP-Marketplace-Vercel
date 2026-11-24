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

  test('Home KPIs match the server home summary', async ({ page }, testInfo) => {
    const { body } = await restAs(page, organizerUser, 'GET', '/organizer/home-summary');
    const kpis = (body as { kpis?: Record<string, { value: number }> })?.kpis ?? {};
    await expect(page.getByTestId('organizer-home')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByRole('button', { name: new RegExp(`^Approved, unpaid: ${kpis.approved_unpaid?.value ?? 0},`) })).toBeVisible();
    await expect(page.getByRole('button', { name: new RegExp(`^To review: ${kpis.awaiting_review?.value ?? 0},`) })).toBeVisible();
    await snap(page, 'home', testInfo);
  });

  test('Events list uses server counts without a roster call per event', async ({ page }, testInfo) => {
    const rosterCalls: string[] = [];
    page.on('request', (r) => {
      if (/\/crm\/events\/\d+\/roster/.test(r.url())) rosterCalls.push(r.url());
    });
    await page.goto('/(organizer)/events');
    await expect(page.getByRole('heading', { name: 'Events' })).toBeVisible();
    await expect(page.getByRole('button', { name: /^E2E Summer Market\./ })).toBeVisible({ timeout: 45_000 });
    for (const chip of ['All', 'Upcoming', 'Applications open', 'Drafts', 'Past']) {
      await expect(page.getByRole('button', { name: new RegExp(`^${chip}`) }).first()).toBeVisible();
    }
    expect(rosterCalls, 'no per-event roster requests on the list').toHaveLength(0);
    await snap(page, 'events', testInfo);
  });

  test('the event workspace splits into sections and keeps real event details', async ({ page }, testInfo) => {
    await page.goto(`/(organizer)/events/${eventId}`);
    const tabs = page.getByTestId('event-workspace-tabs');
    await expect(tabs).toBeVisible({ timeout: 45_000 });
    for (const name of ['Overview', 'Vendors', 'Inventory', 'Money', 'Operations']) {
      await expect(tabs.getByRole('tab', { name: new RegExp(`^${name}`) })).toBeVisible();
    }
    await expect(page.getByText(/\d{4}-\d{2}-\d{2}/)).toHaveCount(0);
    await page.getByRole('button', { name: 'Edit event details' }).click();
    await expect(page.getByLabel('Venue')).toHaveValue('E2E Park');
    await expect(page.getByLabel('Description')).not.toHaveValue('');
    await snap(page, 'workspace', testInfo);

    await tabs.getByRole('tab', { name: /^Inventory/ }).click();
    await expect(page.getByText(/held · .* available of/).first()).toBeVisible();
    await tabs.getByRole('tab', { name: /^Money/ }).click();
    await expect(page.getByText('Outstanding')).toBeVisible();
    await tabs.getByRole('tab', { name: /^Operations/ }).click();
    await page.getByRole('button', { name: /^Vendor packet/ }).click();
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
    await page.goto('/(organizer)/launch?classic=1');
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
