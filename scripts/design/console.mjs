/**
 * Print console errors and an evaluated expression for a page (design debugging).
 * Usage: node scripts/design/console.mjs <url> "<js expression>" [width]
 */
import { chromium } from '@playwright/test';

const [url, expr = 'document.title', width = '390'] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: Number(width), height: 900 } });
page.on('console', (msg) => {
  if (msg.type() === 'error' || msg.type() === 'warning') console.log(`[${msg.type()}] ${msg.text()}`);
});
page.on('pageerror', (err) => console.log(`[pageerror] ${err.message}`));
await page.goto(url, { waitUntil: 'networkidle' });
await page.waitForTimeout(1500);
console.log(JSON.stringify(await page.evaluate(expr), null, 1));
await browser.close();
