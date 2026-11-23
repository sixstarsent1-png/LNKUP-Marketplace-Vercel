/**
 * Capture design review screenshots of the local site at phone and desktop widths.
 *
 * Usage: node scripts/design/capture.mjs <label> [only]
 *   label  folder under docs/design-pass/ (before, after, ...)
 *   only   optional comma-separated screen ids
 *
 * Env: DESIGN_WP_URL (default http://localhost:8896), DESIGN_EXPO_URL (default http://localhost:8082),
 *      DESIGN_VENDOR_APP_PASSWORD / DESIGN_ORG_APP_PASSWORD for the Expo screens.
 */
import { chromium, devices } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const WP = process.env.DESIGN_WP_URL || 'http://localhost:8896';
const EXPO = process.env.DESIGN_EXPO_URL || 'http://localhost:8082';
const PASSWORD = 'lnkup-e2e-Passw0rd!';
const label = process.argv[2] || 'before';
const only = (process.argv[3] || '').split(',').filter(Boolean);
const outDir = join('docs', 'design-pass', label);
mkdirSync(outDir, { recursive: true });

const WIDTHS = {
  phone: { ...devices['iPhone 13'], viewport: { width: 390, height: 844 } },
  desktop: { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 },
};

/** Screens to capture. `as` is the WordPress user to sign in as, `expo` the Expo session role. */
const SCREENS = [
  { id: 'home', url: `${WP}/`, full: true, sections: 'main.lnkup-site__main > section, .lnkup-site > footer' },
  { id: 'explore', url: `${WP}/opportunities/` },
  { id: 'explore-city', url: `${WP}/opportunities/?city=Charlotte` },
  { id: 'explore-empty', url: `${WP}/opportunities/?city=Boise` },
  { id: 'explore-sheet', url: `${WP}/opportunities/`, click: '#lnkup-filter-toggle' },
  { id: 'event-flyer', url: `${WP}/lnkup_event/atlanta-greek-picnic-weekend-festival-and-vendor-village-featuring-live-djs-food-row-and-pop-up-shops/`, full: true },
  { id: 'event-no-art', url: `${WP}/lnkup_event/raleigh-sunday-flea/` },
  { id: 'tour', url: `${WP}/tours/e2e-southern-tour/`, full: true },
  { id: 'signup', url: `${WP}/sign-up/` },
  { id: 'login', url: `${WP}/log-in/` },
  { id: 'city', url: `${WP}/markets/charlotte/`, full: true },
  { id: 'organizers', url: `${WP}/organizers/` },
  { id: 'storefront', url: `${WP}/organizers/?organizer=six-stars-entertainment`, full: true },
  { id: 'vendors', url: `${WP}/vendors/` },
  { id: 'vendor-home', url: `${WP}/vendor-dashboard/`, as: 'e2e_vendor_a', full: true },
  { id: 'vendor-bookings', url: `${WP}/vendor-dashboard/?view=bookings`, as: 'e2e_vendor_a', full: true },
  { id: 'vendor-wallet', url: `${WP}/vendor-dashboard/?view=payments`, as: 'e2e_vendor_a' },
  { id: 'organizer-home', url: `${WP}/organizer-dashboard/`, as: 'e2e_org_a', full: true },
  { id: 'organizer-queue', url: `${WP}/organizer-dashboard/?view=applications`, as: 'e2e_org_a', full: true },
  { id: 'organizer-workspace', url: `${WP}/organizer-dashboard/?event=63&tab=requests`, as: 'e2e_org_a', full: true },
  { id: 'organizer-money', url: `${WP}/organizer-dashboard/?view=financial`, as: 'e2e_org_a', full: true },
  { id: 'organizer-applicant', url: `${WP}/organizer-dashboard/?event=63&tab=requests`, as: 'e2e_org_a', click: '.lnkup-crm-app-card--v2 button:has-text("View details")' },
  { id: 'vendor-booking-detail', url: `${WP}/vendor-dashboard/?view=bookings`, as: 'e2e_vendor_a', click: '[data-testid="lnkup-booking-open"]', full: true },
  { id: 'expo-explore', url: `${EXPO}/find`, expo: 'vendor' },
  { id: 'expo-organizer', url: `${EXPO}/events`, expo: 'organizer' },
];

/**
 * Sign in to WordPress through wp-login.php.
 */
async function wpLogin(page, user) {
  await page.goto(`${WP}/wp-login.php`);
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill(PASSWORD);
  await Promise.all([page.waitForLoadState('networkidle'), page.getByRole('button', { name: /log in/i }).click()]);
}

/**
 * Sign in to the Expo web preview with an application password.
 */
async function expoLogin(page, role) {
  const user = role === 'organizer' ? 'e2e_org_a' : 'e2e_vendor_a';
  await page.goto(`${EXPO}/login`);
  await page.waitForLoadState('networkidle');
  await page.getByPlaceholder(/username/i).fill(user);
  await page.getByPlaceholder(/password/i).fill(PASSWORD);
  await page.getByRole('button', { name: /^sign in$/i }).click();
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30000 }).catch(() => {});
  await page.waitForTimeout(2500);
  return !page.url().includes('/login');
}

const browser = await chromium.launch();
const results = [];
for (const [width, ctxOptions] of Object.entries(WIDTHS)) {
  const sessions = {};
  for (const screen of SCREENS) {
    if (only.length && !only.includes(screen.id)) continue;
    const key = screen.as || (screen.expo ? `expo-${screen.expo}` : 'anon');
    if (!sessions[key]) {
      const ctx = await browser.newContext({ ...ctxOptions, reducedMotion: 'reduce' });
      const page = await ctx.newPage();
      let ok = true;
      if (screen.as) await wpLogin(page, screen.as);
      if (screen.expo) ok = await expoLogin(page, screen.expo);
      sessions[key] = { ctx, page, ok };
    }
    const { page, ok } = sessions[key];
    const file = join(outDir, `${screen.id}-${width}.jpg`);
    if (!ok) {
      results.push(`${screen.id} ${width}: skipped (no app password)`);
      continue;
    }
    try {
      await page.goto(screen.url, { waitUntil: 'networkidle', timeout: 45000 });
      await page.waitForTimeout(1200);
      if (screen.click) {
        await page.locator(screen.click).first().click();
        await page.waitForTimeout(600);
      }
      await page.screenshot({ path: file, type: 'jpeg', quality: 70, fullPage: Boolean(screen.full) });
      if (screen.full) {
        await page.screenshot({ path: file.replace(/\.jpg$/, '-top.jpg'), type: 'jpeg', quality: 75 });
      }
      if (process.env.DESIGN_SECTIONS && screen.sections) {
        const sections = page.locator(screen.sections);
        const count = await sections.count();
        for (let i = 0; i < count; i++) {
          const section = sections.nth(i);
          if (!(await section.isVisible())) continue;
          await section.screenshot({ path: file.replace(/\.jpg$/, `-s${i + 1}.jpg`), type: 'jpeg', quality: 70 });
        }
      }
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      results.push(`${screen.id} ${width}: ${file}${overflow > 1 ? ` (horizontal overflow ${overflow}px)` : ''}`);
    } catch (error) {
      results.push(`${screen.id} ${width}: failed (${String(error.message).split('\n')[0]})`);
    }
  }
  for (const s of Object.values(sessions)) await s.ctx.close();
}
await browser.close();
console.log(results.join('\n'));
