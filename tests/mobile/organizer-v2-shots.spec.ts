import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { appLogin, expectNoHorizontalOverflow, organizerUser, restAs } from './helpers/app-session';

const label = process.env.ORG_V2_SHOTS_LABEL || 'after';
const shotDir = path.join(__dirname, '..', '..', 'docs', 'mobile-organizer-v2', 'screenshots', label);

const allViewports = [
  { name: '360x800', width: 360, height: 800 },
  { name: '390x844', width: 390, height: 844 },
  { name: '393x852', width: 393, height: 852 },
  { name: '430x932', width: 430, height: 932 },
];
const onlyViewports = (process.env.ORG_V2_VIEWPORTS || '').split(',').filter(Boolean);
const viewports = onlyViewports.length ? allViewports.filter((v) => onlyViewports.includes(v.name)) : allViewports;
const onlyScreens = (process.env.ORG_V2_ONLY || '').split(',').filter(Boolean);

type PortfolioRow = { id?: number; event_id?: number; chronology?: string };
type Application = { application_id: number };
type Payout = { id: number };

/**
 * Organizer screens to capture, resolved against live records so detail routes use real IDs.
 */
async function organizerScreens(page: Page): Promise<Array<{ name: string; route: string }>> {
  const portfolio = await restAs(page, organizerUser, 'GET', '/organizer/portfolio');
  const rows = ((portfolio.body as { events?: PortfolioRow[] })?.events ?? []) as PortfolioRow[];
  const event = rows.find((r) => r.chronology === 'upcoming') ?? rows[0];
  const eventId = event?.event_id ?? event?.id;
  const apps = await restAs(page, organizerUser, 'GET', '/applications/organizer');
  const app = ((apps.body as { items?: Application[] })?.items ?? [])[0];

  const screens = [
    { name: 'home', route: '/(organizer)' },
    { name: 'events', route: '/(organizer)/events' },
    { name: 'vendors', route: '/(organizer)/vendors' },
    { name: 'discover', route: '/(organizer)/vendors?segment=discover' },
    { name: 'invites', route: '/(organizer)/vendors?segment=invites' },
    { name: 'money', route: '/(organizer)/money' },
    { name: 'more', route: '/(organizer)/more' },
    { name: 'profile', route: '/(organizer)/profile' },
    { name: 'notification-settings', route: '/(organizer)/notification-settings' },
    { name: 'notifications', route: '/(organizer)/notifications' },
    { name: 'referrals', route: '/(organizer)/referrals' },
  ];
  if (eventId) {
    screens.push({ name: 'event-detail', route: `/(organizer)/events/${eventId}` });
    screens.push({ name: 'checkin', route: `/(organizer)/events/checkin?eventId=${eventId}` });
  }
  if (label === 'before') return screens;
  if (eventId) {
    screens.push({ name: 'operations', route: `/(organizer)/events/operations?eventId=${eventId}` });
    screens.push({ name: 'event-money', route: `/(organizer)/money/event?eventId=${eventId}` });
  }
  if (app) screens.push({ name: 'application-review', route: `/(organizer)/vendors/review/${app.application_id}` });
  screens.push({ name: 'cashout', route: '/(organizer)/money/cashout' });
  screens.push({ name: 'tours', route: '/(organizer)/tours' });
  const payouts = await restAs(page, organizerUser, 'GET', '/financial/organizer-payouts');
  const payout = (((payouts.body as { items?: Payout[] })?.items ?? (payouts.body as Payout[])) ?? [])[0];
  if (payout) screens.push({ name: 'payout-detail', route: `/(organizer)/money/payout?id=${payout.id}` });
  return screens;
}

test.describe('Organizer OS 2.0 screenshots', () => {
  test.setTimeout(1_200_000);

  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'iphone-15', 'Screenshots run once; viewports are set per shot');
  });

  test('captures every organizer screen at each target viewport', async ({ page }) => {
    fs.mkdirSync(shotDir, { recursive: true });
    await appLogin(page, organizerUser);
    const screens = (await organizerScreens(page)).filter((s) => !onlyScreens.length || onlyScreens.includes(s.name));
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      for (const screen of screens) {
        await page.goto(screen.route);
        await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined);
        if (label !== 'before') {
          await expect.soft(page.getByText(/EAS project ID/), `${screen.name} @ ${viewport.name}`).toHaveCount(0);
          await expect.soft(page.getByText(/\b\d{4}-\d{2}-\d{2}\b/), `raw date on ${screen.name} @ ${viewport.name}`).toHaveCount(0);
          await expectNoHorizontalOverflow(page, `${screen.name} @ ${viewport.name}`);
        }
        await page.screenshot({ path: path.join(shotDir, `${viewport.name}-${screen.name}.png`) });
      }
    }
  });
});
