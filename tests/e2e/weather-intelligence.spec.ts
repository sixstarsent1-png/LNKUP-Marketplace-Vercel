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

    test('organizer dashboard shows weather readiness card', async ({ page }) => {
      await page.goto('/organizer-dashboard/');
      const card = page.getByTestId('lnkup-weather-readiness-card');
      await expect(card).toBeVisible({ timeout: 15000 });
      await expect(card).not.toContainText('Loading', { timeout: 15000 });
    });

    test('operations command center weather panel does not 500', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      const weatherPanel = page.locator('#lnkup-ops-weather-panel');
      await expect(weatherPanel).toBeVisible({ timeout: 15000 });
      const weatherCard = weatherPanel.getByTestId('lnkup-ops-weather');
      if (await weatherCard.count()) {
        await expect(weatherCard).toBeVisible();
        await expect(weatherCard).toContainText(/weather readiness|weather intelligence|configure|location|forecast/i);
      } else {
        await expect(weatherPanel).not.toContainText('Fatal error');
        await expect(weatherPanel).not.toContainText('500');
      }
    });

    test('insights tab includes weather intelligence block when configured', async ({ page }) => {
      await page.goto('/organizer-dashboard/#events');
      const opsBtn = page.getByRole('link', { name: /^operations$/i }).first();
      if (!(await opsBtn.count())) {
        test.skip(true, 'No events with operations link');
        return;
      }
      await opsBtn.click();
      await page.getByRole('button', { name: /^insights$/i }).click();
      await expect(page.locator('#lnkup-event-insights')).toBeVisible({ timeout: 15000 });
      const intelWeather = page.locator('#lnkup-ops-weather-intel, .lnkup-weather-intel-block');
      if (await intelWeather.count()) {
        await expect(intelWeather.first()).toBeVisible();
      }
    });
  });
});
