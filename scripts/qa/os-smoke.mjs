import { chromium } from '@playwright/test';

const base = 'http://localhost:8888';
const role = process.argv[2] || 'organizer';
const paths = process.argv.slice(3);
const user = role === 'organizer' ? 'e2e_org_a' : 'e2e_vendor_a';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const errors = [];
page.on('pageerror', (e) => errors.push('JS: ' + e.message + ' @ ' + String(e.stack || '').split('\n').slice(1, 3).join(' | ')));
await page.goto(base + '/wp-login.php');
await page.fill('#user_login', user);
await page.fill('#user_pass', 'lnkup-e2e-Passw0rd!');
await page.click('#wp-submit');
await page.waitForURL((u) => !String(u).includes('wp-login'));
for (const p of paths) {
  const res = await page.goto(base + p, { waitUntil: 'load' });
  const html = await page.content();
  const fatal = /Fatal error|critical error|Parse error|Warning:<\/b>|Notice:<\/b>|Deprecated:<\/b>/i.exec(html);
  const body = await page.evaluate(() => document.body.className.split(' ').filter((c) => c.startsWith('lnkup-')).join(' '));
  const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  console.log(res.status(), p, '|', body, '| overflow', over, fatal ? '| PROBLEM: ' + html.slice(Math.max(0, fatal.index - 200), fatal.index + 400).replace(/\s+/g, ' ') : '');
}
if (errors.length) console.log(errors.join('\n'));
await browser.close();


