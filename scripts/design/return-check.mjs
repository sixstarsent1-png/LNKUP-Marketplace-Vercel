/**
 * Explore → event → back: report whether filters (URL) and scroll position come back.
 * Usage: node scripts/design/return-check.mjs [explore-url]
 */
import { chromium, devices } from '@playwright/test';

const url = process.argv[2] || 'http://localhost:8896/opportunities/?city=Charlotte';
const browser = await chromium.launch();
const ctx = await browser.newContext({ ...devices['iPhone 13'] });
const page = await ctx.newPage();
await page.goto(url, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(3000);
const cards = page.locator('#lnkup-results-grid .lnkup-event-card__title a');
const count = await cards.count();
const target = cards.nth(Math.min(2, count - 1));
await target.scrollIntoViewIfNeeded();
await page.mouse.wheel(0, 120);
await page.waitForTimeout(400);
const before = await page.evaluate(() => ({ y: Math.round(window.scrollY), href: location.pathname + location.search }));
await target.click();
await page.waitForLoadState('load');
const eventUrl = page.url();
await page.goBack({ waitUntil: 'commit', timeout: 60000 });
await page.waitForTimeout(9000);
const after = await page.evaluate(() => ({ y: Math.round(window.scrollY), href: location.pathname + location.search, city: document.getElementById('lnkup-find-city')?.value }));
console.log(JSON.stringify({ cards: count, before, eventUrl, after, restored: Math.abs(after.y - before.y) < 60 && after.href === before.href }, null, 1));
await browser.close();
