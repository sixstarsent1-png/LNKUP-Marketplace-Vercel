import { test, expect } from '@playwright/test';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

async function loginVendor(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(vendorUser!);
  await page.getByLabel(/^password$/i).fill(vendorPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

async function openFirstApplyModal(page: import('@playwright/test').Page) {
  await page.goto('/opportunities/');
  const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
  if (await prelaunch.isVisible().catch(() => false)) {
    test.skip(true, 'Marketplace prelaunch gate active');
    return null;
  }
  const card = page.locator('#lnkup-results-grid .lnkup-event-card--v2').first();
  await expect(card).toBeVisible({ timeout: 15000 });
  const applyBtn = card.getByRole('link', { name: /^apply$/i });
  if (await applyBtn.getAttribute('aria-disabled') === 'true') {
    return null;
  }
  await applyBtn.click();
  const modal = page.locator('#lnkup-apply-modal');
  await expect(modal).toBeVisible();
  await expect(page.getByTestId('lnkup-apply-modal')).toBeVisible({ timeout: 10000 });
  return modal;
}

test.describe('Apply with Profile', () => {
  test('logged-out user sees signup/login CTA', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    if (await prelaunch.isVisible().catch(() => false)) {
      test.skip(true, 'Marketplace prelaunch gate active');
      return;
    }
    await expect(page.locator('#lnkup-results-grid .lnkup-event-card--v2').first()).toBeVisible({ timeout: 15000 });
    await page.locator('#lnkup-results-grid .lnkup-event-card--v2').first().getByRole('link', { name: /^apply$/i }).click();
    await expect(page.locator('#lnkup-apply-modal')).toBeVisible();
    await expect(page.getByText(/create a free vendor profile to apply/i)).toBeVisible();
    await expect(page.getByRole('link', { name: /log in/i })).toBeVisible();
  });

  test.describe('authenticated vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await loginVendor(page);
    });

    test('apply modal shows Apply with your LNKup profile title', async ({ page }) => {
      const modal = await openFirstApplyModal(page);
      if (!modal) {
        test.skip(true, 'No open apply opportunity on feed');
        return;
      }
      await expect(page.getByRole('heading', { name: /apply with your lnkup profile/i })).toBeVisible();
    });

    test('vendor with profile sees profile snapshot in modal', async ({ page }) => {
      const modal = await openFirstApplyModal(page);
      if (!modal) {
        test.skip(true, 'No open apply opportunity on feed');
        return;
      }
      const createProfile = page.getByText(/build your vendor profile first/i);
      if (await createProfile.isVisible().catch(() => false)) {
        test.skip(true, 'Vendor has no profile in test env');
        return;
      }
      const alreadyApplied = page.getByText(/you already applied/i);
      if (await alreadyApplied.isVisible().catch(() => false)) {
        test.skip(true, 'Already applied to first opportunity');
        return;
      }
      await expect(page.getByTestId('lnkup-apply-profile-snapshot')).toBeVisible();
      await expect(page.getByTestId('lnkup-apply-form')).toBeVisible();
    });

    test('vendor can submit application with message', async ({ page }) => {
      const modal = await openFirstApplyModal(page);
      if (!modal) {
        test.skip(true, 'No open apply opportunity on feed');
        return;
      }
      const form = page.getByTestId('lnkup-apply-form');
      if (!(await form.isVisible().catch(() => false))) {
        test.skip(true, 'Apply form not available (blocked or already applied)');
        return;
      }
      await page.locator('#lnkup-apply-message').fill('E2E apply with profile test message.');
      await page.locator('[name="accepted_terms"]').check();
      await page.getByTestId('lnkup-apply-submit').click();
      await expect(page.locator('#lnkup-apply-success')).toBeVisible({ timeout: 15000 });
      await expect(page.getByText(/application submitted/i)).toBeVisible();
    });

    test('duplicate apply shows already applied state', async ({ page }) => {
      const modal = await openFirstApplyModal(page);
      if (!modal) {
        test.skip(true, 'No open apply opportunity on feed');
        return;
      }
      const form = page.getByTestId('lnkup-apply-form');
      if (await form.isVisible().catch(() => false)) {
        await page.locator('#lnkup-apply-message').fill('First submission for duplicate test.');
        await page.locator('[name="accepted_terms"]').check();
        await page.getByTestId('lnkup-apply-submit').click();
        await expect(page.locator('#lnkup-apply-success')).toBeVisible({ timeout: 15000 });
        await page.locator('.lnkup-apply-modal__close').click();
      }
      await openFirstApplyModal(page);
      await expect(page.getByText(/you already applied/i)).toBeVisible({ timeout: 10000 });
    });

    test('mobile apply modal does not overflow viewport', async ({ page }) => {
      await page.setViewportSize({ width: 390, height: 844 });
      const modal = await openFirstApplyModal(page);
      if (!modal) {
        test.skip(true, 'No open apply opportunity on feed');
        return;
      }
      const dialog = page.locator('.lnkup-apply-modal__dialog');
      const box = await dialog.boundingBox();
      expect(box && box.width <= 390).toBeTruthy();
    });
  });

  test.describe('organizer application review', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('applications view shows vendor application details', async ({ page }) => {
      await page.goto('/organizer-dashboard/?view=applications');
      const card = page.locator('.lnkup-crm-app-card--v2').first();
      if (!(await card.isVisible().catch(() => false))) {
        test.skip(true, 'No applications for organizer in test env');
        return;
      }
      await expect(card).toBeVisible();
      const message = card.getByTestId('lnkup-app-message');
      const docsSummary = card.getByTestId('lnkup-app-docs-summary');
      const hasMessage = await message.isVisible().catch(() => false);
      const hasDocs = await docsSummary.isVisible().catch(() => false);
      expect(hasMessage || hasDocs || (await card.locator('.lnkup-crm-app-card__name').isVisible())).toBeTruthy();
    });
  });
});

test.describe('Marketplace compatibility', () => {
  test('search still works after apply with profile changes', async ({ page }) => {
    await page.goto('/opportunities/');
    const prelaunch = page.getByTestId('lnkup-prelaunch-marketplace');
    if (await prelaunch.isVisible().catch(() => false)) {
      return;
    }
    await page.locator('#lnkup-search-input').fill('Charlotte');
    await expect(page.locator('#lnkup-result-count')).toContainText(/opportunit/i, { timeout: 10000 });
  });
});
