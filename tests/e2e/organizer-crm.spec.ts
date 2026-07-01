import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

/**
 * Log into WordPress with organizer credentials.
 */
async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Organizer CRM', () => {
  test('organizer dashboard requires login when unauthenticated', async ({ page }) => {
    await page.goto('/organizer-dashboard/');
    await expect(page).toHaveURL(/wp-login|login/);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('dashboard loads CRM shell with vendor directory section', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      await expect(page.locator('#lnkup-organizer-crm')).toBeVisible();
      await expect(page.locator('#overview')).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__nav-icon').first()).toBeVisible();
      await expect(page.getByRole('heading', { name: /welcome back/i })).toBeVisible();
      await expect(page.getByRole('link', { name: /create event/i }).first()).toBeVisible();

      const sidebar = page.locator('#lnkup-sidebar');
      const sidebarBox = await sidebar.boundingBox();
      expect(sidebarBox?.width).toBeGreaterThanOrEqual(252);
      expect(sidebarBox?.width).toBeLessThanOrEqual(268);

      const navIcon = page.locator('.lnkup-dashboard__nav-icon svg').first();
      const iconBox = await navIcon.boundingBox();
      expect(iconBox?.width).toBeLessThanOrEqual(22);
      expect(iconBox?.height).toBeLessThanOrEqual(22);

      await expect(page.locator('.lnkup-dashboard__workspace-chevron').first()).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__sidebar-footer')).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__profile')).toBeVisible();
      await expect(page.locator('.lnkup-dashboard__user-card')).toHaveCount(0);

      const icon = page.locator('.lnkup-stat-card__icon').first();
      await expect(icon).toBeVisible();
      const statBox = await icon.boundingBox();
      expect(statBox?.width).toBeLessThanOrEqual(48);
      expect(statBox?.height).toBeLessThanOrEqual(48);
      const statSvg = icon.locator('svg').first();
      const statSvgBox = await statSvg.boundingBox();
      expect(statSvgBox?.width).toBeLessThanOrEqual(20);
      expect(statSvgBox?.height).toBeLessThanOrEqual(20);
      const trendIcon = page.locator('.lnkup-stat-card__trend-icon svg').first();
      if (await trendIcon.count()) {
        const trendBox = await trendIcon.boundingBox();
        expect(trendBox?.width).toBeLessThanOrEqual(16);
        expect(trendBox?.height).toBeLessThanOrEqual(16);
      }
      await expect(page.locator('.lnkup-organizer-fin-strip')).toBeVisible();
      await expect(page.locator('#vendors')).toBeVisible();
      await expect(page.locator('#lnkup-crm-directory')).toBeVisible();
    });

    test('vendor directory discover tab loads results', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      await expect(page.getByRole('button', { name: /discover/i })).toHaveClass(/is-active/);
      await expect(page.locator('#lnkup-crm-directory')).not.toContainText('Loading vendors', { timeout: 15000 });
    });

    test('vendor directory includes reputation sort dropdown', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      await expect(page.locator('#lnkup-crm-vendor-sort')).toBeVisible();
      await page.locator('#lnkup-crm-vendor-sort').selectOption('rating');
      await expect(page.locator('#lnkup-crm-directory')).not.toContainText('Loading vendors', { timeout: 15000 });
    });

    test('CRM widgets panel loads overview', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      await expect(page.locator('#lnkup-crm-widgets-grid')).toBeVisible({ timeout: 15000 });
      await expect(page.locator('.lnkup-crm-widget').first()).toBeVisible({ timeout: 15000 });
    });

    test('events section may include operations link', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (await opsBtn.count()) {
        await expect(opsBtn).toBeVisible();
      }
    });

    test('application review shows intelligence cards with compare', async ({ page }) => {
      await page.goto('/organizer-dashboard/#applications');
      const cards = page.locator('.lnkup-crm-app-card');
      const count = await cards.count();
      if (count === 0) {
        await expect(page.getByText(/no applications/i)).toBeVisible();
        return;
      }
      await expect(cards.first()).toBeVisible();
      await expect(page.locator('#lnkup-crm-compare-bar')).toBeAttached();
      await expect(page.locator('#lnkup-crm-compare-bar')).toBeHidden();
      const filterTab = page.getByRole('button', { name: /pending/i }).first();
      await expect(filterTab).toBeVisible();
      const intel = cards.first().locator('.lnkup-crm-app-card__intel');
      if (await intel.count()) {
        await expect(intel).toContainText(/score/i);
      }
    });

    test('intelligence panel is linked from organizer dashboard', async ({ page }) => {
      await page.goto('/organizer-dashboard/#intelligence');
      await expect(page.locator('#intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-organizer-intelligence')).toBeVisible();
      const scoreLabel = page.locator('.lnkup-intel-score__label').first();
      if (await scoreLabel.count()) {
        const labelBox = await scoreLabel.boundingBox();
        const scoreBox = await page.locator('.lnkup-intel-score').first().boundingBox();
        expect(labelBox?.width).toBeLessThanOrEqual((scoreBox?.width ?? 0) + 4);
      }
    });

    test('vendor lists and invites sections exist', async ({ page }) => {
      await page.goto('/organizer-dashboard/#lists');
      await expect(page.locator('#lnkup-crm-lists')).toBeVisible();
      await expect(page.locator('#lnkup-crm-new-list-form')).toBeVisible();
      await page.goto('/organizer-dashboard/#invites');
      await expect(page.locator('#lnkup-crm-invites')).toBeVisible();
      await expect(page.getByRole('button', { name: /invite a vendor/i })).toBeVisible();
    });

    test('invites panel opens vendor picker without browser prompt', async ({ page }) => {
      await page.goto('/organizer-dashboard/#invites');
      let promptCalled = false;
      page.on('dialog', async (dialog) => {
        promptCalled = true;
        await dialog.dismiss();
      });
      await page.getByRole('button', { name: /invite a vendor/i }).click();
      await page.waitForTimeout(500);
      expect(promptCalled).toBe(false);
      await expect(page.locator('.lnkup-crm-slide, #vendors')).toBeVisible({ timeout: 5000 });
    });

    test('discover vendors invite button is present when cards load', async ({ page }) => {
      await page.goto('/organizer-dashboard/#vendors');
      const expandBtn = page.locator('[data-expand-panel="vendors"]').first();
      if (await expandBtn.count()) {
        await expandBtn.click();
      }
      await expect(page.locator('#lnkup-crm-directory')).not.toContainText('Could not load directory', { timeout: 15000 });
      const inviteBtn = page.locator('.lnkup-crm-invite').first();
      if (await inviteBtn.count()) {
        await expect(inviteBtn).toBeVisible();
      }
    });

    test('create list inline form is available', async ({ page }) => {
      await page.goto('/organizer-dashboard/#lists');
      const form = page.locator('#lnkup-crm-new-list-form');
      await expect(form).toBeVisible();
      await expect(form.locator('input[type="text"]')).toBeVisible();
    });

    test('launch event section includes success root', async ({ page }) => {
      await page.goto('/organizer-dashboard/#create');
      await expect(page.locator('#lnkup-launch-success-root')).toBeAttached();
    });

    test('sidebar nav includes Create Event label', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      await expect(page.getByRole('link', { name: /^create event$/i })).toBeVisible();
    });

    test('intelligence panel resolves without perpetual loading text', async ({ page }) => {
      await page.goto('/organizer-dashboard/#intelligence');
      await expect(page.locator('#lnkup-organizer-intelligence')).toBeVisible();
      await expect(page.locator('#lnkup-organizer-intelligence')).not.toContainText(/loading intelligence/i, { timeout: 15000 });
      await expect(page.locator('#lnkup-organizer-intelligence .lnkup-crm-skeleton')).toHaveCount(0, { timeout: 15000 });
    });

    test('mobile bottom nav icons are constrained', async ({ page }) => {
      await page.setViewportSize({ width: 375, height: 667 });
      await page.goto('/organizer-dashboard/#overview');
      await expect(page.locator('#lnkup-bottom-nav')).toBeVisible();
      const bottomIcon = page.locator('.lnkup-dashboard__bottom-icon svg').first();
      await expect(bottomIcon).toBeVisible();
      const box = await bottomIcon.boundingBox();
      expect(box?.width).toBeLessThanOrEqual(22);
      expect(box?.height).toBeLessThanOrEqual(22);
    });
  });
});

test.describe('Organizer CRM regression', () => {
  test('opportunities marketplace still loads after CRM plugin update', async ({ page }) => {
    await page.goto('/opportunities/');
    await expect(page.getByRole('heading', { name: /find events/i })).toBeVisible();
    await expect(page.locator('#lnkup-results-grid')).toBeVisible();
  });

  test('vendor dashboard still loads', async ({ page }) => {
    await page.goto('/vendor-dashboard/');
    await expect(page).toHaveURL(/vendor-dashboard|wp-login|login/);
  });

  test('vendor mobile bottom nav icons are constrained', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 });
    await page.goto('/vendor-dashboard/');
    if (await page.url().match(/wp-login|login/)) {
      test.skip(true, 'Vendor login required for mobile nav test');
    }
    const bottomNav = page.locator('#lnkup-bottom-nav');
    if (await bottomNav.count()) {
      const bottomIcon = page.locator('.lnkup-dashboard__bottom-icon svg').first();
      const box = await bottomIcon.boundingBox();
      expect(box?.width).toBeLessThanOrEqual(22);
      expect(box?.height).toBeLessThanOrEqual(22);
    }
  });
});
