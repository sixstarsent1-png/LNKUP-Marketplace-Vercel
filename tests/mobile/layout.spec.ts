import { test, expect, type Page } from '@playwright/test';
import { appLogin, expectNoHorizontalOverflow, organizerUser, vendorUser } from './helpers/app-session';

const vendorTabs = ['/(vendor)', '/(vendor)/find', '/(vendor)/requests', '/(vendor)/wallet', '/(vendor)/more'];
const organizerTabs = ['/(organizer)', '/(organizer)/events', '/(organizer)/vendors', '/(organizer)/money', '/(organizer)/more'];

/**
 * Close the first-run tutorial if it is covering the screen.
 */
async function dismissTutorial(page: Page) {
  const close = page.getByRole('button', { name: /close|done|skip/i }).first();
  if (await close.isVisible().catch(() => false)) await close.click().catch(() => undefined);
}

/**
 * Every tab label must sit fully inside the tab bar (no clipped text) and keep a 44pt target.
 */
async function expectTabBarFits(page: Page) {
  const tabs = page.getByRole('tab');
  const count = await tabs.count();
  expect(count).toBeGreaterThanOrEqual(5);
  const viewport = page.viewportSize()!;
  for (let i = 0; i < count; i += 1) {
    const tab = tabs.nth(i);
    if (!(await tab.isVisible())) continue;
    const box = await tab.boundingBox();
    const name = (await tab.getAttribute('aria-label')) ?? (await tab.innerText());
    expect(box, `tab "${name}" rendered`).not.toBeNull();
    expect(box!.y + box!.height, `tab "${name}" inside the screen`).toBeLessThanOrEqual(viewport.height + 1);
    expect(box!.height, `tab "${name}" touch target`).toBeGreaterThanOrEqual(44);
  }
}

for (const [role, user, routes] of [
  ['vendor', vendorUser, vendorTabs],
  ['organizer', organizerUser, organizerTabs],
] as const) {
  test(`${role} tabs fit the phone without overflow or clipped labels`, async ({ page }) => {
    test.setTimeout(240_000);
    await appLogin(page, user);
    for (const route of routes) {
      await page.goto(route);
      await page.waitForLoadState('networkidle', { timeout: 30_000 }).catch(() => undefined);
      await dismissTutorial(page);
      await expectNoHorizontalOverflow(page, `${role} ${route}`);
      await expectTabBarFits(page);
    }
  });
}
