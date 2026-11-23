/**
 * Width and basic accessibility audit for the WordPress screens touched by the design pass.
 * Usage: node scripts/design/widths.mjs [width,width]
 * Reports horizontal overflow, unnamed controls, images without alt, h1 count and small tap targets.
 */
import { chromium } from '@playwright/test';

const WP = process.env.DESIGN_WP_URL || 'http://localhost:8896';
const PASSWORD = 'lnkup-e2e-Passw0rd!';
const widths = (process.argv[2] || '360,390,768,1440').split(',').map(Number);

const PAGES = [
  { id: 'home', url: `${WP}/` },
  { id: 'explore', url: `${WP}/opportunities/` },
  { id: 'event-flyer', url: `${WP}/?p=105` },
  { id: 'event-sold-out', url: `${WP}/?p=116` },
  { id: 'tour', url: `${WP}/tours/e2e-southern-tour/` },
  { id: 'sign-up', url: `${WP}/sign-up/` },
  { id: 'vendor-home', url: `${WP}/vendor-dashboard/`, as: 'e2e_vendor_a' },
  { id: 'vendor-bookings', url: `${WP}/vendor-dashboard/?view=bookings`, as: 'e2e_vendor_a' },
  { id: 'vendor-wallet', url: `${WP}/vendor-dashboard/?view=payments`, as: 'e2e_vendor_a' },
  { id: 'vendor-documents', url: `${WP}/vendor-dashboard/?view=documents`, as: 'e2e_vendor_a' },
  { id: 'organizer-home', url: `${WP}/organizer-dashboard/`, as: 'e2e_org_a' },
  { id: 'organizer-applications', url: `${WP}/organizer-dashboard/?view=applications`, as: 'e2e_org_a' },
];

/**
 * Sign in to WordPress through wp-login.php.
 */
async function wpLogin(page, user) {
  await page.goto(`${WP}/wp-login.php`);
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill(PASSWORD);
  await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: /log in/i }).click()]);
  await page.waitForURL((url) => !url.pathname.includes('wp-login'), { timeout: 60000 });
}

/**
 * Collect layout and accessibility findings from the current page.
 */
function audit() {
  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
  };
  const label = (el) => `${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''}${typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''}`;
  const vw = document.documentElement.clientWidth;
  const overflow = [];
  if (document.documentElement.scrollWidth > vw + 1) {
    for (const el of document.body.querySelectorAll('*')) {
      if (!visible(el)) continue;
      const r = el.getBoundingClientRect();
      if (r.right > vw + 1 && !el.closest('[style*="overflow"], .lnkup-x-scroll, [data-scroll-row]')) {
        let p = el.parentElement;
        let clipped = false;
        while (p && p !== document.body) {
          const o = getComputedStyle(p).overflowX;
          if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') { clipped = true; break; }
          p = p.parentElement;
        }
        if (!clipped) overflow.push(`${label(el)} right=${Math.round(r.right)}`);
      }
      if (overflow.length >= 5) break;
    }
  }
  const unnamed = [];
  for (const el of document.querySelectorAll('a[href], button, input:not([type=hidden]), select, textarea, [role=button]')) {
    if (!visible(el)) continue;
    const name = (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title') || el.textContent || el.getAttribute('placeholder') || el.getAttribute('value') || '').trim();
    const labelled = el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`);
    const wrapped = el.closest('label');
    const imgAlt = el.querySelector('img[alt]:not([alt=""]), svg[aria-label]');
    if (!name && !labelled && !wrapped && !imgAlt) unnamed.push(label(el));
  }
  const noAlt = [...document.querySelectorAll('img:not([alt])')].filter(visible).map(label);
  const h1 = [...document.querySelectorAll('h1')].filter(visible).length;
  const small = [];
  for (const el of document.querySelectorAll('a[href], button, [role=button], select, input[type=checkbox], input[type=radio]')) {
    if (!visible(el)) continue;
    const r = el.getBoundingClientRect();
    const inline = getComputedStyle(el).display === 'inline' && el.closest('p, li, td');
    if (!inline && (r.width < 24 || r.height < 24)) small.push(`${label(el)} ${Math.round(r.width)}x${Math.round(r.height)}`);
  }
  return { overflow, unnamed: unnamed.slice(0, 6), unnamedCount: unnamed.length, noAlt: noAlt.slice(0, 4), h1, small: small.slice(0, 6), smallCount: small.length };
}

const browser = await chromium.launch();
const contexts = new Map();
for (const width of widths) {
  for (const spec of PAGES) {
    const key = `${spec.as || 'guest'}@${width}`;
    if (!contexts.has(key)) {
      const context = await browser.newContext({ viewport: { width, height: 900 } });
      if (spec.as) await wpLogin(await context.newPage(), spec.as);
      contexts.set(key, context);
    }
    const page = await contexts.get(key).newPage();
    try {
      await page.goto(spec.url, { waitUntil: 'load', timeout: 60000 });
      await page.waitForTimeout(2500);
      const r = await page.evaluate(audit);
      const flags = [];
      if (r.overflow.length) flags.push(`OVERFLOW ${r.overflow.join(' | ')}`);
      if (r.unnamedCount) flags.push(`UNNAMED(${r.unnamedCount}) ${r.unnamed.join(' | ')}`);
      if (r.noAlt.length) flags.push(`NOALT ${r.noAlt.join(' | ')}`);
      if (r.h1 !== 1) flags.push(`H1=${r.h1}`);
      if (r.smallCount) flags.push(`SMALL(${r.smallCount}) ${r.small.join(' | ')}`);
      console.log(`${spec.id}@${width}: ${flags.length ? flags.join('  ;  ') : 'ok'}`);
    } catch (error) {
      console.log(`${spec.id}@${width}: failed ${error.message.split('\n')[0]}`);
    }
    await page.close();
  }
}
await browser.close();
