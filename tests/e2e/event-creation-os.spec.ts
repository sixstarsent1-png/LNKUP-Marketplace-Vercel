import { test, expect, type Page, type Browser } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import { loginAs, loginPage, lnkupRest } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';
const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER ?? '';
const vendorPass = process.env.PLAYWRIGHT_VENDOR_PASS ?? '';
const CREATE = '/organizer-dashboard/?view=create';
const DEMO_HOST = 'https://demo.lnkup-test.example';
const WIDTHS = [1920, 1440, 1280, 1024, 768, 430, 390, 360];

/** A future date (YYYY-MM-DD) n days from today. */
function futureDate(days: number): string {
  return new Date(Date.now() + days * 864e5).toISOString().slice(0, 10);
}

/** Builder flags for the organizer, or null when the builder is off for this site. */
async function builderFlags(browser: Browser): Promise<Record<string, boolean> | null> {
  const session = await loginAs(browser, organizerUser, organizerPass, '/organizer-dashboard/');
  const res = await lnkupRest(session, 'GET', '/organizer/builder/config');
  const flags = res.ok() ? ((await res.json()) as { flags: Record<string, boolean> }).flags : null;
  await session.context.close();
  return flags;
}

/** True when the local import mock (scripts/e2e/tmp/lnkup-import-mock.php) answers for the demo host. */
async function importMockInstalled(browser: Browser): Promise<boolean> {
  const session = await loginAs(browser, organizerUser, organizerPass, '/organizer-dashboard/');
  const res = await lnkupRest(session, 'POST', '/organizer/import/preview', { url: `${DEMO_HOST}/e/probe` });
  const ok = res.ok() && ((await res.json()) as { status?: string }).status === 'complete';
  await session.context.close();
  return ok;
}

/** Choose a city from the builder's location autocomplete. */
async function chooseCity(page: Page, query: string) {
  const city = page.getByRole('combobox', { name: 'City and state' });
  await city.fill(query);
  await page.getByRole('listbox', { name: 'City suggestions' }).getByRole('option').first().click();
}

/** Add one vendor option through the quick-add chips. */
async function addOption(page: Page, category: string, fee: string, spaces: string) {
  await page.locator('.eb-quickadd .eb-chip', { hasText: category }).click();
  await page.getByLabel('Booth fee').fill(fee);
  await page.getByLabel('Spaces available').fill(spaces);
}

/** Continue to the next builder step and wait for it. */
async function next(page: Page, step: string) {
  await page.getByTestId('eb-continue').click();
  await expect(page.getByTestId(`eb-step-${step}`)).toBeVisible();
}

/** Publish from the publish step and return the success screen links. */
async function publishAndConfirm(page: Page) {
  await page.getByTestId('eb-publish').click();
  await page.getByTestId('eb-confirm-publish').click();
  await expect(page.getByTestId('eb-success')).toBeVisible({ timeout: 60_000 });
}

test.describe('Event Creation OS 3.0', () => {
  let flags: Record<string, boolean> | null = null;
  let mock = false;

  test.beforeAll(async ({ browser }) => {
    test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
    flags = await builderFlags(browser);
    mock = flags?.event_import_v3 ? await importMockInstalled(browser) : false;
  });

  test.beforeEach(async ({ page }) => {
    test.skip(!flags, 'event_builder_v3 is off on this site');
    await loginPage(page, organizerUser, organizerPass);
  });

  test('access: guests are sent to log in and vendors cannot use the builder API', async ({ browser }) => {
    const guest = await browser.newContext();
    const gp = await guest.newPage();
    await gp.goto(CREATE);
    await expect(gp).toHaveURL(/log-?in/);
    await guest.close();
    test.skip(!vendorUser, 'no vendor fixture');
    const vendor = await loginAs(browser, vendorUser, vendorPass, '/vendor-dashboard/');
    expect((await lnkupRest(vendor, 'GET', '/organizer/builder/drafts')).status()).toBe(403);
    await vendor.context.close();
  });

  test('Journey A + X — quick create, publish, then the public listing shows place, options and the apply link', async ({ page }) => {
    const title = `Journey A ${Date.now()}`;
    await page.goto(CREATE);
    await page.locator('[data-method="quick"]').click();
    await page.getByLabel('Event name').fill(title);
    await page.locator('input[type="date"]').first().fill(futureDate(40));
    await chooseCity(page, 'Charlotte');
    await page.getByLabel('Venue name').fill('Camp North End');
    await next(page, 'options');
    await addOption(page, 'Retail', '60', '15');
    await next(page, 'publish');
    await expect(page.getByTestId('eb-checklist')).toContainText('Required');
    await publishAndConfirm(page);
    const publicUrl = await page.getByLabel('Public event page').inputValue();
    const applyUrl = await page.getByLabel('Vendor application link').inputValue();
    expect(applyUrl).toMatch(/apply=\d+/);
    await page.goto(publicUrl);
    await expect(page.getByRole('heading', { name: title })).toBeVisible();
    await expect(page.getByRole('main').getByText(/Charlotte, NC/).first()).toBeVisible();
  });

  test('Journey B — advanced create with an image, two options, questions and a payment policy', async ({ page }) => {
    await page.goto(CREATE);
    await page.locator('[data-method="single"]').click();
    await page.getByLabel('Event name').fill(`Journey B ${Date.now()}`);
    await page.locator('input[type="file"]').first().setInputFiles({ name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64') });
    await expect(page.getByText(/Cover photo added|Flyer added/)).toBeVisible({ timeout: 30_000 });
    await page.locator('input[type="date"]').first().fill(futureDate(60));
    await chooseCity(page, 'Raleigh');
    await page.getByLabel('Venue name').fill('Dix Park');
    await page.getByLabel('Description').fill('A full day of local food trucks, makers and live music in the park. Families, students and neighbors.');
    await next(page, 'options');
    await addOption(page, 'Food Trucks', '150', '6');
    await addOption(page, 'Retail', '0', '20');
    await next(page, 'applications');
    await page.locator('.eb-addmenu .eb-chip', { hasText: 'Food truck set' }).click();
    await next(page, 'payments');
    await next(page, 'publish');
    await publishAndConfirm(page);
    await expect(page.getByTestId('eb-success')).toContainText('is live');
  });

  test('Journey C — draft recovery keeps progress after the page closes', async ({ page }) => {
    const title = `Journey C ${Date.now()}`;
    await page.goto(CREATE);
    await page.locator('[data-method="single"]').click();
    await page.getByLabel('Event name').fill(title);
    await expect(page.locator('[data-eb-save][data-state="saved"]')).toBeVisible({ timeout: 15_000 });
    const draftUrl = page.url();
    await page.goto('/organizer-dashboard/?view=events');
    await page.goto(draftUrl);
    await expect(page.getByLabel('Event name')).toHaveValue(title);
    await page.goto(CREATE);
    await expect(page.getByTestId('eb-drafts')).toContainText(title);
  });

  test('Journey D — import a listing, review fields, continue in the builder', async ({ page }) => {
    test.skip(!mock, 'local import mock not installed (scripts/e2e/tmp/lnkup-import-mock.php); covered by event-import-test.php');
    await page.goto(`${CREATE}&method=import`);
    await page.getByLabel('Event link').fill(`${DEMO_HOST}/e/journey-d-${Date.now()}`);
    await page.getByTestId('eb-import-go').click();
    await expect(page.getByText('Review imported details')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByText(/Ticket prices and attendee capacity are not imported/)).toBeVisible();
    await page.getByTestId('eb-import-continue').click();
    await expect(page.getByLabel('Event name')).toHaveValue('Queen City Night Market');
    await expect(page.getByTestId('eb-sources')).toContainText('Primary');
  });

  test('Journey J — bulk import links into reviewed drafts (never published)', async ({ page }) => {
    test.skip(!mock || !flags?.event_import_bulk, 'needs event_import_bulk + local import mock; covered by event-import-bulk-test.php');
    await page.goto(`${CREATE}&method=bulk`);
    const stamp = Date.now();
    await page.getByLabel('Event links').fill(`${DEMO_HOST}/e/j1-${stamp}\n${DEMO_HOST}/e/partial-${stamp}`);
    await page.getByTestId('eb-bulk-read').click();
    await expect(page.locator('[data-testid="eb-job-apply"]:not([disabled])')).toBeVisible({ timeout: 45_000 });
    await page.getByTestId('eb-job-apply').click();
    await expect(page.locator('.eb-job__item[data-status="drafted"]').first()).toBeVisible();
  });

  test('Journey L — recurring market: preview dates, skip one, publish independent dates', async ({ page }) => {
    test.skip(!flags?.portfolio_builder_v3, 'portfolio_builder_v3 is off');
    await page.goto(`${CREATE}&method=recurring`);
    const schedule = page.getByTestId('eb-schedule');
    await expect(schedule).toBeVisible();
    await page.getByLabel('Event name').fill(`Journey L ${Date.now()}`);
    const first = schedule.locator('input[type="date"]').first();
    await first.fill(futureDate(20));
    await first.dispatchEvent('change');
    await schedule.getByRole('button', { name: 'After a number of dates' }).click();
    await page.getByLabel('Number of dates').fill('4');
    await page.getByLabel('Number of dates').dispatchEvent('change');
    await chooseCity(page, 'Durham');
    await page.getByLabel('Venue name').fill('Durham Central Park');
    await page.getByLabel('Street address').fill('501 Foster St');
    const day = page.getByTestId('eb-cal').locator('.eb-cal__day');
    await expect(day).toHaveCount(4, { timeout: 20_000 });
    await day.nth(1).click();
    await expect(page.getByTestId('eb-cal').locator('.eb-cal__day[data-skip="true"]')).toHaveCount(1, { timeout: 20_000 });
    await next(page, 'options');
    await addOption(page, 'Retail', '35', '12');
    await next(page, 'applications');
    await next(page, 'payments');
    await next(page, 'publish');
    await publishAndConfirm(page);
    await expect(page.getByTestId('eb-success')).toContainText('3 dates created');
  });

  test('Journey N — multi-city tour with a price override and one stop held back', async ({ page }) => {
    test.skip(!flags?.portfolio_builder_v3, 'portfolio_builder_v3 is off');
    await page.goto(`${CREATE}&method=tour`);
    await expect(page.getByTestId('eb-stops')).toBeVisible();
    await page.getByLabel('Event name').fill(`Journey N ${Date.now()}`);
    await page.getByRole('button', { name: 'Paste a list' }).click();
    await page.getByLabel('Stops, one per line').fill(`${futureDate(45)}, Atlanta, GA, Pullman Yards\n${futureDate(59)}, Dallas, TX, Fair Park`);
    await page.getByRole('button', { name: 'Add', exact: true }).click();
    const stops = page.getByTestId('eb-stop');
    await expect(stops).toHaveCount(2);
    await stops.nth(0).getByLabel('Street address for stop 1').fill('225 Rogers St NE');
    await stops.nth(0).getByLabel('Booth price for stop 1').fill('95');
    await stops.nth(1).getByLabel('Publish this stop now').uncheck();
    await next(page, 'options');
    await addOption(page, 'Retail', '120', '30');
    await next(page, 'applications');
    await next(page, 'payments');
    await next(page, 'publish');
    await publishAndConfirm(page);
    await expect(page.getByTestId('eb-success')).toContainText('2 dates created, 1 published');
  });

  test('Journey P — duplicate from the events list requires a new place and copies no bookings', async ({ page }) => {
    await page.goto('/organizer-dashboard/?view=events');
    const dup = page.locator('[data-duplicate-event]').first();
    test.skip((await dup.count()) === 0, 'no events to duplicate');
    await dup.dispatchEvent('click');
    await expect(page).toHaveURL(/view=create.*duplicate=\d+/);
    const dialog = page.getByRole('dialog', { name: 'Duplicate an event' });
    await expect(dialog).toContainText('Never copied');
    await dialog.getByRole('button', { name: 'Create copy' }).click();
    await expect(page.getByTestId('eb-step-info')).toBeVisible();
    await page.getByTestId('eb-step-publish-tab').or(page.getByRole('button', { name: /Preview & publish/ })).first().click();
    await expect(page.getByTestId('eb-checklist')).toContainText('Location confirmed for this copy');
  });

  test('Journey W — unsafe import links and injected text are rejected or rendered inert', async ({ page }) => {
    test.skip(!flags?.event_import_v3, 'event_import_v3 is off');
    let dialogs = 0;
    page.on('dialog', async (d) => { dialogs += 1; await d.dismiss(); });
    await page.goto(`${CREATE}&method=import`);
    for (const url of ['http://127.0.0.1/wp-admin', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/']) {
      await page.getByLabel('Event link').fill(url);
      await page.getByTestId('eb-import-go').click();
      await expect(page.getByText('We could not import that link')).toBeVisible({ timeout: 20_000 });
      await expect(page.getByText('This address cannot be imported.')).toBeVisible();
    }
    await page.goto(CREATE);
    await page.locator('[data-method="quick"]').click();
    await page.getByLabel('Event name').fill('<img src=x onerror=alert(1)> Market');
    await expect(page.locator('[data-eb-save][data-state="saved"]')).toBeVisible({ timeout: 15_000 });
    await page.reload();
    await expect(page.getByLabel('Event name')).toHaveValue(/Market/);
    expect(dialogs, 'no script from user text ever runs').toBe(0);
  });

  test('accessibility: builder screens have no serious or critical axe violations', async ({ page }) => {
    const screens: Array<[string, string]> = [[CREATE, 'eb-chooser'], [`${CREATE}&method=import`, 'eb-import']];
    if (flags?.event_import_v3) screens.push(['/organizer-dashboard/?view=imports', 'eb-imports-center']);
    for (const [url, testid] of screens) {
      await page.goto(url);
      await expect(page.getByTestId(testid)).toBeVisible();
      const results = await new AxeBuilder({ page }).include('.eb-app').analyze();
      const bad = results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
      expect(bad.map((v) => `${v.id}: ${v.nodes.length}`), `axe on ${url}`).toEqual([]);
    }
    await page.goto(CREATE);
    await page.locator('[data-method="single"]').click();
    await expect(page.getByTestId('eb-step-info')).toBeVisible();
    const builder = await new AxeBuilder({ page }).include('.eb-app').analyze();
    expect(builder.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical').map((v) => v.id), 'axe on builder').toEqual([]);
  });

  test('layout: no horizontal overflow on the chooser and builder at 8 widths', async ({ page }) => {
    await page.goto(CREATE);
    await page.locator('[data-method="single"]').click();
    await expect(page.getByTestId('eb-step-info')).toBeVisible();
    const builderUrl = page.url();
    for (const width of WIDTHS) {
      await page.setViewportSize({ width, height: width < 600 ? 844 : 1000 });
      for (const url of [CREATE, builderUrl]) {
        await page.goto(url);
        await expect(page.getByTestId(url === CREATE ? 'eb-chooser' : 'eb-step-info')).toBeVisible();
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `${url} at ${width}px`).toBeLessThanOrEqual(1);
      }
    }
  });
});
