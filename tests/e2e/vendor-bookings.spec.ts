import { test, expect, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? '';
const otherVendor = process.env.PLAYWRIGHT_VENDOR_B_USER ?? '';
const password = process.env.PLAYWRIGHT_PASSWORD ?? '';

/**
 * Open My Bookings on a given tab.
 */
async function openBookings(page: Page, bucket = 'all') {
  await page.goto(`/vendor-dashboard/?view=bookings&bucket=${bucket}`);
  await expect(page.getByTestId('lnkup-vendor-bookings')).toBeVisible();
}

test.describe('My Bookings', () => {
  test.skip(!vendorUser || !vendorPass, 'Run scripts/e2e/seed.php');

  test.beforeEach(async ({ page }) => {
    await loginPage(page, vendorUser, vendorPass);
  });

  test('tabs show counts and the All tab lists booking cards', async ({ page }) => {
    await openBookings(page);
    await expect(page.getByTestId('lnkup-bookings-tab-upcoming')).toBeVisible();
    await expect(page.getByTestId('lnkup-bookings-tab-all')).toHaveAttribute('aria-current', 'page');
    await expect(page.getByTestId('lnkup-booking-card').first()).toBeVisible();
  });

  test('pending payment tab offers Pay with the server amount', async ({ page }) => {
    await openBookings(page, 'pending_payment');
    const cards = page.getByTestId('lnkup-booking-card');
    test.skip((await cards.count()) === 0, 'Seed has no approved unpaid booking');
    await expect(cards.first().getByRole('button', { name: /^Pay \$/ })).toBeVisible();
  });

  test('a booking opens a detail page with timeline and payment sections', async ({ page }) => {
    await openBookings(page);
    await page.getByTestId('lnkup-booking-open').first().click();
    await expect(page.getByTestId('lnkup-booking-detail')).toBeVisible();
    await expect(page.getByTestId('lnkup-booking-timeline')).toBeVisible();
    await expect(page.getByTestId('lnkup-booking-timeline').locator('li').first()).toContainText(/Application submitted/i);
    await expect(page.getByTestId('lnkup-booking-payment')).toBeVisible();
    await expect(page.getByRole('link', { name: /^(← )?Bookings$/i }).first()).toBeVisible();
  });

  test('city filter narrows the list without leaving the page', async ({ page }) => {
    await openBookings(page);
    const city = page.getByTestId('lnkup-bookings-filters').getByRole('combobox').first();
    test.skip((await city.count()) === 0, 'No city filter options');
    const options = await city.locator('option').allTextContents();
    test.skip(options.length < 2, 'Only one city');
    await city.selectOption({ index: 1 });
    await expect(page).toHaveURL(/view=bookings/);
    await expect(page.getByTestId('lnkup-vendor-bookings')).toBeVisible();
  });

  test('cards stack to one column on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await openBookings(page);
    const box = await page.getByTestId('lnkup-booking-card').first().boundingBox();
    expect(box?.width ?? 999).toBeLessThanOrEqual(390);
  });
});

test.describe('My Bookings isolation', () => {
  test.skip(!vendorUser || !vendorPass || !otherVendor || !password, 'Run scripts/e2e/seed.php');

  test("another vendor cannot open someone else's booking", async ({ browser }) => {
    const owner = await browser.newPage();
    await loginPage(owner, vendorUser, vendorPass);
    await openBookings(owner);
    const bookingId = await owner.getByTestId('lnkup-booking-card').first().getAttribute('data-booking-id');
    await owner.close();
    expect(bookingId).toBeTruthy();

    const context = await browser.newContext();
    const intruder = await context.newPage();
    await loginPage(intruder, otherVendor, password);
    await intruder.goto(`/vendor-dashboard/?view=bookings&booking=${bookingId}`);
    await expect(intruder.getByText(/Booking not found/i)).toBeVisible();
    await context.close();
  });
});
