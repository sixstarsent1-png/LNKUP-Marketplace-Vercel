/**
 * Print the ancestor chain of the first element matching a selector with margin/padding, to find
 * where vertical space comes from. Usage: node scripts/design/ancestors.mjs <url> <selector> [width]
 */
import { chromium } from '@playwright/test';

const [url, selector, width = '390'] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: Number(width), height: 900 } });
await page.goto(url, { waitUntil: 'networkidle' });
const rows = await page.evaluate((selector) => {
  const out = [];
  let el = document.querySelector(selector);
  while (el && el !== document.documentElement) {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    out.push(`${el.tagName.toLowerCase()}.${String(el.className).split(' ').slice(0, 3).join('.')} top=${Math.round(r.top)} pt=${cs.paddingTop} mt=${cs.marginTop} pb=${cs.paddingBottom}`);
    el = el.parentElement;
  }
  return out;
}, selector);
console.log(rows.join('\n'));
await browser.close();
