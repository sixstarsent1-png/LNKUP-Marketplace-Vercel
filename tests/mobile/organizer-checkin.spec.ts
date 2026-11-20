import { test, expect, type Page, type TestInfo } from '@playwright/test';
import path from 'node:path';
import { appLogin, appLogout, expectNoHorizontalOverflow, organizerUser, restAs } from './helpers/app-session';

const freeEventId = process.env.PLAYWRIGHT_FREE_EVENT_ID || '60';
const freeAppId = process.env.PLAYWRIGHT_FREE_APPLICATION_ID || '66';
const passCode = process.env.PLAYWRIGHT_FREE_PASS_CODE || '';
const mainEventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '56';
const securityUser = process.env.PLAYWRIGHT_STAFF_SECURITY_USER || 'e2e_staff_security';
const shots = path.join(__dirname, '..', '..', 'scripts', 'e2e', 'reports', 'mobile-shots', 'after');

type RosterRow = { application_id: number; checked_in?: boolean; reason?: string; booth?: string };

/**
 * Save a screenshot for the report (one device only).
 */
async function snap(page: Page, name: string, testInfo: TestInfo) {
  if (testInfo.project.name !== 'iphone-15') return;
  await page.screenshot({ path: path.join(shots, `organizer-${name}.png`), fullPage: true });
}

/**
 * Read one roster row straight from WordPress, the source of truth for check-in state.
 */
async function rosterRow(page: Page, eventId: string, appId: string): Promise<RosterRow | undefined> {
  const { body } = await restAs(page, organizerUser, 'GET', `/crm/events/${eventId}/roster`);
  const rows = ((body as { rows?: RosterRow[] })?.rows ?? []) as RosterRow[];
  return rows.find((row) => String(row.application_id) === appId);
}

/**
 * Open the check-in screen for one event and wait for the roster.
 */
async function openCheckIn(page: Page, eventId: string) {
  await page.goto(`/(organizer)/events/checkin?eventId=${eventId}`);
  await expect(page.getByRole('heading', { name: 'Check-in' })).toBeVisible();
  await expect(page.getByTestId('checkin-code-card')).toBeVisible({ timeout: 45_000 });
}

/**
 * Answer the override reason prompt.
 */
async function giveOverrideReason(page: Page, reason: string) {
  const field = page.getByRole('textbox', { name: /^Check in .* anyway\?$/ });
  await expect(field).toBeVisible();
  await field.fill(reason);
  await page.getByRole('button', { name: 'Check in', exact: true }).last().click();
}

test.describe('Organizer event-day check-in', () => {
  test('the check-in screen opens from the event Day-of tab and fits the screen', async ({ page }, testInfo) => {
    await appLogin(page, organizerUser);
    await page.goto(`/(organizer)/events/${freeEventId}?tab=dayof`);
    await page.getByRole('button', { name: 'Open check-in' }).click();
    await expect(page).toHaveURL(new RegExp(`checkin\\?eventId=${freeEventId}`));
    await expect(page.getByTestId('checkin-code-card')).toBeVisible({ timeout: 45_000 });
    await page.getByRole('button', { name: /^All/ }).click();
    await expect(page.getByTestId(`checkin-row-${freeAppId}`)).toBeVisible();
    await expect(page.getByText(/Checked in/).first()).toBeVisible();
    await expectNoHorizontalOverflow(page, 'Check-in');
    await snap(page, 'checkin', testInfo);
  });

  test.describe('state changes', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(async ({}, testInfo) => {
      test.skip(testInfo.project.name !== 'iphone-15', 'Check-in writes server state; run once per seed on one device');
      test.skip(!passCode, 'Seed did not export a pass code');
    });

    test('floor staff see the gate but cannot override it', async ({ page }) => {
      const before = await rosterRow(page, freeEventId, freeAppId);
      test.skip(Boolean(before?.checked_in), 'Already checked in — reseed to rerun');
      expect(before?.reason).toBe('documents');

      await appLogin(page, securityUser);
      await openCheckIn(page, freeEventId);
      await page.getByLabel('Pass code').fill(passCode);
      await page.getByRole('button', { name: 'Check in with code' }).click();
      await expect(page.getByText(/Documents need attention\. Find the vendor below/)).toBeVisible({ timeout: 45_000 });

      await page.getByRole('button', { name: /with an override$/ }).click();
      await giveOverrideReason(page, 'Paper permit shown at the gate');
      await expect(page.getByText(/Only the event owner or operations staff can override/)).toBeVisible({ timeout: 45_000 });
      expect((await rosterRow(page, freeEventId, freeAppId))?.checked_in).toBeFalsy();
      await appLogout(page);
    });

    test('an offline override is reported as not recorded', async ({ page, context }) => {
      await appLogin(page, organizerUser);
      await openCheckIn(page, freeEventId);
      await page.getByRole('button', { name: /with an override$/ }).click();
      const field = page.getByRole('textbox', { name: /^Check in .* anyway\?$/ });
      await field.fill('Paid cash at the gate');
      await context.setOffline(true);
      await page.getByRole('button', { name: 'Check in', exact: true }).last().click();
      await expect(page.getByText(/Not recorded — you appear to be offline/)).toBeVisible({ timeout: 45_000 });
      await context.setOffline(false);
      expect((await rosterRow(page, freeEventId, freeAppId))?.checked_in).toBeFalsy();
    });

    test('the owner overrides with a reason, and a repeat code entry says already checked in', async ({ page }, testInfo) => {
      await appLogin(page, organizerUser);
      await openCheckIn(page, freeEventId);
      await page.getByRole('button', { name: /with an override$/ }).click();
      const field = page.getByRole('textbox', { name: /^Check in .* anyway\?$/ });
      await field.fill('ok');
      await page.getByRole('button', { name: 'Check in', exact: true }).last().click();
      await expect(page.getByText('Enter at least 5 characters.')).toBeVisible();
      await giveOverrideReason(page, 'Paid cash at the gate');
      await expect(page.getByText(/Checked in with override/)).toBeVisible({ timeout: 45_000 });
      await expect.poll(async () => (await rosterRow(page, freeEventId, freeAppId))?.checked_in).toBe(true);

      await page.getByLabel('Pass code').fill(passCode);
      await page.getByRole('button', { name: 'Check in with code' }).click();
      await expect(page.getByText('Already checked in.')).toBeVisible({ timeout: 45_000 });
      await page.getByRole('button', { name: /^Checked in/ }).first().click();
      await expect(page.getByTestId(`checkin-row-${freeAppId}`)).toBeVisible();
      await snap(page, 'checkin-done', testInfo);
    });

    test('a booth can be assigned from the check-in list', async ({ page }) => {
      await appLogin(page, organizerUser);
      await openCheckIn(page, mainEventId);
      await page.getByRole('button', { name: /^All/ }).click();
      const boothButton = page.getByRole('button', { name: /^(Assign|Move) booth for / }).first();
      await expect(boothButton).toBeVisible({ timeout: 45_000 });
      await boothButton.click();
      const openBooth = page.getByRole('button', { name: /^Booth \d+\. (Standard|Open)/ }).first();
      await expect(openBooth).toBeVisible({ timeout: 45_000 });
      const label = (await openBooth.getAttribute('aria-label')) ?? '';
      const number = label.match(/^Booth (\d+)/)?.[1];
      await openBooth.click();
      await expect(page.getByText(new RegExp(`is now at booth ${number}\\.`))).toBeVisible({ timeout: 45_000 });
    });
  });

  test('the check-in endpoint refuses another organizer', async ({ page }) => {
    const { status } = await restAs(page, process.env.PLAYWRIGHT_ORGANIZER_B_USER || 'e2e_org_b', 'POST', `/operations/applications/${freeAppId}/checkin`, {
      action: 'checked_in',
    });
    expect(status).toBe(403);
  });
});
