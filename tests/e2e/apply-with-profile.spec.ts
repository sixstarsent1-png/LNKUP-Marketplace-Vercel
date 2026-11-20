import { test, expect, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? '';
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';
const appliedOpportunity = process.env.PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID ?? '';
const multiSlotOpportunity = process.env.PLAYWRIGHT_MULTI_SLOT_OPPORTUNITY_ID ?? '';

/**
 * Open the apply modal for a known opportunity via its deep link.
 */
async function openApplyModal(page: Page, opportunityId: string) {
  await page.goto(`/opportunities/?apply=${opportunityId}`);
  const modal = page.getByTestId('lnkup-apply-modal');
  await expect(modal).toBeVisible({ timeout: 15000 });
  return modal;
}

test.describe('Apply with Profile', () => {
  test('logged-out visitor sees sign-up and log-in actions in the modal', async ({ page }) => {
    test.skip(!multiSlotOpportunity, 'Run scripts/e2e/seed.php');
    const modal = await openApplyModal(page, multiSlotOpportunity);
    await expect(modal.getByText(/create a free vendor profile to apply/i)).toBeVisible();
    await expect(modal.getByRole('link', { name: /log in/i })).toBeVisible();
    await expect(modal.getByRole('link', { name: /sign up/i })).toBeVisible();
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Run scripts/e2e/seed.php');
      await loginPage(page, vendorUser, vendorPass);
    });

    test('modal titles the profile-based apply flow', async ({ page }) => {
      const modal = await openApplyModal(page, multiSlotOpportunity);
      await expect(modal.getByRole('heading', { name: /apply with your lnkup profile/i })).toBeVisible();
    });

    test('an existing application shows the already-applied state', async ({ page }) => {
      test.skip(!appliedOpportunity, 'Run scripts/e2e/seed.php');
      const modal = await openApplyModal(page, appliedOpportunity);
      await expect(modal.getByText(/already applied/i)).toBeVisible();
      await expect(page.getByTestId('lnkup-apply-submit')).toHaveCount(0);
    });

    test('mobile apply modal fits the viewport', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      await openApplyModal(page, multiSlotOpportunity);
      const box = await page.locator('.lnkup-apply-modal__dialog').boundingBox();
      expect(box?.width ?? 999).toBeLessThanOrEqual(390);
    });
  });

  test.describe('organizer application review', () => {
    test('requests view shows vendor application cards', async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
      await loginPage(page, organizerUser, organizerPass);
      await page.goto('/organizer-dashboard/?view=applications');
      const card = page.locator('.lnkup-crm-app-card--v2').first();
      await expect(card).toBeVisible();
      await expect(card.locator('.lnkup-request-row__title')).not.toBeEmpty();
      await expect(card.locator('.lnkup-request-row__badges')).toBeVisible();
    });
  });
});

test.describe('Marketplace compatibility', () => {
  test('search still works after apply-with-profile changes', async ({ page }) => {
    await page.goto('/opportunities/');
    await page.locator('#lnkup-search-input').fill('Charlotte');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
  });
});
