import { test, expect } from '@playwright/test';
import { appLogin, appLogout, organizerUser, password, vendorUser, wpBase } from './helpers/app-session';

const noRoleUser = process.env.PLAYWRIGHT_NO_ROLE_USER || 'e2e_no_role';

test.describe('Sign-in, role routing and deep links', () => {
  test('an account without a marketplace role sees why it cannot sign in', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Username').fill(noRoleUser);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: /^sign in$/i }).click();
    await expect(page.getByText(/cannot use the LNKup app/i)).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });

  test('a stored session that lost its roles lands on no-access instead of looping', async ({ page }) => {
    const login = await page.request.post(`${wpBase}/wp-json/lnkup/v1/auth/login`, {
      data: { username: vendorUser, password },
    });
    const body = (await login.json()) as { token: string; user: { id: number; name: string; slug: string } };
    await page.goto('/login');
    await page.evaluate(
      ([token, user]) => {
        window.localStorage.setItem(
          'lnkup_wp_session',
          JSON.stringify({ username: 'x', appPassword: '', token, user: { ...user, roles: [] } }),
        );
      },
      [body.token, body.user] as const,
    );
    await page.goto('/');
    await expect(page.getByRole('heading', { name: /isn't set up for LNKup/i })).toBeVisible();
    await page.getByRole('button', { name: /sign out/i }).click();
    await expect(page.getByLabel('Username')).toBeVisible();
  });

  test('a signed-out deep link opens after sign-in', async ({ page }) => {
    await page.goto('/(vendor)/wallet');
    await expect(page).toHaveURL(/\/login/);
    await appLogin(page, vendorUser);
    await expect(page).toHaveURL(/\/wallet/);
  });

  test('a destination for another role is dropped after sign-in', async ({ page }) => {
    await page.goto('/(organizer)/money');
    await expect(page).toHaveURL(/\/login/);
    await appLogin(page, vendorUser);
    await expect(page.getByRole('tab', { name: /explore/i })).toBeVisible();
    await expect(page).not.toHaveURL(/\/money/);
  });

  test('signing out does not send the next user back to the previous screen', async ({ page }) => {
    await appLogin(page, organizerUser);
    await page.goto('/(organizer)/more');
    await page.getByRole('button', { name: /sign out/i }).first().click();
    const confirm = page.getByRole('button', { name: /^sign out$/i }).last();
    if (await confirm.isVisible().catch(() => false)) await confirm.click();
    await expect(page.getByLabel('Username')).toBeVisible();
    await appLogin(page, organizerUser);
    await expect(page).not.toHaveURL(/\/more/);
  });
});

test.describe('Live data honesty', () => {
  test('a failed requests load shows an error, never sample data', async ({ page }) => {
    await appLogin(page, vendorUser);
    await page.route('**/wp-json/lnkup/v1/applications/vendor**', (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"x","message":"boom"}' }),
    );
    await page.goto('/(vendor)/requests');
    await expect(page.getByRole('alert').first()).toBeVisible();
    await expect(page.getByText(/couldn't reach LNKup/i).first()).toBeVisible();
    await expect(page.getByText(/demo data|sample/i)).toHaveCount(0);
    await expect(page.getByText(/boom/)).toHaveCount(0);
  });

  test('a failed wallet load shows no payable sample rows', async ({ page }) => {
    await appLogin(page, vendorUser);
    await page.route('**/wp-json/lnkup/v1/financial/**', (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }),
    );
    await page.route('**/wp-json/lnkup/v1/applications/vendor**', (route) =>
      route.fulfill({ status: 500, contentType: 'application/json', body: '{}' }),
    );
    await page.goto('/(vendor)/wallet');
    await expect(page.getByRole('alert').first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^pay/i })).toHaveCount(0);
  });

  test.afterEach(async ({ page }) => {
    await appLogout(page).catch(() => undefined);
  });
});
