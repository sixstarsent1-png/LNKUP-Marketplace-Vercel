import { test, expect, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';

/**
 * Fail the test if a native alert/confirm/prompt opens; organizer UI must use inline controls.
 */
function forbidNativeDialogs(page: Page): () => boolean {
  let opened = false;
  page.on('dialog', async (dialog) => {
    opened = true;
    await dialog.dismiss();
  });
  return () => opened;
}

test.describe('Organizer CRM', () => {
  test('organizer dashboard requires login', async ({ page }) => {
    await page.goto('/organizer-dashboard/');
    await expect(page).toHaveURL(/log-?in/);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
      await loginPage(page, organizerUser, organizerPass);
    });

    test('Vendors view loads the directory from the sidebar route', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=vendors');
      const directory = page.locator('#lnkup-crm-directory');
      await expect(directory).toBeVisible();
      await expect(directory.locator('.lnkup-skeleton, .lnkup-loading-state, .lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
      await expect(directory).not.toContainText(/could not load/i);
      await expect(page.locator('.lnkup-dashboard__nav-link.is-active[data-view="vendors"]')).toHaveCount(1);
    });

    test('vendor search narrows the directory', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=vendors');
      const directory = page.locator('#lnkup-crm-directory');
      await expect(directory.locator('.lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
      await page.locator('#lnkup-crm-vendor-search').fill('E2E Vendor B');
      await expect(directory).toContainText(/E2E Vendor B/i, { timeout: 15000 });
    });

    test('Lists view loads without a perpetual skeleton', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=lists');
      const lists = page.locator('#lnkup-crm-lists');
      await expect(lists).toBeVisible();
      await expect(lists.locator('.lnkup-skeleton, .lnkup-loading-state, .lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
      await expect(page.locator('#lnkup-crm-new-list-form')).toBeVisible();
    });

    test('Invites view loads and opens the picker without a browser dialog', async ({ page }) => {
      const dialogOpened = forbidNativeDialogs(page);
      await page.goto('/organizer-dashboard/?view=invites');
      const invites = page.locator('#lnkup-crm-invites');
      await expect(invites.locator('.lnkup-skeleton, .lnkup-loading-state, .lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
      await page.getByRole('button', { name: /invite a vendor/i }).click();
      await expect(page).toHaveURL(/view=vendors/);
      expect(dialogOpened()).toBe(false);
    });

    test('inviting the same vendor twice surfaces the duplicate', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=vendors');
      const directory = page.locator('#lnkup-crm-directory');
      await expect(directory.locator('.lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
      const invite = directory.locator('.lnkup-crm-invite').first();
      test.skip((await invite.count()) === 0, 'No discoverable vendors');
      const form = page.locator('#lnkup-crm-invite-form');
      const duplicate = page.getByText(/already has an open invite/i).first();
      const sendInvite = async (): Promise<number> => {
        await invite.click();
        await expect(form).toBeVisible();
        await form.getByLabel(/^event/i).selectOption({ index: 1 });
        await form.getByLabel(/^opportunity/i).selectOption({ index: 1 });
        const response = page.waitForResponse((r) => r.url().includes('/crm/invites') && r.request().method() === 'POST');
        await form.getByRole('button', { name: /send invite/i }).click();
        return (await response).status();
      };

      const first = await sendInvite();
      if (first === 409) {
        await expect(duplicate).toBeVisible();
        await page.locator('.lnkup-crm-slide__close').click();
      } else {
        expect(first).toBe(200);
        await expect(form).toBeHidden();
      }

      expect(await sendInvite()).toBe(409);
      await expect(duplicate).toBeVisible();
      await page.locator('.lnkup-crm-slide__close').click();
      await expect(form).toBeHidden();
    });
  });
});
