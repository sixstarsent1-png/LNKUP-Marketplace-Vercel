/**
 * Walk the five design-pass journeys and save a phone screenshot at each step.
 * Usage: node scripts/design/journeys.mjs [journey,journey]
 * Output: docs/design-pass/journeys/<journey>-<step>.jpg and a PASS/FAIL line per step.
 */
import { mkdirSync } from 'node:fs';
import { chromium, devices } from '@playwright/test';

const WP = process.env.DESIGN_WP_URL || 'http://localhost:8896';
const EXPO = process.env.DESIGN_EXPO_URL || 'http://localhost:8082';
const PASSWORD = 'lnkup-e2e-Passw0rd!';
const OUT = 'docs/design-pass/journeys';
const only = process.argv[2] ? process.argv[2].split(',') : null;
mkdirSync(OUT, { recursive: true });

const results = [];

/**
 * Record a step: run the check, then save a screenshot whatever the outcome.
 */
async function step(page, journey, name, check) {
  let ok = true;
  let note = '';
  try {
    note = (await check()) || '';
  } catch (error) {
    ok = false;
    note = error.message.split('\n')[0];
  }
  await page.waitForTimeout(800);
  await page.screenshot({ path: `${OUT}/${journey}-${name}.jpg`, type: 'jpeg', quality: 70 }).catch(() => {});
  results.push(`${ok ? 'PASS' : 'FAIL'} ${journey} / ${name}${note ? ` — ${note}` : ''}`);
  console.log(results[results.length - 1]);
  return ok;
}

/**
 * Sign in to WordPress through wp-login.php.
 */
async function wpLogin(page, user) {
  await page.goto(`${WP}/wp-login.php`);
  await page.getByLabel(/username or email/i).fill(user);
  await page.getByLabel(/^password$/i).fill(PASSWORD);
  await page.getByRole('button', { name: /log in/i }).click();
  await page.waitForURL((url) => !url.pathname.includes('wp-login'), { timeout: 60000 });
}

const browser = await chromium.launch();
const { defaultBrowserType: _ignored, ...phone } = devices['iPhone 13'];

async function newPage() {
  const context = await browser.newContext({ ...phone });
  return context.newPage();
}

const journeys = {
  /** Homepage, then a city, then an opportunity, then apply. */
  async discover() {
    const page = await newPage();
    await step(page, 'discover', '1-home', async () => {
      await page.goto(`${WP}/`, { waitUntil: 'load' });
      await page.getByTestId('lnkup-home-cities').scrollIntoViewIfNeeded();
    });
    await step(page, 'discover', '2-city', async () => {
      await page.getByTestId('lnkup-home-cities').getByRole('link').first().click();
      await page.waitForLoadState('load');
      await page.getByRole('link', { name: /^details: /i }).first().waitFor({ timeout: 20000 });
      return new URL(page.url()).pathname;
    });
    await step(page, 'discover', '3-event', async () => {
      await page.getByRole('link', { name: /^details: /i }).first().click();
      await page.waitForLoadState('load');
      await page.getByRole('heading', { level: 1 }).waitFor();
      return await page.getByRole('heading', { level: 1 }).innerText();
    });
    await step(page, 'discover', '4-apply', async () => {
      const apply = page.getByRole('button', { name: /^apply|choose a spot/i }).or(page.getByRole('link', { name: /^apply|log in to apply|sign up to apply/i })).first();
      await apply.click();
      await page.waitForTimeout(2000);
      const prompt = page.getByText(/create a free vendor profile to apply|log in to apply/i).first();
      await prompt.waitFor({ timeout: 15000 });
      return `guest sees: ${(await prompt.innerText()).slice(0, 40)}`;
    });
    await page.context().close();
  },

  /** Expo Explore, then an event, then back with filters and position kept. */
  async expo() {
    const page = await newPage();
    await page.goto(`${EXPO}/login`, { waitUntil: 'load' });
    await page.getByPlaceholder(/username/i).fill('e2e_vendor_a');
    await page.getByPlaceholder(/password/i).fill(PASSWORD);
    await page.getByRole('button', { name: /^sign in$/i }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 30000 });
    let before = '';
    let beforeScroll = 0;
    const scrollTop = async () => {
      const rows = await page.evaluate(() =>
        [...document.querySelectorAll('div')]
          .filter((el) => el.scrollTop > 0)
          .map((el) => ({ top: Math.round(el.scrollTop), height: el.scrollHeight, visible: el.getBoundingClientRect().width > 0 })),
      );
      if (process.env.DEBUG_SCROLL) console.log(JSON.stringify(rows));
      return Math.max(0, ...rows.filter((r) => r.visible).map((r) => r.top));
    };
    await step(page, 'expo', '1-explore-filtered', async () => {
      await page.goto(`${EXPO}/find`, { waitUntil: 'load' });
      const count = page.getByText(/\d+ opportunities$/).first();
      await count.waitFor({ timeout: 30000 });
      const unfiltered = await count.innerText();
      await page.getByText('Food Vendor', { exact: true }).first().click();
      await page.waitForFunction(
        (text) => [...document.querySelectorAll('div')].some((el) => /^\d+ opportunities$/.test(el.textContent.trim()) && el.textContent.trim() !== text),
        unfiltered,
        { timeout: 20000 },
      );
      before = await count.innerText();
      await page.mouse.move(200, 500);
      await page.mouse.wheel(0, 900);
      await page.waitForTimeout(1500);
      beforeScroll = await scrollTop();
      await page.evaluate(() => {
        const el = [...document.querySelectorAll('div')].find((d) => d.scrollTop > 0);
        if (el) el.dataset.journeyMark = '1';
      });
      return `${unfiltered} -> ${before}, scrolled ${beforeScroll}px`;
    });
    let title = '';
    await step(page, 'expo', '2-event', async () => {
      const details = page.getByText('Details', { exact: true });
      const count = await details.count();
      for (let i = 0; i < count; i += 1) {
        if (await details.nth(i).isVisible()) {
          await details.nth(i).click();
          break;
        }
      }
      await page.getByText('Event details').first().waitFor({ timeout: 20000 });
      if (process.env.DEBUG_SCROLL) {
        await page.waitForTimeout(1500);
        console.log(`while on detail: ${await page.evaluate(() => {
          const el = document.querySelector('[data-journey-mark]');
          if (!el) return 'marked list gone';
          const r = el.getBoundingClientRect();
          return JSON.stringify({ top: el.scrollTop, h: Math.round(r.height), display: getComputedStyle(el).display, hiddenAncestor: Boolean(el.closest('[aria-hidden="true"], [style*="display: none"]')) });
        })}`);
      }
      title = new URL(page.url()).pathname;
      return title;
    });
    await step(page, 'expo', '3-back', async () => {
      const searches = [];
      page.on('request', (req) => {
        if (/marketplace|opportunities/.test(req.url()) && req.resourceType() === 'fetch') searches.push(req.url().replace(/^.*\/wp-json/, ''));
      });
      await page.goBack();
      if (process.env.DEBUG_SCROLL) setTimeout(() => console.log(`requests after back: ${JSON.stringify(searches)}`), 3000);
      await page.getByText(/opportunities$/).first().waitFor({ timeout: 20000 });
      await page.waitForTimeout(2000);
      const after = await page.getByText(/\d+ opportunities$/).first().innerText();
      const afterScroll = await scrollTop();
      const sameNode = await page.evaluate(() => Boolean(document.querySelector('[data-journey-mark]')));
      if (process.env.DEBUG_SCROLL) console.log(`same scroll node after back: ${sameNode}`);
      if (after !== before) throw new Error(`result count changed: ${before} -> ${after}`);
      if (Math.abs(afterScroll - beforeScroll) > 120) throw new Error(`scroll moved ${beforeScroll} -> ${afterScroll}`);
      return `filter kept (${after}), scroll ${beforeScroll} -> ${afterScroll}px`;
    });
    await page.context().close();
  },

  /** Vendor Home, then an action, then the booking or pass. */
  async vendor() {
    const page = await newPage();
    await wpLogin(page, 'e2e_vendor_a');
    await step(page, 'vendor', '1-home', async () => {
      await page.goto(`${WP}/vendor-dashboard/`, { waitUntil: 'load' });
      await page.getByRole('heading', { level: 1 }).waitFor();
    });
    await step(page, 'vendor', '2-action', async () => {
      const action = page.locator('a.lnkup-home-next').first();
      const label = (await action.innerText()).replace(/\s+/g, ' ').slice(0, 60);
      await action.click();
      await page.waitForLoadState('load');
      return label;
    });
    await step(page, 'vendor', '3-booking', async () => {
      await page.getByRole('heading', { level: 1 }).waitFor();
      return `${new URL(page.url()).search} · ${await page.getByRole('heading', { level: 1 }).innerText()}`;
    });
    await page.context().close();
  },

  /** Organizer Home, then an event, then the queue, then an applicant and the decision. */
  async organizer() {
    const page = await newPage();
    await wpLogin(page, 'e2e_org_a');
    await step(page, 'organizer', '1-home', async () => {
      await page.goto(`${WP}/organizer-dashboard/`, { waitUntil: 'load' });
      await page.getByRole('heading', { level: 1 }).waitFor();
    });
    await step(page, 'organizer', '2-event', async () => {
      await page.locator('.lnkup-overview-v2__event').getByRole('link').first().click();
      await page.waitForLoadState('load');
      return await page.getByRole('heading', { level: 1 }).innerText();
    });
    await step(page, 'organizer', '3-queue', async () => {
      await page.goto(`${WP}/organizer-dashboard/?view=applications`, { waitUntil: 'load' });
      await page.locator('.lnkup-review__open').first().waitFor({ timeout: 20000 });
      return `${await page.locator('.lnkup-review__open').count()} applicants listed`;
    });
    await step(page, 'organizer', '4-applicant', async () => {
      await page.locator('.lnkup-review__open').first().click();
      const accept = page.getByRole('button', { name: /^(accept|approve)/i }).first();
      await accept.waitFor({ timeout: 15000 });
      const decline = page.getByRole('button', { name: /^decline/i }).first();
      return `decision buttons: ${await accept.isVisible() ? 'accept' : ''} ${await decline.isVisible() ? 'decline' : ''}`.trim();
    });
    await page.context().close();
  },

  /** Money and wallet: paid history, available funds, confirmed zero and missing data. */
  async money() {
    const vendor = await newPage();
    await wpLogin(vendor, 'e2e_vendor_a');
    await step(vendor, 'money', '1-vendor-wallet', async () => {
      await vendor.goto(`${WP}/vendor-dashboard/?view=payments`, { waitUntil: 'load' });
      await vendor.waitForTimeout(4000);
      const text = await vendor.locator('body').innerText();
      if (/NaN|undefined|\$-/.test(text)) throw new Error('broken amount text');
      return `unavailable shown ${(text.match(/Unavailable/g) || []).length}x`;
    });
    await vendor.context().close();
    const org = await newPage();
    await wpLogin(org, 'e2e_org_a');
    await step(org, 'money', '2-organizer-financial', async () => {
      await org.goto(`${WP}/organizer-dashboard/?view=financial`, { waitUntil: 'load' });
      await org.waitForTimeout(5000);
      const text = await org.locator('body').innerText();
      if (/NaN|undefined|\$-|Stripe Connect|Settlement:/.test(text)) throw new Error('broken amount or processor jargon');
      return 'no jargon, no broken amounts';
    });
    await org.context().close();
  },
};

for (const [name, run] of Object.entries(journeys)) {
  if (only && !only.includes(name)) continue;
  try {
    await run();
  } catch (error) {
    results.push(`FAIL ${name} — ${error.message.split('\n')[0]}`);
    console.log(results[results.length - 1]);
  }
}
await browser.close();
console.log(`\n${results.filter((r) => r.startsWith('PASS')).length}/${results.length} steps passed`);
