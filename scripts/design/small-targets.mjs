/**
 * List controls under 24px with their text and nearest classed ancestor.
 * Usage: node scripts/design/small-targets.mjs <url> [width] [user]
 */
import { chromium } from '@playwright/test';

const [url, width = '390', user] = process.argv.slice(2);
const WP = new URL(url).origin;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: Number(width), height: 900 } });
if (user) {
  await page.goto(`${WP}/wp-login.php`);
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill('lnkup-e2e-Passw0rd!');
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForURL((u) => !u.pathname.includes('wp-login'), { timeout: 60000 });
}
await page.goto(url, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(2500);
const rows = await page.evaluate(() =>
  [...document.querySelectorAll('a[href], button, select, input[type=checkbox]')]
    .filter((el) => {
      const r = el.getBoundingClientRect();
      const inline = getComputedStyle(el).display === 'inline' && el.closest('p, li, td');
      return r.width > 0 && r.height > 0 && !inline && (r.width < 24 || r.height < 24);
    })
    .map((el) => {
      let p = el.parentElement;
      while (p && !(typeof p.className === 'string' && p.className.trim())) p = p.parentElement;
      const r = el.getBoundingClientRect();
      return `${el.tagName} "${(el.textContent || el.getAttribute('aria-label') || '').trim().slice(0, 30)}" ${Math.round(r.width)}x${Math.round(r.height)} in .${p ? p.className.trim().split(/\s+/)[0] : '?'} display=${getComputedStyle(el).display}`;
    }),
);
console.log(rows.join('\n'));
await browser.close();
