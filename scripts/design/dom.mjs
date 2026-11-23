/**
 * Sign in as a test user and print the child structure of the first element matching a selector.
 * Usage: node scripts/design/dom.mjs <user> <url> <selector>
 */
import { chromium } from '@playwright/test';

const [user, url, selector] = process.argv.slice(2);
const WP = new URL(url).origin;
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await page.goto(`${WP}/wp-login.php`);
await page.getByLabel(/username or email/i).fill(user);
await page.getByLabel(/^password$/i).fill('lnkup-e2e-Passw0rd!');
await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: /log in/i }).click()]);
await page.goto(url, { waitUntil: 'load', timeout: 60000 });
await page.waitForTimeout(2500);
const out = await page.evaluate((selector) => {
  const el = document.querySelector(selector);
  if (!el) return 'not found';
  const walk = (node, depth) => {
    if (depth > 3) return [];
    return Array.from(node.children).flatMap((c) => [
      `${'  '.repeat(depth)}${c.tagName.toLowerCase()}.${String(c.className).split(' ').filter(Boolean).slice(0, 2).join('.')} ${c.children.length ? '' : JSON.stringify((c.textContent || '').trim().slice(0, 40))}`,
      ...walk(c, depth + 1),
    ]);
  };
  return [`${el.tagName.toLowerCase()}.${el.className}`, ...walk(el, 1)].join('\n');
}, selector);
console.log(out);
await browser.close();
