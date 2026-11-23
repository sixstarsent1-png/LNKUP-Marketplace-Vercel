/**
 * Capture public reference sites (first viewport) for the design review.
 * Usage: node scripts/design/capture-refs.mjs
 */
import { chromium, devices } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const out = join('docs', 'design-pass', 'refs');
mkdirSync(out, { recursive: true });
const sites = [
  { id: 'posh', url: 'https://posh.vip/' },
  { id: 'posh-explore', url: 'https://posh.vip/explore' },
  { id: 'eventbrite', url: 'https://www.eventbrite.com/' },
  { id: 'eventbrite-feed', url: 'https://www.eventbrite.com/d/nc--charlotte/events/' },
  { id: 'lnkup-live', url: 'https://thelnkup.com/' },
];
const widths = {
  phone: { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } },
  desktop: { viewport: { width: 1440, height: 900 } },
};
const browser = await chromium.launch();
for (const [w, opts] of Object.entries(widths)) {
  const ctx = await browser.newContext(opts);
  const page = await ctx.newPage();
  for (const s of sites) {
    try {
      await page.goto(s.url, { waitUntil: 'domcontentloaded', timeout: 45000 });
      await page.waitForTimeout(4000);
      await page.screenshot({ path: join(out, `${s.id}-${w}.jpg`), type: 'jpeg', quality: 70 });
      console.log(`${s.id} ${w}: ok`);
    } catch (e) {
      console.log(`${s.id} ${w}: ${String(e.message).split('\n')[0]}`);
    }
  }
  await ctx.close();
}
await browser.close();
