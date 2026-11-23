/**
 * Print computed styles for selectors on a page (design debugging).
 * Usage: node scripts/design/inspect.mjs <url> <selector> [prop,prop] [width]
 */
import { chromium } from '@playwright/test';

const [url, selector, props = 'display,margin,padding,min-height,height,gap', width = '1440'] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: Number(width), height: 900 } });
await page.goto(url, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(2500);
const out = await page.evaluate(
  ({ selector, props }) =>
    Array.from(document.querySelectorAll(selector))
      .slice(0, 3)
      .map((el) => {
        const cs = getComputedStyle(el);
        const r = el.getBoundingClientRect();
        return { tag: el.tagName, cls: el.className, box: `${Math.round(r.width)}x${Math.round(r.height)}`, ...Object.fromEntries(props.split(',').map((p) => [p, cs.getPropertyValue(p)])) };
      }),
  { selector, props },
);
console.log(JSON.stringify(out, null, 1));
await browser.close();
