import { test, expect, type Page, type BrowserContext, type APIRequestContext } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

/**
 * Public Website 5.0: homepage, navigation, search, acquisition landings, campaign variants.
 *
 * Prerequisite (adds the landing pages and two E2E campaign variants; touches no users or payments):
 *   wp eval-file wp-content/lnkup-e2e/php/public-website-setup.php
 */

interface PublicPricing {
  organizer_commission_percent: number;
  example: { vendor_total: number; organizer_payout: number };
}

interface DiscoveryCity {
  city: string;
  state: string;
  label: string;
}

/** Replace Meta's script with an empty stub so no browser event can reach Meta. */
async function blockMeta(context: BrowserContext): Promise<void> {
  await context.route('**/connect.facebook.net/**', (route) =>
    route.fulfill({ status: 200, contentType: 'application/javascript', body: '/* stub */' })
  );
}

/** Behave like a returning iPhone visitor who already closed the first-visit app sheet. */
async function dismissAppSheet(context: BrowserContext): Promise<void> {
  await context.addInitScript(() => {
    window.localStorage.setItem('lnkup_app_download_sheet_at', String(Date.now()));
  });
}

/** Log in through wp-login.php and wait until WordPress redirects away from it. */
async function loginAs(page: Page, user: string, password: string): Promise<void> {
  await page.goto('/wp-login.php');
  await page.getByLabel('Username or Email Address').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(password);
  await page.getByRole('button', { name: 'Log In' }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/wp-login.php'));
}

/** Live discovery payload from the public REST endpoint. */
async function discovery(request: APIRequestContext): Promise<{ pricing: PublicPricing; city_index: DiscoveryCity[] }> {
  const response = await request.get('/wp-json/lnkup/v1/public/discovery');
  expect(response.ok(), 'discovery endpoint responds').toBeTruthy();
  return response.json();
}

/** Serious and critical axe violations on the current page, as readable strings. */
async function seriousA11yViolations(page: Page): Promise<string[]> {
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
  return results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id}: ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(' | ')}`);
}

/** Price label as rendered by the theme (6 → "6%", 6.5 → "6.5%"). */
function percentLabel(value: number): string {
  const rounded = Math.round(value * 100) / 100;
  return Number.isInteger(rounded) ? `${rounded}%` : `${Math.round(rounded * 10) / 10}%`;
}

test.describe('Public website 5.0', () => {
  test.beforeEach(async ({ context }) => {
    await blockMeta(context);
    await dismissAppSheet(context);
  });

  test('homepage hero search builds a shareable Find Events URL', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    const search = page.getByTestId('lnkup-hero-search');
    await search.getByLabel('Event or keyword').fill('festival');
    await search.getByLabel('When').selectOption('weekend');
    await search.getByRole('button', { name: 'Search events' }).click();
    await expect(page).toHaveURL(/\/opportunities\/\?q=festival&date_preset=weekend$/);
  });

  test('city autocomplete fills city and state from live listings', async ({ page, request }) => {
    const { city_index: cities } = await discovery(request);
    test.skip(cities.length === 0, 'no open events with a city');
    const target = cities.find((c) => c.state) ?? cities[0];
    await page.goto('/');
    const search = page.getByTestId('lnkup-hero-search');
    const cityInput = search.getByRole('combobox', { name: 'City' });
    await cityInput.fill(target.city.slice(0, 3));
    const option = search.getByRole('option', { name: target.label });
    await expect(option).toBeVisible();
    await cityInput.press('ArrowDown');
    await option.click();
    await expect(cityInput).toHaveValue(target.city);
    await search.getByRole('button', { name: 'Search events' }).click();
    await expect(page).toHaveURL(new RegExp(`city=${encodeURIComponent(target.city).replace(/%20/g, '\\+')}`));
  });

  test('search mode tabs switch with the keyboard', async ({ page }) => {
    await page.goto('/');
    const search = page.getByTestId('lnkup-hero-search');
    const eventsTab = search.getByRole('tab', { name: 'Events for vendors' });
    await eventsTab.focus();
    await page.keyboard.press('ArrowRight');
    await expect(search.getByRole('tab', { name: 'Vendors for events' })).toHaveAttribute('aria-selected', 'true');
    await expect(search.getByRole('button', { name: 'Find vendors' })).toBeVisible();
    await expect(search.getByRole('button', { name: 'Search events' })).toBeHidden();
  });

  test('event cards link to real listings with open spots', async ({ page }) => {
    await page.goto('/');
    const cards = page.getByTestId('lnkup-featured-card');
    test.skip((await cards.count()) === 0, 'no open events');
    const first = cards.first();
    await expect(first.getByRole('heading')).toBeVisible();
    await expect(first.getByText(/\d+ spots? open/)).toBeVisible();
    await expect(first.getByRole('link').first()).toHaveAttribute('href', /^https?:\/\//);
  });

  test('desktop dropdown opens, closes with Escape and returns focus', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    const nav = page.getByRole('navigation', { name: 'Primary' });
    const trigger = nav.getByRole('button', { name: /for organizers/i });
    await trigger.click();
    await expect(trigger).toHaveAttribute('aria-expanded', 'true');
    await expect(nav.getByRole('link', { name: /how lnkup works for organizers/i })).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  });

  test('Get started menu offers vendor and organizer sign-up', async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.goto('/');
    await page.getByTestId('lnkup-header-signup').click();
    await expect(page.getByTestId('lp-role-vendor')).toHaveAttribute('href', /sign-up\/?\?role=vendor/);
    await expect(page.getByTestId('lp-role-organizer')).toHaveAttribute('href', /sign-up\/?\?role=organizer/);
  });

  test('mobile drawer traps focus and closes with Escape', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');
    const opener = page.getByRole('button', { name: 'Open menu' });
    await opener.click();
    const drawer = page.getByRole('dialog', { name: 'Menu' });
    await expect(drawer).toBeVisible();
    await expect(page.locator('body')).toHaveClass(/lp-scroll-lock/);
    for (let i = 0; i < 25; i += 1) {
      await page.keyboard.press('Tab');
    }
    await expect.poll(() => drawer.evaluate((el) => el.contains(document.activeElement))).toBe(true);
    await page.keyboard.press('Escape');
    await expect(drawer).toBeHidden();
    await expect(opener).toBeFocused();
    await expect(page.locator('body')).not.toHaveClass(/lp-scroll-lock/);
  });

  test('no horizontal overflow at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 720 });
    for (const path of ['/', '/for-organizers/', '/for-vendors/']) {
      await page.goto(path);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
      expect(overflow, `${path} overflow`).toBeLessThanOrEqual(1);
    }
  });

  test('organizer landing shows Fee Engine pricing and a working calculator', async ({ page, request }) => {
    const { pricing } = await discovery(request);
    await page.goto('/for-organizers/');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    const pricingSection = page.getByTestId('lp-org-pricing');
    await expect(pricingSection.getByText(percentLabel(pricing.organizer_commission_percent)).first()).toBeVisible();
    await expect(page.getByTestId('lp-org-example-payout')).toHaveText(`$${pricing.example.organizer_payout.toLocaleString('en-US', { minimumFractionDigits: Number.isInteger(pricing.example.organizer_payout) ? 0 : 2 })}`);

    const calc = page.getByTestId('lp-roi-calculator');
    const payout = calc.locator('[data-out="net"]');
    const before = await payout.innerText();
    await calc.getByRole('slider', { name: /paid vendor booths/i }).fill('100');
    await expect(payout).not.toHaveText(before);
    await expect(page.getByTestId('lp-org-walkthrough')).toHaveCount(0);
  });

  test('vendor landing narrows only to live cities and ignores unsafe input', async ({ page, request }) => {
    const { city_index: cities } = await discovery(request);
    await page.goto('/for-vendors/?city=%3Cscript%3Ewindow.__lp_xss%3D1%3C%2Fscript%3E&category=%22%3E%3Cimg%20src%3Dx%3E');
    const landing = page.getByTestId('lp-vendor-landing');
    await expect(landing).toHaveAttribute('data-city', '');
    await expect(landing).toHaveAttribute('data-category', '');
    expect(await page.evaluate(() => (window as unknown as { __lp_xss?: number }).__lp_xss)).toBeUndefined();

    test.skip(cities.length === 0, 'no open events with a city');
    await page.goto(`/for-vendors/?city=${encodeURIComponent(cities[0].city)}`);
    await expect(landing).toHaveAttribute('data-city', cities[0].city);
    await expect(page.getByTestId('lp-vendor-hero')).toContainText(cities[0].label);
  });

  test('vendor landing returns visitors to the opportunity they came from', async ({ page, request }) => {
    const response = await request.get('/wp-json/lnkup/v1/public/discovery/cards?set=upcoming&limit=1');
    const { items } = (await response.json()) as { items: Array<{ opportunity_id: number; url: string }> };
    test.skip(items.length === 0, 'no open events');
    await page.goto(`/for-vendors/?opportunity=${items[0].opportunity_id}`);
    const primary = page.getByTestId('lp-vendor-cta-primary');
    await expect(primary).toContainText(/sign up to apply/i);
    await expect(primary).toHaveAttribute('href', /role=vendor&redirect=/);
    await expect(page.getByTestId('lp-vendor-cta-return')).toHaveAttribute('href', items[0].url);
  });

  test('campaign variant uses its copy, stays out of search and keeps attribution', async ({ page, context }) => {
    await page.goto('/for-vendors/e2e-food-trucks/?utm_source=e2e&utm_medium=test&utm_campaign=lp5_e2e');
    await expect(page.getByRole('heading', { level: 1, name: 'Book more food truck events' })).toBeVisible();
    await expect(page.getByTestId('lp-vendor-landing')).toHaveAttribute('data-variant', 'e2e-food-trucks');
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);
    await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/for-vendors\/$/);
    await expect.poll(async () => (await context.cookies()).find((c) => c.name === 'lnkup_ft')?.value ?? '').toContain('lp5_e2e');
  });

  test('disabled or unknown variants fall back to the base landing', async ({ page }) => {
    await page.goto('/for-organizers/e2e-off/?utm_source=e2e');
    await expect(page).toHaveURL(/\/for-organizers\/\?utm_source=e2e$/);
    await page.goto('/for-vendors/not-a-real-variant/');
    await expect(page).toHaveURL(/\/for-vendors\/$/);
  });

  test('landing views tag ViewContent for the Pixel when it is active', async ({ page }) => {
    await page.goto('/for-vendors/e2e-food-trucks/');
    const meta = await page.evaluate(() => (window as unknown as { lnkupMeta?: { viewContent?: Record<string, string> } }).lnkupMeta ?? null);
    test.skip(meta === null, 'Meta Pixel is not active on this site');
    expect(meta?.viewContent).toMatchObject({ content_category: 'vendor_landing', content_name: 'e2e-food-trucks', marketplace_role: 'vendor' });
  });

  test('acquisition landings keep app prompts away from the sign-up call to action', async ({ browser }) => {
    const context = await browser.newContext({ userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/22B83 Instagram 356.0', viewport: { width: 390, height: 844 } });
    await blockMeta(context);
    const page = await context.newPage();
    await page.goto('/for-vendors/?utm_source=instagram');
    await expect(page.getByTestId('lp-vendor-cta-primary')).toBeInViewport();
    await expect(page.getByTestId('lnkup-app-download-bar')).toHaveCount(0);
    await expect(page.getByTestId('lnkup-app-download-sheet')).toHaveCount(0);
    await context.close();
  });

  test('sign-up keeps the vendor role and the opportunity to return to', async ({ page, request }) => {
    const response = await request.get('/wp-json/lnkup/v1/public/discovery/cards?set=upcoming&limit=1');
    const { items } = (await response.json()) as { items: Array<{ opportunity_id: number; url: string }> };
    test.skip(items.length === 0, 'no open events');
    await page.goto(`/for-vendors/?opportunity=${items[0].opportunity_id}`);
    await page.getByTestId('lp-vendor-cta-primary').click();
    await expect(page).toHaveURL(/\/sign-up\/\?role=vendor&redirect=/);
    await expect(page.locator('input[name="lnkup_role"][value="lnkup_vendor"]')).toBeChecked();
    await expect(page.locator('input[name="lnkup_redirect"]').first()).toHaveValue(new RegExp(new URL(items[0].url).pathname.replace(/\//g, '\\/')));
  });

  for (const role of ['vendor', 'organizer'] as const) {
    test(`signed-in ${role} sees a dashboard link and next steps`, async ({ page }) => {
      await loginAs(page, `lp5-${role}`, 'Lp5-e2e-pass!');
      await page.goto('/');
      await expect(page.getByTestId('lp-welcome-back')).toBeVisible();
      await expect(page.getByTestId('lnkup-header-signup')).toHaveCount(0);
      const nextStep = role === 'organizer' ? /create an event/i : /my applications/i;
      await expect(page.getByTestId('lp-welcome-back').getByRole('link', { name: nextStep })).toBeVisible();
    });
  }

  test('a landing draft can be previewed by editors without going live', async ({ page, browser }) => {
    test.setTimeout(120_000);
    const draftTitle = `Draft headline ${Date.now()}`;
    await loginAs(page, process.env.LP5_ADMIN_USER || 'admin', process.env.LP5_ADMIN_PASS || 'password');
    await page.goto('/wp-admin/themes.php?page=lnkup-landing');
    await page.getByLabel('Headline').first().fill(draftTitle);
    await page.getByRole('button', { name: 'Save draft' }).first().click();
    await expect(page.getByText(/draft saved/i)).toBeVisible();
    const preview = page.getByRole('link', { name: 'Preview Homepage' });
    const previewUrl = await preview.getAttribute('href');
    await page.goto(previewUrl ?? '/');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(draftTitle);
    await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/);

    const visitor = await browser.newPage();
    await visitor.goto('/');
    await expect(visitor.getByRole('heading', { level: 1 })).not.toHaveText(draftTitle);
    await visitor.close();

    await page.goto('/wp-admin/themes.php?page=lnkup-landing');
    await page.getByRole('button', { name: 'Discard draft' }).first().press('Enter');
    await expect(page.getByText(/draft discarded/i)).toBeVisible();
  });

  test('reduced motion shows every section without animation', async ({ page }) => {
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/');
    const hidden = await page.locator('.lp-reveal').evaluateAll((els) => els.filter((el) => getComputedStyle(el).opacity !== '1').length);
    expect(hidden).toBe(0);
  });

  test('FAQs render with FAQPage structured data', async ({ page }) => {
    await page.goto('/for-organizers/');
    const faq = page.getByTestId('lp-org-faq');
    await faq.getByText('Does LNKup sell tickets?').click();
    await expect(faq.getByText(/handles the vendor side/i)).toBeVisible();
    const schemas = await page.locator('script[type="application/ld+json"]').allTextContents();
    expect(schemas.some((s) => s.includes('"FAQPage"'))).toBeTruthy();
  });

  for (const path of ['/', '/for-organizers/', '/for-vendors/']) {
    test(`no serious accessibility violations on ${path}`, async ({ page }) => {
      await page.goto(path);
      expect(await seriousA11yViolations(page)).toEqual([]);
    });
  }
});
