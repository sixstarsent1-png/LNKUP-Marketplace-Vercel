import { test, expect, type Page } from '@playwright/test';
import { loginAs, loginPage, lnkupRest } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizer = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerB = process.env.PLAYWRIGHT_ORGANIZER_B_USER ?? '';
const TOURS = '/organizer-dashboard/?view=tours';

/**
 * ISO date `days` from today.
 */
function isoDays(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d.toISOString().slice(0, 10);
}

/**
 * Fill one row of the "Add stops" table.
 */
async function fillStopRow(page: Page, index: number, stop: { city: string; state: string; date: string; timezone: string; venue?: string; address?: string }) {
  const form = page.getByTestId('lnkup-portfolio-add-stops');
  await form.getByRole('textbox', { name: 'City' }).nth(index).fill(stop.city);
  await form.getByRole('textbox', { name: 'State' }).nth(index).fill(stop.state);
  await form.getByLabel('Date').nth(index).fill(stop.date);
  await form.getByRole('textbox', { name: 'Venue' }).nth(index).fill(stop.venue ?? '');
  await form.getByRole('textbox', { name: 'Address' }).nth(index).fill(stop.address ?? '');
  await form.getByRole('combobox', { name: 'Time zone' }).nth(index).selectOption(stop.timezone);
}

test.describe('Organizer Tours & Series', () => {
  test.skip(!organizer || !organizerB, 'Run scripts/e2e/seed.php and seed-portfolio.php first');

  test('organizer sets up a tour, publishes only ready stops and sees it on the public page', async ({ page, browser }) => {
    test.setTimeout(180_000);
    await loginPage(page, organizer, password);
    await page.goto(TOURS);
    const workspace = page.getByTestId('lnkup-portfolio');
    await expect(workspace.getByRole('heading', { name: 'Start something new' })).toBeVisible({ timeout: 20_000 });

    const title = `E2E Tour ${Date.now()}`;
    await page.getByTestId('lnkup-portfolio-new-tour').click();
    const create = page.getByTestId('lnkup-portfolio-create');
    await create.getByLabel('Name').fill(title);
    await create.getByLabel('Year or season').fill('2027');
    await create.getByRole('button', { name: 'Save and add dates' }).click();

    await expect(page.getByRole('heading', { name: title })).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('lnkup-portfolio-tab-stops')).toBeVisible();
    await fillStopRow(page, 0, { city: 'Houston', state: 'TX', date: isoDays(90), timezone: 'America/Chicago', venue: 'BH Ranch', address: '1 Ranch Rd' });
    await fillStopRow(page, 1, { city: 'Jacksonville', state: 'FL', date: isoDays(120), timezone: 'America/New_York' });
    await page.getByRole('button', { name: 'Save stops as drafts' }).click();
    const stops = page.getByTestId('lnkup-portfolio-stops');
    await expect(stops.getByRole('row').filter({ hasText: 'Houston' })).toBeVisible({ timeout: 20_000 });
    await expect(stops.getByRole('row').filter({ hasText: 'Jacksonville' }).getByText('Venue to be confirmed')).toBeVisible();

    await page.getByRole('navigation', { name: 'Tour sections' }).getByRole('link', { name: 'Booths & questions' }).click();
    const defaults = page.getByTestId('lnkup-portfolio-defaults');
    await defaults.getByRole('textbox', { name: 'Booth type' }).first().fill('Standard booth');
    await defaults.getByRole('textbox', { name: 'Vendor category' }).first().fill('Food');
    await defaults.getByRole('spinbutton', { name: 'Price' }).first().fill('200');
    await defaults.getByRole('spinbutton', { name: 'Booths per stop' }).first().fill('10');
    await defaults.getByRole('button', { name: 'Save defaults' }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Defaults saved.' })).toBeVisible({ timeout: 30_000 });

    await page.getByRole('navigation', { name: 'Tour sections' }).getByRole('link', { name: 'Dates & stops' }).click();
    await page.getByTestId('lnkup-portfolio-publish-ready').click();
    const result = page.getByTestId('lnkup-portfolio-publish-result');
    await expect(result).toContainText('1 published · 1 not ready · 0 failed', { timeout: 90_000 });
    await expect(result).toContainText(/Jacksonville: .*venue/i);
    await expect(page.getByTestId('lnkup-portfolio-stops').getByRole('row').filter({ hasText: 'Houston' }).getByText('Live')).toBeVisible({ timeout: 20_000 });

    await page.getByRole('navigation', { name: 'Tour sections' }).getByRole('link', { name: 'Overview' }).click();
    await expect(page.getByTestId('lnkup-portfolio-tab-overview').getByText('Live stops')).toBeVisible({ timeout: 20_000 });
    await expect(page.getByTestId('lnkup-portfolio-stop-card')).toHaveCount(2);

    const publicLink = page.getByRole('link', { name: 'View public page' });
    await expect(publicLink).toBeVisible();
    const publicUrl = await publicLink.getAttribute('href');
    expect(publicUrl).toBeTruthy();
    const anon = await browser.newPage();
    await anon.goto(publicUrl as string);
    const publicPage = anon.getByTestId('lnkup-tour-public');
    await expect(publicPage.getByRole('heading', { name: title })).toBeVisible();
    await expect(publicPage.getByTestId('lnkup-tour-public-stop')).toHaveCount(1);
    await expect(publicPage.getByText('Houston, TX')).toBeVisible();
    await expect(publicPage.getByText('Jacksonville')).toHaveCount(0);
    await anon.close();

    const session = await loginAs(browser, organizer, password, TOURS);
    const tourId = Number(new URL(page.url()).searchParams.get('tour'));
    const detail = await (await lnkupRest(session, 'GET', `/portfolio/tours/${tourId}`)).json();
    const stopIds = (detail.stops as Array<{ event_id: number }>).map((s) => s.event_id);
    await lnkupRest(session, 'POST', `/portfolio/tours/${tourId}/cancel`, { event_ids: stopIds, note: 'E2E cleanup' });
    const removed = await lnkupRest(session, 'DELETE', `/portfolio/tours/${tourId}`);
    expect(removed.status()).toBe(200);
    await session.context.close();
  });

  test('another organizer cannot open someone else\'s tour', async ({ browser }) => {
    test.setTimeout(90_000);
    const owner = await loginAs(browser, organizer, password, TOURS);
    const created = await lnkupRest(owner, 'POST', '/portfolio/tours', { title: `E2E Private ${Date.now()}`, type: 'series' });
    expect(created.status()).toBe(201);
    const tour = await created.json();

    const other = await loginAs(browser, organizerB, password, '/organizer-dashboard/');
    const read = await lnkupRest(other, 'GET', `/portfolio/tours/${tour.id}`);
    expect(read.status()).toBe(404);
    const write = await lnkupRest(other, 'POST', `/portfolio/tours/${tour.id}/stops`, { stops: [{ city: 'X', state: 'GA' }] });
    expect(write.status()).toBe(404);

    expect((await lnkupRest(owner, 'DELETE', `/portfolio/tours/${tour.id}`)).status()).toBe(200);
    await owner.context.close();
    await other.context.close();
  });

  test('the Tours & Series view shows a clear message without access', async ({ page }) => {
    await loginPage(page, organizerB, password);
    await page.goto(TOURS);
    await expect(page.getByText('Tours & Series is not available on your account yet.')).toBeVisible({ timeout: 20_000 });
  });
});
