import { chromium } from '@playwright/test';

/**
 * Scan organizer routes for leaked PHP source, raw ISO timestamps and Stripe jargon in visible text.
 * Usage: node scripts/qa/os-leak-scan.mjs [eventId]
 */
const base = process.env.OS_BASE || 'http://localhost:8888';
const eventId = process.argv[2] || '968';
const routes = [
  '/organizer-dashboard/',
  '/organizer-dashboard/?view=events',
  '/organizer-dashboard/?view=applications',
  '/organizer-dashboard/?view=vendors',
  '/organizer-dashboard/?view=lists',
  '/organizer-dashboard/?view=invites',
  '/organizer-dashboard/?view=financial',
  '/organizer-dashboard/?view=intelligence',
  '/organizer-dashboard/?view=tours',
  '/organizer-dashboard/?view=team',
  '/organizer-dashboard/?view=profile',
  '/organizer-dashboard/?view=settings',
  '/organizer-dashboard/?view=start',
  '/organizer-dashboard/?view=help',
  `/organizer-dashboard/?event=${eventId}`,
  `/organizer-dashboard/?event=${eventId}&tab=inventory`,
  `/organizer-dashboard/?event=${eventId}&tab=money`,
  `/organizer-dashboard/?event=${eventId}&ops=1`,
  `/organizer-dashboard/?event=${eventId}&roster=1`,
  `/organizer-dashboard/?event=${eventId}&scan=1`,
];
const checks = [
  ['php', /ob_get_clean|esc_html_e?\(|<\?php|phpcs:ignore/],
  ['raw-timestamp', /\b\d{4}-\d{2}-\d{2} \d{2}:\d{2}/],
  ['stripe', /\bStripe\b/],
];

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
await page.goto(base + '/wp-login.php');
await page.fill('#user_login', process.env.OS_USER || 'e2e_org_a');
await page.fill('#user_pass', 'lnkup-e2e-Passw0rd!');
await page.click('#wp-submit', { timeout: 60000 });
await page.waitForURL((u) => !String(u).includes('wp-login'), { timeout: 60000 });
let problems = 0;
for (const route of routes) {
  await page.goto(base + route, { waitUntil: 'networkidle', timeout: 60000 }).catch(() => {});
  const text = await page.evaluate(() => document.querySelector('.lnkup-dashboard__main')?.innerText || document.body.innerText);
  const hits = checks.filter(([, rx]) => rx.test(text)).map(([name, rx]) => `${name}: "${(text.match(rx) || [''])[0]}"`);
  if (hits.length) {
    problems++;
    console.log('FAIL', route, hits.join(' | '));
  } else {
    console.log('ok  ', route);
  }
}
await browser.close();
process.exit(problems ? 1 : 0);
