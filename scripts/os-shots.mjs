/**
 * Capture loaded vendor or organizer screens for visual QA.
 *
 * Usage: node scripts/os-shots.mjs [--role=vendor|organizer] [outDir] [widths] [screens]
 *   widths:  comma list, default 1920,1440,1280,1024,768,430,390,360
 *   screens: comma list of keys below, default all for the role
 *
 * Waits for network idle and for skeleton loaders to disappear, then records
 * page-level horizontal overflow per screen and width in overflow.json, and
 * REST request counts/timings per screen in rest.json.
 */
import { chromium } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const args = process.argv.slice(2);
const roleArg = args.find((a) => a.startsWith('--role='));
const role = roleArg ? roleArg.split('=')[1] : 'vendor';
const positional = args.filter((a) => !a.startsWith('--'));

const base = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8888';
const pass = process.env.PLAYWRIGHT_PASSWORD || 'lnkup-e2e-Passw0rd!';
const user =
  role === 'organizer'
    ? process.env.PLAYWRIGHT_ORGANIZER_USER || 'e2e_org_a'
    : process.env.PLAYWRIGHT_VENDOR_USER || 'e2e_vendor_a';
const defaultOut = role === 'organizer' ? 'docs/organizer-experience-v2/screenshots/after' : 'docs/vendor-experience-v2/screenshots/after';
const outDir = positional[0] || defaultOut;
const widths = (positional[1] || '1920,1440,1280,1024,768,430,390,360').split(',').map(Number);

const VENDOR_SCREENS = {
  home: '/vendor-dashboard/',
  explore: '/opportunities/',
  bookings: '/vendor-dashboard/?view=applications',
  wallet: '/vendor-dashboard/?view=payments',
  operations: '/vendor-dashboard/?view=operations',
  growth: '/vendor-dashboard/?view=growth',
  passport: '/vendor-dashboard/?view=passport',
  documents: '/vendor-dashboard/?view=documents',
  saved: '/favorites/',
};

const ORG = '/organizer-dashboard/';
const ORGANIZER_SCREENS = {
  home: `${ORG}?view=home`,
  events: `${ORG}?view=events`,
  applications: `${ORG}?view=applications`,
  vendors: `${ORG}?view=vendors`,
  lists: `${ORG}?view=lists`,
  invites: `${ORG}?view=invites`,
  money: `${ORG}?view=financial`,
  operations: `${ORG}?view=operations`,
  marketing: `${ORG}?view=marketing`,
  intelligence: `${ORG}?view=intelligence`,
  tours: `${ORG}?view=tours`,
  team: `${ORG}?view=team`,
  profile: `${ORG}?view=profile`,
  settings: `${ORG}?view=settings`,
  start: `${ORG}?view=start`,
  create: `${ORG}?view=create`,
  help: `${ORG}?view=help`,
  'event-overview': `${ORG}?event={event}`,
  'event-inventory': `${ORG}?event={event}&tab=inventory`,
  'event-applications': `${ORG}?event={event}&tab=applications`,
  'event-money': `${ORG}?event={event}&tab=money`,
  'event-ops': `${ORG}?event={event}&ops=1`,
  'event-roster': `${ORG}?event={event}&roster=1`,
  'event-checkin': `${ORG}?event={event}&scan=1`,
};

const SCREENS = role === 'organizer' ? ORGANIZER_SCREENS : VENDOR_SCREENS;
const pick = (positional[2] || Object.keys(SCREENS).join(',')).split(',');

/**
 * Wait until skeleton placeholders are gone or a short ceiling passes.
 * @param {import('@playwright/test').Page} page
 */
async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 20000 }).catch(() => {});
  await page
    .waitForFunction(
      () => !Array.from(document.querySelectorAll('[class*="skeleton"]')).some((el) => el.offsetParent !== null),
      null,
      { timeout: 12000 },
    )
    .catch(() => {});
  await page.waitForTimeout(400);
}

/**
 * Close onboarding dialogs that cover the dashboard.
 * @param {import('@playwright/test').Page} page
 */
async function dismissOverlays(page) {
  for (const name of [/skip tour/i, /^not now$/i, /^close$/i]) {
    const btn = page.getByRole('button', { name }).filter({ visible: true }).first();
    if (await btn.count()) {
      await btn.click({ timeout: 1000 }).catch(() => {});
    }
  }
}

/**
 * First event ID linked from the organizer Events view, for workspace routes.
 * @param {import('@playwright/test').Page} page
 */
async function firstEventId(page) {
  if (process.env.ORG_EVENT_ID) return process.env.ORG_EVENT_ID;
  await page.goto(`${base}${ORG}?view=events`, { waitUntil: 'domcontentloaded' });
  return page.evaluate(() => {
    const link = Array.from(document.querySelectorAll('a[href*="event="]'))
      .map((a) => new URL(a.href, location.href).searchParams.get('event'))
      .find((id) => id && /^\d+$/.test(id));
    return link || '';
  });
}

const browser = await chromium.launch();
const page = await browser.newPage();
await page.goto(`${base}/wp-login.php`);
await page.fill('#user_login', user);
await page.fill('#user_pass', pass);
await page.click('#wp-submit');
await page.waitForURL((u) => !String(u).includes('wp-login'), { timeout: 20000 });
fs.mkdirSync(outDir, { recursive: true });

const eventId = role === 'organizer' ? await firstEventId(page) : '';

const rest = {};
let restLog = null;
page.on('requestfinished', async (req) => {
  if (!restLog || !req.url().includes('/wp-json/')) return;
  const timing = req.timing();
  restLog.push({ url: req.url().replace(base, ''), ms: Math.round(timing.responseEnd) });
});

const overflowFile = path.join(outDir, 'overflow.json');
const overflow = fs.existsSync(overflowFile) ? JSON.parse(fs.readFileSync(overflowFile, 'utf8')) : {};
for (const key of pick) {
  let url = SCREENS[key];
  if (!url) continue;
  if (url.includes('{event}')) {
    if (!eventId) continue;
    url = url.replace('{event}', eventId);
  }
  for (const width of widths) {
    await page.setViewportSize({ width, height: width < 800 ? 2000 : 1500 });
    restLog = [];
    const started = Date.now();
    await page.goto(base + url, { waitUntil: 'domcontentloaded' });
    await settle(page);
    await dismissOverlays(page);
    if (!rest[key]) {
      rest[key] = { url, settledMs: Date.now() - started, requests: restLog.length, calls: restLog };
    }
    restLog = null;
    const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    overflow[`${key}@${width}`] = over;
    await page.screenshot({ path: path.join(outDir, `${key}-${width}.png`), fullPage: true });
  }
}
fs.writeFileSync(overflowFile, JSON.stringify(overflow, null, 2));
fs.writeFileSync(path.join(outDir, 'rest.json'), JSON.stringify({ eventId, screens: rest }, null, 2));
await browser.close();
console.log(JSON.stringify(overflow));
