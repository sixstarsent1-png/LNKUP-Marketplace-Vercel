import { expect, type Page, type Request } from '@playwright/test';

export const vendorUser = process.env.PLAYWRIGHT_VENDOR_USER || 'e2e_vendor_a';
export const vendorBUser = process.env.PLAYWRIGHT_VENDOR_B_USER || 'e2e_vendor_b';
export const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER || 'e2e_org_a';
export const organizerBUser = process.env.PLAYWRIGHT_ORGANIZER_B_USER || 'e2e_org_b';
export const password = process.env.PLAYWRIGHT_PASSWORD || 'lnkup-e2e-Passw0rd!';
export const wpBase = process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8888';

/**
 * Sign in through the app's own login screen and wait for the role home to render.
 */
export async function appLogin(page: Page, user: string, pass: string = password): Promise<void> {
  if (!/\/login/.test(page.url())) await page.goto('/login');
  await page.getByLabel('Username').fill(user);
  await page.getByLabel('Password', { exact: true }).fill(pass);
  await page.getByRole('button', { name: /^sign in$/i }).click();
  await expect(page).not.toHaveURL(/\/login/, { timeout: 60_000 });
}

/**
 * Sign out from any screen by clearing the stored session and returning to login.
 */
export async function appLogout(page: Page): Promise<void> {
  await page.evaluate(() => window.localStorage.clear());
  await page.goto('/login');
  await expect(page.getByLabel('Username')).toBeVisible();
}

/**
 * Collect lnkup REST requests made by the page so specs can assert request budgets.
 */
export function trackApiRequests(page: Page): { requests: string[]; reset: () => void } {
  const requests: string[] = [];
  page.on('request', (request: Request) => {
    const url = request.url();
    if (url.includes('/wp-json/')) {
      requests.push(`${request.method()} ${url.replace(/^.*\/wp-json/, '')}`);
    }
  });
  return {
    requests,
    reset: () => {
      requests.length = 0;
    },
  };
}

/**
 * Collect console errors and uncaught page errors.
 */
export function trackPageErrors(page: Page): string[] {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('console', (message) => {
    if (message.type() === 'error') errors.push(`console: ${message.text()}`);
  });
  return errors;
}

/**
 * Assert the page never scrolls horizontally at the current viewport.
 */
export async function expectNoHorizontalOverflow(page: Page, label: string): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  expect(overflow, `${label} should not scroll horizontally`).toBeLessThanOrEqual(1);
}

/**
 * Call lnkup/v1 as a user with a fresh mobile Bearer token (for cross-role assertions).
 */
export async function restAs(
  page: Page,
  user: string,
  method: 'GET' | 'POST',
  path: string,
  data?: unknown,
): Promise<{ status: number; body: unknown }> {
  const login = await page.request.post(`${wpBase}/wp-json/lnkup/v1/auth/login`, {
    data: { username: user, password },
  });
  expect(login.ok(), `${user} REST login`).toBeTruthy();
  const { token } = (await login.json()) as { token: string };
  const response = await page.request.fetch(`${wpBase}/wp-json/lnkup/v1${path}`, {
    method,
    headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
    data,
  });
  return { status: response.status(), body: await response.json().catch(() => null) };
}
