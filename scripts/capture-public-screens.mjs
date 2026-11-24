/**
 * Capture full-page screenshots of public pages at several widths.
 *
 * Usage: node scripts/capture-public-screens.mjs [baseUrl] [outDir] [paths...]
 * Defaults: http://localhost:8888, docs/public-website-5/screenshots/after, the main public pages.
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const [, , baseArg, outArg, ...pathArgs] = process.argv;
const base = (baseArg || 'http://localhost:8888').replace(/\/$/, '');
const out = outArg || 'docs/public-website-5/screenshots/after';
const paths = pathArgs.length ? pathArgs : ['/', '/for-organizers/', '/for-vendors/', '/opportunities/', '/sign-up/'];
const widths = (process.env.WIDTHS || '390,1440').split(',').map((w) => parseInt(w, 10));

mkdirSync(out, { recursive: true });

const browser = await chromium.launch();
try {
  for (const width of widths) {
    const context = await browser.newContext({
      viewport: { width, height: width < 600 ? 844 : 900 },
      deviceScaleFactor: 1,
      reducedMotion: 'reduce',
      ...(process.env.UA ? { userAgent: process.env.UA, isMobile: width < 600, hasTouch: width < 600 } : {}),
    });
    const page = await context.newPage();
    for (const path of paths) {
      const slug = path.replace(/[^a-z0-9]+/gi, '-').replace(/^-|-$/g, '') || 'home';
      const response = await page.goto(base + path, { waitUntil: 'networkidle', timeout: 60000 });
      const file = join(out, `${slug}-${width}.png`);
      if (process.env.SCROLL_TO) {
        await page.evaluate((y) => window.scrollTo(0, y), parseInt(process.env.SCROLL_TO, 10));
      }
      if (process.env.STEPS) {
        const height = await page.evaluate(() => document.documentElement.scrollHeight);
        const viewport = page.viewportSize().height;
        for (let y = 0, i = 0; y < height && i < parseInt(process.env.STEPS, 10); y += viewport, i += 1) {
          await page.evaluate((top) => window.scrollTo(0, top), y);
          await page.waitForTimeout(150);
          await page.screenshot({ path: join(out, `${slug}-${width}-${String(i).padStart(2, '0')}.png`) });
        }
        continue;
      }
      await page.screenshot({ path: file, fullPage: process.env.FULL !== '0' });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      console.log(`${response ? response.status() : '???'} ${width}px ${path} -> ${file}${overflow > 1 ? ` (horizontal overflow ${overflow}px)` : ''}`);
    }
    await context.close();
  }
} finally {
  await browser.close();
}
