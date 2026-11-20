import { test, expect, type Page } from '@playwright/test';

/**
 * The hero logo must paint above the cover photo: the topmost element at its top edge is the logo.
 */
async function expectLogoAboveCover(page: Page) {
  const cover = page.locator('.lnkup-pub-hero__cover');
  test.skip((await cover.count()) === 0, 'Profile has no cover image');
  const logo = page.locator('.lnkup-pub-hero__logo').first();
  await expect(logo).toBeVisible();
  const box = await logo.boundingBox();
  expect(box, 'logo should have a box').not.toBeNull();
  const onTop = await page.evaluate(({ x, y }) => {
    const el = document.elementFromPoint(x, y);
    return !!el && !!el.closest('.lnkup-pub-hero__logo');
  }, { x: box!.x + box!.width / 2, y: box!.y + 4 });
  expect(onTop, 'cover image should not cover the top of the logo').toBe(true);
}

const eventId = process.env.PLAYWRIGHT_PUBLIC_EVENT_ID;
const organizerSlug = process.env.PLAYWRIGHT_PUBLIC_ORGANIZER_SLUG;
const vendorSlug = process.env.PLAYWRIGHT_PUBLIC_VENDOR_SLUG;

test.describe('Public event page', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!eventId, 'Set PLAYWRIGHT_PUBLIC_EVENT_ID to a published event');
    await page.goto(`/opportunities/?event=${eventId}`);
    await expect(page.getByTestId('lnkup-public-event')).toBeVisible();
  });

  test('offers an Add to calendar link when the event has a date', async ({ page }) => {
    const calendar = page.getByTestId('lnkup-public-event-calendar');
    test.skip((await calendar.count()) === 0, 'Event has no date');
    await expect(calendar).toHaveAttribute('href', /^https:\/\/calendar\.google\.com\/calendar\/render\?.*action=TEMPLATE/);
    await expect(calendar).toHaveAttribute('href', /dates=\d{8}/);
  });

  test('gallery images describe themselves', async ({ page }) => {
    const gallery = page.locator('.lnkup-public-event__gallery img');
    const total = await gallery.count();
    test.skip(total === 0, 'Event has no gallery');
    for (let i = 0; i < total; i++) {
      await expect(gallery.nth(i)).toHaveAttribute('alt', /\S/);
    }
  });
});

test.describe('Public organizer storefront', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!organizerSlug, 'Set PLAYWRIGHT_PUBLIC_ORGANIZER_SLUG');
  });

  test('event tabs show counts', async ({ page }) => {
    await page.goto(`/organizers/?organizer=${organizerSlug}`);
    const tabs = page.getByRole('tablist', { name: /organizer events/i });
    await expect(tabs.getByRole('tab', { name: /upcoming events\s*\d+/i })).toBeVisible();
    await expect(tabs.getByRole('tab', { name: /past events\s*\d+/i })).toBeVisible();
  });

  test('#about deep link opens the About tab', async ({ page }) => {
    await page.goto(`/organizers/?organizer=${organizerSlug}#about`);
    await expect(page.getByRole('tab', { name: 'About' })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tabpanel', { name: 'About' })).toBeVisible();
  });

  test('logo sits on top of the cover photo', async ({ page }) => {
    await page.goto(`/organizers/?organizer=${organizerSlug}`);
    await expectLogoAboveCover(page);
  });

  test('arrow keys move between tabs and update the hash', async ({ page }) => {
    await page.goto(`/organizers/?organizer=${organizerSlug}`);
    const selected = page.getByRole('tab', { selected: true });
    await selected.focus();
    await page.keyboard.press('ArrowRight');
    await expect(page).toHaveURL(/#(open|past|about|upcoming)$/);
  });
});

test.describe('Public vendor profile', () => {
  test.beforeEach(async ({ page }) => {
    test.skip(!vendorSlug, 'Set PLAYWRIGHT_PUBLIC_VENDOR_SLUG');
    await page.goto(`/vendors/?vendor=${vendorSlug}`);
    await expect(page.getByTestId('lnkup-public-vendor-profile')).toBeVisible();
  });

  test('shows the metrics row and an event history section', async ({ page }) => {
    await expect(page.getByText('Events completed')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Event history' })).toBeVisible();
  });

  test('logo sits on top of the cover photo', async ({ page }) => {
    await expectLogoAboveCover(page);
  });

  test('details aside renders trust or setup info when present', async ({ page }) => {
    const aside = page.getByRole('complementary', { name: /vendor details/i });
    test.skip((await aside.count()) === 0, 'Vendor has no trust badges or setup details');
    await expect(aside).toBeVisible();
  });

  test('social links are absolute URLs', async ({ page }) => {
    for (const name of ['Instagram', 'TikTok']) {
      const link = page.getByRole('link', { name, exact: true });
      if ((await link.count()) === 0) {
        continue;
      }
      const href = await link.first().getAttribute('href');
      expect(href, `${name} link`).toMatch(/^https:\/\/(www\.)?(instagram|tiktok)\.com\/[^/]/);
      expect(href, `${name} link should not nest a URL`).not.toMatch(/\.com\/@?https?:/);
    }
  });
});
