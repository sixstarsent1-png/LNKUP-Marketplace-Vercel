import { test, expect } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const securityUser = process.env.PLAYWRIGHT_STAFF_SECURITY_USER ?? '';
const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID ?? '';

test.describe('Event-day check-in', () => {
  test.skip(!organizerUser || !eventId, 'Run scripts/e2e/seed.php');

  test('scan page shows check-in counts and a searchable vendor lookup', async ({ page }) => {
    await loginPage(page, organizerUser, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&scan=1`);
    const counts = page.getByTestId('lnkup-checkin-counts');
    for (const label of ['Expected', 'Checked in', 'Not yet', 'Exceptions']) {
      await expect(counts).toContainText(label);
    }
    const lookup = page.getByTestId('lnkup-checkin-lookup');
    const empty = page.getByText(/no paid vendors for this event yet/i);
    await expect(lookup.or(empty)).toBeVisible();
    if (await lookup.isVisible()) {
      await page.getByTestId('lnkup-checkin-search').fill('zzzz-no-match');
      await expect(lookup.getByRole('listitem').filter({ visible: true })).toHaveCount(0);
    } else {
      await expect(counts).toContainText(/Expected\s*0/);
    }
  });

  test('security staff can open check-in for their event', async ({ page }) => {
    test.skip(!securityUser, 'No security staff fixture');
    await loginPage(page, securityUser, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&scan=1`);
    await expect(page.getByTestId('lnkup-checkin-counts')).toBeVisible();
    await expect(page.getByText(/permission denied/i)).toHaveCount(0);
  });

  test('vendor pass builder shows the packet stage', async ({ page }) => {
    await loginPage(page, organizerUser, password);
    await page.goto(`/organizer-dashboard/?event=${eventId}&ops=1`);
    const stage = page.getByTestId('lnkup-packet-stage');
    await expect(stage).toHaveAttribute('data-stage', /^(draft|ready|published)$/);
    await expect(stage).toContainText(/draft|ready|published/i);
  });
});
