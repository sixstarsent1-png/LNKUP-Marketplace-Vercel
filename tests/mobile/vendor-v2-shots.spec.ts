import { test, expect, type Page } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { appLogin, expectNoHorizontalOverflow, restAs, vendorUser } from './helpers/app-session';

const label = process.env.VENDOR_V2_SHOTS_LABEL || 'after';
const shotDir = path.join(__dirname, '..', '..', 'docs', 'mobile-vendor-v2', 'screenshots', label);

const allViewports = [
  { name: '360x800', width: 360, height: 800 },
  { name: '390x844', width: 390, height: 844 },
  { name: '393x852', width: 393, height: 852 },
  { name: '430x932', width: 430, height: 932 },
];
const onlyViewports = (process.env.VENDOR_V2_VIEWPORTS || '').split(',').filter(Boolean);
const viewports = onlyViewports.length ? allViewports.filter((v) => onlyViewports.includes(v.name)) : allViewports;
const onlyScreens = (process.env.VENDOR_V2_ONLY || '').split(',').filter(Boolean);

type BookingRow = { application_id: number; event_id?: number; credential_state?: string };
type TransactionRow = { id: number; status: string; payment_status: string };
type ReceiptRow = { id: number };

/**
 * Vendor screens to capture, resolved against live records so detail routes use real IDs.
 */
async function vendorScreens(page: Page): Promise<Array<{ name: string; route: string }>> {
  const bookings = await restAs(page, vendorUser, 'GET', '/applications/vendor');
  const items = ((bookings.body as { items?: BookingRow[] })?.items ?? []) as BookingRow[];
  const first = items[0];
  const withEvent = items.find((b) => b.event_id);
  const withPass = items.find((b) => b.credential_state === 'active') ?? first;
  const txns = await restAs(page, vendorUser, 'GET', '/financial/transactions?role=vendor&limit=20');
  const txn = ((txns.body as TransactionRow[]) ?? []).find((t) => ['paid', 'completed'].includes(t.status));
  const receipts = await restAs(page, vendorUser, 'GET', '/financial/receipts?role=vendor');
  const receipt = ((receipts.body as ReceiptRow[]) ?? [])[0];

  const screens = [
    { name: 'home', route: '/(vendor)' },
    { name: 'explore', route: '/(vendor)/find' },
    { name: 'bookings', route: '/(vendor)/requests' },
    { name: 'wallet', route: '/(vendor)/wallet' },
    { name: 'profile', route: '/(vendor)/more' },
    { name: 'passport', route: '/(vendor)/passport' },
    { name: 'documents', route: '/(vendor)/documents' },
    { name: 'growth', route: '/(vendor)/growth' },
    { name: 'notifications', route: '/(vendor)/notifications' },
    { name: 'referrals', route: '/(vendor)/referrals' },
    { name: 'saved', route: '/(vendor)/favorites' },
    { name: 'operations', route: '/(vendor)/operations' },
  ];
  if (first) screens.push({ name: 'booking-detail', route: `/booking/${first.application_id}` });
  if (withPass) screens.push({ name: 'event-pass', route: `/pass/${withPass.application_id}` });
  if (label === 'before') return screens;
  if (withEvent?.event_id) screens.push({ name: 'event-detail', route: `/event/${withEvent.event_id}` });
  if (txn) screens.push({ name: 'transaction-detail', route: `/wallet/transaction/${txn.id}` });
  if (receipt) screens.push({ name: 'receipt-detail', route: `/wallet/receipt/${receipt.id}` });
  return screens;
}

test.describe('Vendor OS 2.0 screenshots', () => {
  test.setTimeout(900_000);

  test.beforeEach(({}, testInfo) => {
    test.skip(testInfo.project.name !== 'iphone-15', 'Screenshots run once; viewports are set per shot');
  });

  test('captures every vendor screen at each target viewport', async ({ page }) => {
    fs.mkdirSync(shotDir, { recursive: true });
    await appLogin(page, vendorUser);
    const screens = (await vendorScreens(page)).filter((s) => !onlyScreens.length || onlyScreens.includes(s.name));
    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      for (const screen of screens) {
        await page.goto(screen.route);
        await page.waitForLoadState('networkidle', { timeout: 20_000 }).catch(() => undefined);
        await expect.soft(page.getByText('Event details unavailable'), `${screen.name} @ ${viewport.name}`).toHaveCount(0);
        if (label !== 'before') await expectNoHorizontalOverflow(page, `${screen.name} @ ${viewport.name}`);
        await page.screenshot({ path: path.join(shotDir, `${viewport.name}-${screen.name}.png`) });
      }
    }
  });
});
