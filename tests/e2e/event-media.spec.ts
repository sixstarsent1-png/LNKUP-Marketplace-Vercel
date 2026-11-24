import { test, expect, type Page } from '@playwright/test';

/**
 * Event media: every marketplace card shows real artwork (never the old "LNKup" box),
 * images load, flyers/logos are contained, and frames keep a fixed ratio.
 */

/** All card media frames rendered on the page. */
function cardMedia(page: Page) {
  return page.getByTestId('lnkup-featured-card').or(page.locator('.lnkup-event-card'));
}

/** Wait for every card image to finish loading (or failing) and report natural widths. */
async function cardImageWidths(page: Page): Promise<Array<{ src: string; width: number; fit: string }>> {
  await page.waitForLoadState('load');
  return page.evaluate(async () => {
    const imgs = Array.from(document.querySelectorAll<HTMLImageElement>('.lnkup-event-card__media img, .lp-event__media img, .lnkup-event-hero__media img'));
    await Promise.all(
      imgs.map((img) => {
        img.loading = 'eager';
        return img.complete ? Promise.resolve() : new Promise((r) => { img.addEventListener('load', r, { once: true }); img.addEventListener('error', r, { once: true }); });
      })
    );
    return imgs.map((img) => ({ src: img.currentSrc || img.src, width: img.naturalWidth, fit: getComputedStyle(img).objectFit }));
  });
}

test.describe('Event media', () => {
  test('homepage marketplace cards show real artwork, not placeholders', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: 'Events booking vendors right now' })).toBeVisible();
    await expect(page.getByText('Discover upcoming festivals, markets, and events actively accepting vendors.')).toBeVisible();
    await expect(cardMedia(page).first()).toBeVisible();
    await expect(page.locator('.lnkup-event-card__placeholder')).toHaveCount(0);
    const images = await cardImageWidths(page);
    expect(images.length).toBeGreaterThan(0);
    for (const img of images) {
      expect(img.width, `loaded: ${img.src}`).toBeGreaterThan(0);
    }
  });

  test('Find Events cards resolve media and keep a stable frame', async ({ page }) => {
    await page.goto('/opportunities/');
    await page.waitForLoadState('networkidle');
    await expect(page.locator('.lnkup-event-card__placeholder')).toHaveCount(0);
    const images = await cardImageWidths(page);
    for (const img of images) {
      expect(img.width, `loaded: ${img.src}`).toBeGreaterThan(0);
    }
    const frames = await page.evaluate(() =>
      Array.from(document.querySelectorAll<HTMLElement>('.lnkup-event-card:not(.lnkup-event-card--skeleton) .lnkup-event-card__media')).filter((frame) => frame.checkVisibility()).map((frame) => {
        const f = frame.getBoundingClientRect();
        const img = frame.querySelector('img');
        const i = img ? img.getBoundingClientRect() : null;
        return { w: f.width, h: f.height, filled: !!i && Math.abs(i.width - f.width) < 2 && Math.abs(i.height - f.height) < 2 };
      })
    );
    for (const frame of frames) {
      expect(frame.w).toBeGreaterThan(0);
      expect(frame.h).toBeGreaterThan(0);
      expect(frame.filled, 'image covers its frame (no blank area)').toBe(true);
    }
  });

  test('flyers and logos are contained, photos fill the frame', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('load');
    const fits = await page.evaluate(() =>
      Array.from(document.querySelectorAll<HTMLElement>('.lnkup-media')).map((frame) => ({
        presentation: Array.from(frame.classList).find((c) => c.startsWith('lnkup-media--') && !c.includes('fit')) || '',
        fit: getComputedStyle(frame.querySelector('img') as HTMLImageElement).objectFit,
      }))
    );
    for (const f of fits) {
      if (f.presentation === 'lnkup-media--flyer' || f.presentation === 'lnkup-media--logo') {
        expect(f.fit).toBe('contain');
      } else {
        expect(f.fit).toBe('cover');
      }
    }
  });
});
