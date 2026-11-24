import { expect, test, type APIResponse, type Browser, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

/**
 * Marketing, SEO & Distribution OS — browser legs of journeys A, B, E, G, H and J.
 *
 * Needs the seeded wp-env site (scripts/e2e/seed.php) with flags seo_index_policy,
 * market_category_pages, tracking_links and organizer_marketing on, and the Charlotte market.
 * Server-side legs (registration → application → approval → payment attribution, connector
 * credit, external claims, campaign approval, refund reconciliation, deep-link resolution) are
 * covered by the PHP suites listed in docs/marketing-distribution-os/TEST_RESULTS.md.
 */

const ORG_USER = process.env.PLAYWRIGHT_ORGANIZER_USER || '';
const ORG_PASS = process.env.PLAYWRIGHT_ORGANIZER_PASS || '';
const VENDOR_USER = process.env.PLAYWRIGHT_VENDOR_USER || '';
const VENDOR_PASS = process.env.PLAYWRIGHT_VENDOR_PASS || '';
const ADMIN_USER = process.env.PLAYWRIGHT_ADMIN_USER || '';
const ADMIN_PASS = process.env.PLAYWRIGHT_ADMIN_PASS || '';
const MARKET_PATH = process.env.PLAYWRIGHT_MARKET_PATH || '/markets/charlotte/';
const RUN = Date.now().toString(36);

test.describe.configure({ timeout: 120_000 });
test.use({ navigationTimeout: 60_000, actionTimeout: 30_000 });

/**
 * Parsed JSON-LD graph nodes on the current page.
 */
async function jsonLdTypes(page: Page): Promise<string[]> {
  const blocks = await page.locator('script[type="application/ld+json"]').allTextContents();
  const types: string[] = [];
  const visit = (node: unknown): void => {
    if (Array.isArray(node)) {
      node.forEach(visit);
      return;
    }
    if (node && typeof node === 'object') {
      const record = node as Record<string, unknown>;
      if (typeof record['@type'] === 'string') types.push(record['@type']);
      Object.values(record).forEach(visit);
    }
  };
  blocks.forEach((block) => visit(JSON.parse(block)));
  return types;
}

/**
 * Content of a meta tag by name or property ('' when absent).
 */
async function metaContent(page: Page, key: string): Promise<string> {
  const tag = page.locator(`meta[name="${key}"], meta[property="${key}"]`).first();
  return (await tag.count()) ? (await tag.getAttribute('content')) || '' : '';
}

/**
 * Open the organizer Marketing view in a fresh, signed-in context.
 */
async function openOrganizerMarketing(browser: Browser): Promise<Page> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await loginPage(page, ORG_USER, ORG_PASS);
  await page.goto('/organizer-dashboard/?view=marketing');
  await expect(page.getByTestId('lnkup-org-marketing')).toBeVisible();
  return page;
}

/**
 * Archive the organizer's tracked link with this label through the dashboard REST API.
 */
async function archiveLink(page: Page, label: string): Promise<number> {
  return page.evaluate(async (wanted) => {
    const cfg = (window as unknown as { lnkupOrgMarketing: { rest: string; nonce: string } }).lnkupOrgMarketing;
    const headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
    const list = await (await fetch(`${cfg.rest}links`, { credentials: 'same-origin', headers })).json();
    const links: Array<{ id: number; label: string }> = Array.isArray(list) ? list : list.links || [];
    const link = links.find((l) => l.label === wanted);
    if (!link) return 0;
    const res = await fetch(`${cfg.rest}links/${link.id}`, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify({ status: 'archived' }) });
    return res.status;
  }, label);
}

/**
 * Assert a response refuses access without leaking data.
 */
function expectRefused(res: APIResponse, label: string): void {
  expect([401, 403, 404], `${label} should be refused (got ${res.status()})`).toContain(res.status());
}

test.describe('Journey A — organic vendor acquisition (browser leg)', () => {
  test('market page leads to an indexable event page and keeps the campaign touch', async ({ page, context }) => {
    const res = await page.goto(`${MARKET_PATH}?utm_source=google&utm_medium=organic&utm_campaign=pw-a-${RUN}`);
    expect(res?.status()).toBe(200);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    expect(await metaContent(page, 'robots')).not.toContain('noindex');

    await expect
      .poll(async () => decodeURIComponent((await context.cookies()).find((c) => c.name === 'lnkup_lt')?.value || ''))
      .toContain(`pw-a-${RUN}`);

    const hrefs = await page.locator('main a[href*="/events/"]').evaluateAll((links) =>
      Array.from(new Set(links.map((a) => (a as HTMLAnchorElement).href))).slice(0, 4),
    );
    test.skip(hrefs.length === 0, 'Market has no open events in this environment');
    let indexable = 0;
    for (const href of hrefs) {
      await page.goto(href);
      const noindex = (await metaContent(page, 'robots')).includes('noindex');
      const types = await jsonLdTypes(page);
      if (noindex) {
        expect(types, `${href} is noindex, so it must not claim Event schema`).not.toContain('Event');
        continue;
      }
      indexable += 1;
      expect(types, `${href} is indexable, so it needs Event schema`).toContain('Event');
      const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
      expect(canonical || '').toBe(href.split('?')[0]);
    }
    expect(indexable, 'at least one indexable event page from the market').toBeGreaterThan(0);
    expect(decodeURIComponent((await context.cookies()).find((c) => c.name === 'lnkup_ft')?.value || '')).toContain('google');
  });
});

test.describe('Journeys B and E — organizer share link and social preview', () => {
  test.skip(!ORG_USER || !ORG_PASS, 'PLAYWRIGHT_ORGANIZER_USER/PASS not set');

  test('organizer creates a tracked link that opens the event with an accurate preview', async ({ browser }) => {
    const page = await openOrganizerMarketing(browser);
    const createForm = page.getByTestId('lnkup-om-create-link');
    await expect(createForm).toBeVisible();
    await createForm.getByLabel('Where will you share it?').selectOption('instagram');
    await createForm.getByLabel('Name (optional)').fill(`PW story ${RUN}`);
    await createForm.getByRole('button', { name: 'Create link' }).click();

    const row = page.getByTestId('lnkup-om-links').getByRole('listitem').filter({ hasText: `PW story ${RUN}` });
    await expect(row).toBeVisible();
    const shortUrl = ((await row.locator('code').textContent()) || '').trim();
    expect(shortUrl).toMatch(/\/l\/[A-Za-z0-9]+\/$/);

    const visitor = await browser.newContext();
    const visit = await visitor.newPage();
    await visit.goto(shortUrl);
    await expect(visit).toHaveURL(/\/events\/[a-z0-9-]+\/?/);
    expect(await metaContent(visit, 'og:title')).not.toBe('');
    expect(await metaContent(visit, 'og:image')).toMatch(/^https?:\/\//);
    expect(decodeURIComponent((await visitor.cookies()).find((c) => c.name === 'lnkup_lt')?.value || '')).toContain('tracked_link');

    const bot = await visit.request.get(shortUrl, { headers: { 'User-Agent': 'facebookexternalhit/1.1' }, maxRedirects: 0 });
    expect(bot.status()).toBe(302);
    expect(bot.headers()['location'] || '').toContain('/events/');
    expect(bot.headers()['x-robots-tag'] || '').toContain('noindex');

    await page.reload();
    await expect(page.getByTestId('lnkup-om-links').getByRole('listitem').filter({ hasText: `PW story ${RUN}` })).toContainText('1 clicks', { timeout: 30_000 });
    await visitor.close();
    expect(await archiveLink(page, `PW story ${RUN}`)).toBe(200);
    await page.context().close();
  });
});

test.describe('Journey G — campaign management (admin view)', () => {
  test.skip(!ADMIN_USER || !ADMIN_PASS, 'PLAYWRIGHT_ADMIN_USER/PASS not set');

  test('marketing command center shows campaigns, KPIs and honest integration status', async ({ page }) => {
    await loginPage(page, ADMIN_USER, ADMIN_PASS);
    await page.goto('/wp-admin/admin.php?page=lnkup-marketing');
    await expect(page.getByTestId('lnkup-mc-kpis')).toBeVisible();
    await page.goto('/wp-admin/admin.php?page=lnkup-marketing&tab=campaigns');
    await expect(page.getByRole('table').or(page.getByText('No campaigns yet.')).first()).toBeVisible();
    await page.goto('/wp-admin/admin.php?page=lnkup-marketing&tab=integrations');
    await expect(page.getByRole('row', { name: /SMS/ })).toContainText('Not configured');
    await page.goto('/wp-admin/admin.php?page=lnkup-marketing&tab=seo');
    await expect(page.getByRole('heading', { name: 'Google Search Console' })).toBeVisible();
  });
});

test.describe('Journey H — privacy protection', () => {
  test('signed-out requests to private marketing, campaign and share routes are refused', async ({ request }) => {
    expectRefused(await request.get('/wp-json/lnkup/v1/organizer/marketing/links'), 'organizer links');
    expectRefused(await request.get('/wp-json/lnkup/v1/organizer/marketing/performance'), 'organizer performance');
    expectRefused(await request.get('/wp-json/lnkup/v1/comms/marketing/segments'), 'segments');
    expectRefused(await request.get('/wp-json/lnkup/v1/comms/marketing/campaigns'), 'campaigns');
    expectRefused(await request.post('/wp-json/lnkup/v1/app/share-link', { data: { target_type: 'event', target_id: 1 } }), 'share link');
    expectRefused(await request.get('/wp-json/lnkup/v1/connector/marketing/context'), 'connector toolkit');
    expectRefused(await request.get('/wp-json/lnkup/v1/marketing/seo/diagnostics'), 'SEO diagnostics');
  });

  test('tracked link codes cannot redirect off-site', async ({ request }) => {
    const res = await request.get('/l/zzzzzzzz/?url=https://example.com', { maxRedirects: 0 });
    expect(res.status()).toBe(404);
    expect(res.headers()['location'] || '').not.toContain('example.com');
  });

  test('vendors cannot open organizer marketing data or the command center', async ({ browser }) => {
    test.skip(!VENDOR_USER || !VENDOR_PASS, 'PLAYWRIGHT_VENDOR_USER/PASS not set');
    const context = await browser.newContext();
    const page = await context.newPage();
    await loginPage(page, VENDOR_USER, VENDOR_PASS);
    const admin = await page.goto('/wp-admin/admin.php?page=lnkup-marketing');
    expect(admin?.status()).not.toBe(200);
    const nonce = await page.evaluate(async () => {
      const res = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' });
      return res.ok ? res.text() : '';
    });
    const links = await page.request.get('/wp-json/lnkup/v1/organizer/marketing/links', { headers: nonce ? { 'X-WP-Nonce': nonce } : {} });
    expectRefused(links, 'vendor reading organizer links');
    await context.close();
  });
});

test.describe('Journey J — SEO lifecycle (public output)', () => {
  test('sitemap lists event pages and no opportunity singles', async ({ request }) => {
    const index = await request.get('/wp-sitemap.xml');
    test.skip(index.status() !== 200, 'Core sitemaps disabled (Rank Math or another SEO plugin owns sitemaps)');
    const body = await index.text();
    expect(body).toContain('wp-sitemap-lnkup-events-1.xml');
    const events = await (await request.get('/wp-sitemap-lnkup-events-1.xml')).text();
    expect(events).toMatch(/<loc>[^<]+\/events\/[a-z0-9-]+\/<\/loc>/);
    expect(events).toContain('<lastmod>');
    expect(body).not.toContain('lnkup_opportunity');
  });

  test('an unknown event URL is a real 404, not a soft page', async ({ page }) => {
    const res = await page.goto(`/events/pw-missing-${RUN}/`);
    expect(res?.status()).toBe(404);
  });
});
