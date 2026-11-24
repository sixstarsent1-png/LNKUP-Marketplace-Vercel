import { chromium } from '@playwright/test';
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: Number(process.argv[3] || 390), height: 900 } });
await page.goto('http://localhost:8888/wp-login.php');
await page.fill('#user_login', process.argv[4] || 'e2e_org_a');
await page.fill('#user_pass', 'lnkup-e2e-Passw0rd!');
await page.click('#wp-submit');
await page.waitForURL((u) => !String(u).includes('wp-login'));
await page.goto('http://localhost:8888' + process.argv[2], { waitUntil: 'networkidle' });
const out = await page.evaluate(() => {
  const vw = document.documentElement.clientWidth;
  const res = [];
  document.querySelectorAll('body *').forEach((el) => {
    const r = el.getBoundingClientRect();
    if (r.right > vw + 0.5 && r.width > 0 && getComputedStyle(el).position !== 'fixed') {
      res.push({ tag: el.tagName, cls: String(el.className).slice(0, 80), right: Math.round(r.right), w: Math.round(r.width) });
    }
  });
  return res.slice(0, 15);
});
console.log(JSON.stringify(out, null, 1));
await browser.close();
