/**
 * Event Creation OS 3.0 — "after" screenshots for the screens listed in the spec (§44), at
 * desktop / tablet / mobile widths, into docs/event-creation-os-v3/screenshots/after.
 *
 * Needs wp-env on :8888 with the e2e organizer (scripts/e2e/seed.php) and the Event OS flags on.
 * Import screens need the local import mock (scripts/e2e/tmp/lnkup-import-mock.php copied into
 * wp-content/mu-plugins); they are skipped and reported otherwise.
 *
 * Usage: node scripts/event-os-shots.mjs [widths=1440,768,390]
 */
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

const base = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8888';
const out = 'docs/event-creation-os-v3/screenshots/after';
const widths = (process.argv[2] || '1440,768,390').split(',').map(Number);
const user = process.env.PLAYWRIGHT_ORGANIZER_USER || 'e2e_org_a';
const pass = process.env.PLAYWRIGHT_ORGANIZER_PASS || 'lnkup-e2e-Passw0rd!';
const DEMO = 'https://demo.lnkup-test.example';
mkdirSync(out, { recursive: true });

const day = (n) => new Date(Date.now() + n * 864e5).toISOString().slice(0, 10);
const report = [];

const browser = await chromium.launch();
for (const width of widths) {
  const ctx = await browser.newContext({ viewport: { width, height: width < 600 ? 844 : width < 1000 ? 1024 : 1000 } });
  const page = await ctx.newPage();
  await page.goto(`${base}/wp-login.php`);
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

  const shot = async (name, fn, { element } = {}) => {
    try {
      await fn();
      await page.waitForTimeout(500);
      const file = `${out}/${name}-${width}.png`;
      if (element) await page.locator(element).first().screenshot({ path: file });
      else await page.screenshot({ path: file, fullPage: true });
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
      report.push(`${name} @${width}: ok${overflow ? ' (OVERFLOW)' : ''}`);
    } catch (e) {
      report.push(`${name} @${width}: FAILED — ${String(e.message).split('\n')[0]}`);
    }
  };
  const go = async (path) => { await page.goto(`${base}${path}`); };
  const closeDialogs = async () => { while (await page.locator('.eb-dialog-backdrop').count()) await page.keyboard.press('Escape'); };

  await shot('01-create-entry', async () => { await go('/organizer-dashboard/?view=create'); await page.getByTestId('eb-chooser').waitFor(); await page.getByTestId('eb-drafts').locator('.eb-draft, .eb-card').first().waitFor(); });
  await shot('14-draft-portfolio', async () => {}, { element: '[data-testid="eb-drafts"]' });
  await shot('23-template-library', async () => { await page.locator('[data-method="template"]').click(); await page.getByRole('dialog').locator('.eb-method').first().waitFor(); });
  await closeDialogs();
  await shot('24-event-duplication', async () => { await page.locator('[data-method="duplicate"]').click(); await page.getByRole('dialog', { name: 'Duplicate an event' }).waitFor(); });
  await closeDialogs();

  await shot('02-quick-create', async () => {
    await go('/organizer-dashboard/?view=create');
    await page.locator('[data-method="quick"]').click();
    await page.getByTestId('eb-step-info').waitFor();
    await page.getByLabel('Event name').fill('Queen City Night Market');
  });

  await go('/organizer-dashboard/?view=create');
  await page.locator('[data-method="single"]').click();
  await page.getByTestId('eb-step-info').waitFor();
  await page.getByLabel('Event name').fill(`Riverfront Makers Market`);
  await page.getByLabel('Description').fill('An evening market with food trucks, local makers, art and live music along the river. Families and students.');
  await page.locator('input[type="date"]').first().fill(day(50));
  await page.getByRole('combobox', { name: 'City and state' }).fill('Charlotte');
  await page.getByRole('listbox', { name: 'City suggestions' }).getByRole('option').first().click();
  await page.getByLabel('Venue name').fill('Camp North End');
  await page.waitForTimeout(1500);
  await shot('03-event-information', async () => {});
  await shot('04-image-upload', async () => {}, { element: '[data-testid="eb-media-frame"]' });
  await shot('05-date-location', async () => {}, { element: '.eb-ac' });
  await page.getByTestId('eb-continue').click();
  await page.getByTestId('eb-step-options').waitFor();
  for (const [cat, fee, spots] of [['Food Trucks', '150', '6'], ['Retail', '0', '20']]) {
    await page.locator('.eb-quickadd .eb-chip', { hasText: cat }).click();
    await page.getByLabel('Booth fee').fill(fee);
    await page.getByLabel('Spaces available').fill(spots);
  }
  await page.waitForTimeout(1500);
  await shot('06-vendor-opportunities', async () => {});
  await shot('07-advanced-booth-settings', async () => { await page.locator('.eb-option').first().click(); await page.waitForTimeout(400); }, { element: '.eb-option' });
  await page.getByTestId('eb-continue').click();
  await page.getByTestId('eb-step-applications').waitFor();
  await page.locator('.eb-addmenu .eb-chip', { hasText: 'Food truck set' }).click();
  await page.waitForTimeout(1200);
  await shot('08-application-builder', async () => {});
  await shot('09-document-requirements', async () => {}, { element: '[data-testid="eb-documents"]' });
  await page.getByTestId('eb-continue').click();
  await page.getByTestId('eb-step-payments').waitFor();
  await shot('10-payment-configuration', async () => { await page.waitForTimeout(800); });
  await page.getByTestId('eb-continue').click();
  await page.getByTestId('eb-step-publish').waitFor();
  await page.getByTestId('eb-preview-stage').locator('.eb-detail').waitFor();
  await shot('11-live-preview', async () => {}, { element: '[data-testid="eb-preview-stage"]' });
  await shot('12-publication-checklist', async () => {}, { element: '[data-testid="eb-checklist"]' });
  await shot('13-publication-success', async () => {
    await page.getByTestId('eb-publish').click();
    await page.getByTestId('eb-confirm-publish').click();
    await page.getByTestId('eb-success').waitFor({ timeout: 60000 });
  });

  await shot('15-url-import', async () => { await go('/organizer-dashboard/?view=create&method=import'); await page.getByTestId('eb-import').waitFor(); await page.getByLabel('Event link').fill(`${DEMO}/e/qc-night-market`); });
  await shot('16-import-preview', async () => { await page.getByTestId('eb-import-go').click(); await page.getByTestId('eb-import-continue').waitFor({ timeout: 30000 }); });
  await shot('17-duplicate-detection', async () => { await page.getByTestId('eb-import-duplicate').or(page.getByTestId('eb-import-own')).first().waitFor({ timeout: 5000 }); }, { element: '.eb-import__review' });
  await shot('18-bulk-import', async () => {
    await go('/organizer-dashboard/?view=create&method=bulk');
    await page.getByLabel('Event links').fill(`${DEMO}/e/bulk-one-${width}\n${DEMO}/e/partial-${width}`);
    await page.getByTestId('eb-bulk-read').click();
    await page.locator('[data-testid="eb-job-apply"]:not([disabled])').waitFor({ timeout: 45000 });
  });

  await go('/organizer-dashboard/?view=create&method=recurring');
  await page.getByTestId('eb-schedule').waitFor();
  await page.getByLabel('Event name').fill('Saturday Makers Market');
  const first = page.getByTestId('eb-schedule').locator('input[type="date"]').first();
  await first.fill(day(12));
  await first.dispatchEvent('change');
  await page.getByRole('button', { name: 'After a number of dates' }).click();
  await page.getByLabel('Number of dates').fill('8');
  await page.getByLabel('Number of dates').dispatchEvent('change');
  await shot('19-recurring-schedule', async () => { await page.getByTestId('eb-cal').locator('.eb-cal__day').nth(7).waitFor({ timeout: 20000 }); }, { element: '[data-testid="eb-schedule"]' });
  await shot('20-recurring-calendar', async () => { await page.getByTestId('eb-cal').locator('.eb-cal__day').nth(2).click(); await page.waitForTimeout(2500); }, { element: '[data-testid="eb-cal"]' });

  await go('/organizer-dashboard/?view=create&method=tour');
  await page.getByTestId('eb-stops').waitFor();
  await page.getByLabel('Event name').fill('Sneaker Summit');
  await page.getByRole('button', { name: 'Paste a list' }).click();
  await page.getByLabel('Stops, one per line').fill(`${day(40)}, Atlanta, GA, Pullman Yards\n${day(54)}, Dallas, TX, Fair Park\n${day(68)}, Houston, TX`);
  await page.getByRole('button', { name: 'Add', exact: true }).click();
  await shot('21-tour-stop-builder', async () => { await page.getByTestId('eb-stop').nth(2).waitFor(); });
  await shot('22-existing-event-association', async () => { await page.getByRole('button', { name: 'Add existing events' }).click(); await page.getByRole('dialog').locator('.eb-pick, .eb-help').first().waitFor(); });
  await closeDialogs();

  await go('/organizer-dashboard/?view=events');
  const editHref = await page.locator('a[href*="edit=1"], a[href*="edit="]').first().getAttribute('href').catch(() => null);
  await shot('25-edit-event', async () => {
    if (!editHref) throw new Error('no event to edit');
    await page.goto(editHref.startsWith('http') ? editHref : `${base}${editHref}`);
    await page.getByTestId('eb-step-info').waitFor({ timeout: 20000 });
  });
  await shot('26-external-sources', async () => { await page.getByTestId('eb-sources-card').scrollIntoViewIfNeeded(); }, { element: '[data-testid="eb-sources-card"]' });
  await shot('28-imports-center', async () => { await go('/organizer-dashboard/?view=imports'); await page.getByTestId('eb-ic-sources').waitFor({ timeout: 20000 }); });
  await ctx.close();
}
await browser.close();
console.log(report.join('\n'));
