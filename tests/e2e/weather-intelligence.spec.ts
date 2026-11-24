import { test, expect } from '@playwright/test';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER;
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS;

async function loginOrganizer(page: import('@playwright/test').Page) {
  await page.goto('/wp-login.php');
  await page.getByLabel(/username or email/i).fill(organizerUser!);
  await page.getByLabel(/^password$/i).fill(organizerPass!);
  await page.getByRole('button', { name: /log in/i }).click();
  await expect(page).not.toHaveURL(/wp-login/);
}

test.describe('Event Weather Intelligence', () => {
  test('weather REST admin status requires auth', async ({ request }) => {
    const res = await request.get('/wp-json/lnkup/v1/weather/admin/status');
    expect(res.status()).toBeGreaterThanOrEqual(401);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Set PLAYWRIGHT_ORGANIZER_USER and PLAYWRIGHT_ORGANIZER_PASS');
      await loginOrganizer(page);
    });

    test('weather shows once per event: on Overview, not repeated on Operations', async ({ page }) => {
      const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID;
      test.skip(!eventId, 'Run scripts/e2e/seed.php');
      await page.goto(`/organizer-dashboard/?event=${eventId}`);
      await expect(page.getByTestId('lnkup-event-weather')).not.toContainText('Loading', { timeout: 15000 });
      await page.goto(`/organizer-dashboard/?event=${eventId}&ops=1`);
      await expect(page.locator('#lnkup-ops-weather-panel')).toHaveCount(0);
    });

    test('event workspace weather card does not 500', async ({ page }) => {
      const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID;
      test.skip(!eventId, 'Run scripts/e2e/seed.php');
      await page.goto(`/organizer-dashboard/?event=${eventId}`);
      const weatherPanel = page.getByTestId('lnkup-event-weather');
      await expect(weatherPanel).toBeVisible({ timeout: 15000 });
      await expect(weatherPanel).toContainText(/weather|forecast|location/i);
      await expect(weatherPanel).not.toContainText('Fatal error');
    });

    test('insights tab includes weather intelligence block when configured', async ({ page }) => {
      const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID;
      test.skip(!eventId, 'Run scripts/e2e/seed.php');
      await page.goto(`/organizer-dashboard/?event=${eventId}&ops=1`);
      const insights = page.getByRole('tab', { name: /^insights$/i });
      if (!(await insights.isVisible())) {
        await page.locator('[data-oos-ops-more] > summary').click();
      }
      await insights.click();
      await expect(page.locator('#lnkup-event-insights')).toBeVisible({ timeout: 15000 });
      const intelWeather = page.locator('#lnkup-ops-weather-intel, .lnkup-weather-intel-block');
      if (await intelWeather.count()) {
        await expect(intelWeather.first()).toBeVisible();
      }
    });
  });
});
