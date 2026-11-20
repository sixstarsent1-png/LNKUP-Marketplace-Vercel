import { test, expect, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER;
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS;
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;
const organizerEventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID;

const vendorRoutes = [
  '/vendor-dashboard/',
  '/vendor-dashboard/?view=applications',
  '/vendor-dashboard/?view=payments',
  '/vendor-dashboard/?view=documents',
  '/vendor-dashboard/?view=operations',
  '/vendor-dashboard/?view=growth',
  '/vendor-dashboard/?view=referrals',
  '/vendor-dashboard/?view=reviews',
  '/vendor-dashboard/?view=help',
  '/vendor-profile/',
  '/favorites/',
];

const organizerViews = [
  'home',
  'events',
  'applications',
  'financial',
  'vendors',
  'lists',
  'invites',
  'referrals',
  'operations',
  'intelligence',
  'create',
  'help',
  'profile',
];

const organizerDetailParams = ['', '&tab=requests', '&edit=1', '&roster=1', '&ops=1', '&ops=1&tab=readiness', '&scan=1'];

/**
 * Log into WordPress with the given credentials.
 */
async function login(page: Page, user: string, pass: string) {
  await loginPage(page, user, pass);
}

/**
 * Shared layout rules every dashboard page must follow.
 */
async function expectConsistentPage(page: Page, path: string) {
  await page.goto(path);
  await page.waitForLoadState('networkidle');

  const mainHeadings = page.locator('.lnkup-dashboard__content h1');
  await expect(mainHeadings, `${path} should have exactly one h1`).toHaveCount(1);
  await expect(page.locator('.lnkup-page-header__title').first(), `${path} should use the shared page header`).toBeVisible();

  await expect(page.getByText(/Loading\u2026|Loading\.\.\./), `${path} should not leave loading text behind`).toHaveCount(0);

  const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
  expect(overflows, `${path} should not scroll horizontally`).toBe(false);

  await expect(page.locator('.lnkup-dashboard__header'), `${path} should not render a second header row`).toHaveCount(0);
  await expect(page.locator('header.lnkup-workspace-bar'), `${path} should have one top bar`).toHaveCount(1);
}

test.describe('Dashboard top bar', () => {
  test.use({ viewport: { width: 1280, height: 800 } });

  test('organizer search sits in the top bar and Log out is in the profile menu', async ({ page }) => {
    test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
    await login(page, organizerUser!, organizerPass!);
    await page.goto('/organizer-dashboard/');
    const bar = page.locator('header.lnkup-workspace-bar');
    await expect(bar.getByRole('searchbox', { name: /search vendors/i })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Log out' })).toBeHidden();
    await bar.locator('#lnkup-workspace-profile').click();
    await expect(page.getByRole('link', { name: 'Log out' })).toBeVisible();
  });
});

test.describe('Dashboard visual consistency', () => {
  test.describe('vendor', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!vendorUser || !vendorPass, 'Set PLAYWRIGHT_VENDOR_USER and PLAYWRIGHT_VENDOR_PASS');
      await login(page, vendorUser!, vendorPass!);
    });

    for (const path of vendorRoutes) {
      test(`vendor ${path} follows the shared layout`, async ({ page }) => {
        await expectConsistentPage(page, path);
      });
    }
  });

  test.describe('organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await login(page, organizerUser!, organizerPass!);
    });

    for (const view of organizerViews) {
      test(`organizer ${view} follows the shared layout`, async ({ page }) => {
        await expectConsistentPage(page, `/organizer-dashboard/?view=${view}`);
      });
    }

    for (const detail of organizerDetailParams) {
      test(`organizer event detail "${detail || 'workspace'}" follows the shared layout`, async ({ page }) => {
        test.skip(!organizerEventId, 'Set PLAYWRIGHT_ORGANIZER_EVENT_ID to an event the organizer owns');
        await expectConsistentPage(page, `/organizer-dashboard/?event=${organizerEventId}${detail}`);
      });
    }
  });

  test.describe('organizer on a phone', () => {
    test.use({ viewport: { width: 390, height: 844 } });

    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await login(page, organizerUser!, organizerPass!);
    });

    for (const view of ['home', 'events', 'applications', 'financial', 'vendors', 'operations', 'create']) {
      test(`organizer ${view} fits a phone screen`, async ({ page }) => {
        await expectConsistentPage(page, `/organizer-dashboard/?view=${view}`);
      });
    }

    for (const detail of ['', '&tab=requests', '&scan=1']) {
      test(`organizer event detail "${detail || 'workspace'}" fits a phone screen`, async ({ page }) => {
        test.skip(!organizerEventId, 'Set PLAYWRIGHT_ORGANIZER_EVENT_ID to an event the organizer owns');
        await expectConsistentPage(page, `/organizer-dashboard/?event=${organizerEventId}${detail}`);
      });
    }
  });
});
