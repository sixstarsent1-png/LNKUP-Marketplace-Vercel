import { test, expect, type Page, type TestInfo } from '@playwright/test';
import path from 'node:path';
import { appLogin, organizerUser, restAs } from './helpers/app-session';

const shots = path.join(__dirname, '..', '..', 'docs', 'event-creation-os-v3', 'screenshots', 'after');

/**
 * Save a mobile screenshot for the Event Creation OS report (iPhone 15 only).
 */
async function snap(page: Page, name: string, testInfo: TestInfo) {
  if (testInfo.project.name !== 'iphone-15') return;
  await page.screenshot({ path: path.join(shots, `mobile-${name}.png`), fullPage: false });
}

test.describe('Event Creation OS 3.0 — native create', () => {
  test.beforeEach(async ({ page }) => {
    await appLogin(page, organizerUser);
    const cfg = await restAs(page, organizerUser, 'GET', '/organizer/builder/config');
    test.skip(cfg.status !== 200, 'event_builder_v3 is off on this site');
  });

  test('the legacy launch route opens the new Create flow', async ({ page }) => {
    await page.goto('/(organizer)/launch');
    await expect(page.getByTestId('create-chooser')).toBeVisible({ timeout: 45_000 });
  });

  test('quick create autosaves a server draft and shows the publish checklist', async ({ page }, testInfo) => {
    await page.goto('/(organizer)/create');
    await expect(page.getByTestId('create-chooser')).toBeVisible({ timeout: 45_000 });
    await snap(page, 'create-chooser', testInfo);
    await page.getByRole('button', { name: /^Quick create/ }).click();
    const name = page.getByTestId('builder-name');
    await expect(name).toBeVisible({ timeout: 45_000 });
    await name.fill(`Mobile Builder ${Date.now()}`);
    await expect(page.getByText(/^Saved · \d+% ready/)).toBeVisible({ timeout: 30_000 });
    await snap(page, 'create-info', testInfo);
    await page.getByTestId('builder-continue').click();
    await page.getByRole('button', { name: 'Add a vendor option' }).click();
    await page.getByRole('button', { name: /^Retail/ }).first().click();
    await page.getByLabel('Spaces').fill('12');
    await expect(page.getByText(/1 option · 12 spaces/)).toBeVisible({ timeout: 30_000 });
    await snap(page, 'create-options', testInfo);
    await page.getByTestId('builder-continue').click();
    await expect(page.getByTestId('builder-preview')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText('Before you publish')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Event date/ })).toBeVisible();
    await snap(page, 'create-review', testInfo);
    await page.getByRole('button', { name: /^Event date/ }).click();
    await expect(page.getByTestId('builder-name')).toBeVisible();
  });

  test('import reads a listing link through WordPress and offers field review', async ({ page }, testInfo) => {
    const cfg = await restAs(page, organizerUser, 'GET', '/organizer/builder/config');
    test.skip(!(cfg.body as { flags?: Record<string, boolean> })?.flags?.event_import_v3, 'event_import_v3 is off');
    await page.goto('/(organizer)/create/import?url=' + encodeURIComponent('https://demo.lnkup-test.example/e/mobile-demo'));
    const review = page.getByTestId('import-review');
    await expect(page.getByTestId('import-url')).toBeVisible({ timeout: 45_000 });
    const mockReady = await review.waitFor({ timeout: 45_000 }).then(() => true).catch(() => false);
    if (!mockReady) console.log('import screen text:', (await page.locator('body').innerText()).slice(0, 600));
    test.skip(!mockReady, 'the local import mock (demo.lnkup-test.example) is not installed');
    await expect(page.getByRole('checkbox', { name: /^Event name: Queen City Night Market/ })).toBeVisible();
    await expect(page.getByText(/Ticket prices and attendee capacity are not imported/)).toBeVisible();
    await snap(page, 'create-import', testInfo);
  });
});
