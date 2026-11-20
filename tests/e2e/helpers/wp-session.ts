import { expect, type APIRequestContext, type Browser, type BrowserContext, type Page } from '@playwright/test';

/**
 * Log an existing page into WordPress (one retry for wp-env reauth bounces).
 */
export async function loginPage(page: Page, user: string, pass: string): Promise<void> {
  for (let attempt = 0; attempt < 2; attempt += 1) {
    await page.goto('/wp-login.php');
    await page.getByLabel(/username or email/i).fill(user);
    await page.getByLabel(/^password$/i).fill(pass);
    await page.getByRole('button', { name: /log in/i }).click();
    await page.waitForLoadState('domcontentloaded');
    if (!/wp-login/.test(page.url())) break;
  }
  await expect(page, `${user} should be logged in`).not.toHaveURL(/wp-login/);
}

export interface WpSession {
  context: BrowserContext;
  request: APIRequestContext;
  nonce: string;
}

/**
 * Log into WordPress in a fresh browser context and return the REST nonce the dashboard exposes.
 * Retries once because parallel logins against the single wp-env Apache container occasionally bounce to reauth.
 */
export async function loginAs(browser: Browser, user: string, pass: string, landing: string): Promise<WpSession> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await loginPage(page, user, pass);
  await page.goto(landing);
  const nonce = await page.evaluate(() => {
    const w = window as unknown as {
      lnkupAjax?: { restNonce?: string };
      wpApiSettings?: { nonce?: string };
      lnkupOrganizerCrm?: { nonce?: string };
    };
    return w.lnkupAjax?.restNonce || w.wpApiSettings?.nonce || w.lnkupOrganizerCrm?.nonce || '';
  });
  expect(nonce, `${landing} should expose a REST nonce for ${user}`).not.toBe('');
  await page.close();
  return { context, request: context.request, nonce };
}

/**
 * Call an lnkup/v1 route with a logged-in session.
 */
export function lnkupRest(session: WpSession, method: 'GET' | 'POST' | 'PUT' | 'DELETE', path: string, data?: unknown) {
  return session.request.fetch(`/wp-json/lnkup/v1${path}`, {
    method,
    headers: { 'X-WP-Nonce': session.nonce, 'Content-Type': 'application/json', Accept: 'application/json' },
    data,
  });
}
