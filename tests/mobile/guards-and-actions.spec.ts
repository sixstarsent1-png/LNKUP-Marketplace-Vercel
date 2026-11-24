import { test, expect, type Page } from '@playwright/test';
import { appLogin, organizerUser, restAs, vendorBUser, vendorUser } from './helpers/app-session';

const eventId = process.env.PLAYWRIGHT_ORGANIZER_EVENT_ID || '56';
const capacityOpportunityId = process.env.PLAYWRIGHT_CAPACITY_OPPORTUNITY_ID || '57';

/**
 * Read the vendor's saved opportunity IDs straight from WordPress.
 */
async function serverFavorites(page: Page, user: string): Promise<number[]> {
  const { body } = await restAs(page, user, 'GET', '/marketplace/favorites');
  const raw = body as { favorites?: { opportunities?: Array<number | string> } } | null;
  return (raw?.favorites?.opportunities ?? []).map(Number).filter(Boolean);
}

test.describe('Vendor guards', () => {
  test('a double-tapped heart ends in one consistent saved state', async ({ page }) => {
    const opportunityId = Number(capacityOpportunityId);
    const wasSaved = (await serverFavorites(page, vendorUser)).includes(opportunityId);
    await appLogin(page, vendorUser);
    await page.goto(`/opportunity/${opportunityId}`);
    const heart = page.getByRole('button', { name: wasSaved ? /^Unsave / : /^Save / }).first();
    await expect(heart).toBeVisible({ timeout: 45_000 });
    await heart.dblclick();
    await expect.poll(async () => (await serverFavorites(page, vendorUser)).includes(opportunityId), { timeout: 45_000 }).toBe(!wasSaved);
    await page.reload();
    await expect(
      page.getByRole('button', { name: !wasSaved ? /^Unsave / : /^Save / }).first(),
    ).toBeVisible({ timeout: 45_000 });
    await page.waitForTimeout(2_000);
    expect((await serverFavorites(page, vendorUser)).includes(opportunityId)).toBe(!wasSaved);
  });

  test('deleting a saved search asks first and then removes it', async ({ page }) => {
    const name = `E2E search ${Date.now()}`;
    const created = await restAs(page, vendorBUser, 'POST', '/marketplace/saved-searches', {
      name,
      filters: { city: 'Charlotte', q: String(Date.now()) },
      force: true,
    });
    expect(created.status).toBeLessThan(300);
    await appLogin(page, vendorBUser);
    await page.goto('/(vendor)/saved-searches');
    await expect(page.getByText(name)).toBeVisible();
    await page.getByRole('button', { name: `Delete ${name}` }).click();
    await expect(page.getByRole('heading', { name: 'Delete saved search?' })).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByText(name)).toBeVisible();
    await page.getByRole('button', { name: `Delete ${name}` }).click();
    await page.getByRole('button', { name: 'Delete', exact: true }).click();
    await expect(page.getByText('Saved search deleted.')).toBeVisible({ timeout: 45_000 });
    await expect(page.getByText(name)).toHaveCount(0);
    const after = await restAs(page, vendorBUser, 'GET', '/marketplace/saved-searches');
    expect(JSON.stringify(after.body), 'the delete persisted on the server').not.toContain(name);
  });

  test('sign out asks for confirmation', async ({ page }) => {
    await appLogin(page, vendorUser);
    await page.goto('/(vendor)/more');
    await page.getByTestId('vendor-more-sign-out').click();
    await expect(page.getByRole('heading', { name: 'Sign out?' })).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page).toHaveURL(/\/more/);
    await page.getByTestId('vendor-more-sign-out').click();
    await page.getByRole('button', { name: 'Sign out', exact: true }).last().click();
    await expect(page.getByLabel('Username')).toBeVisible();
  });
});

test.describe('Organizer guards', () => {
  test('declining asks for a reason and cancel changes nothing', async ({ page }) => {
    await appLogin(page, organizerUser);
    await page.goto('/(organizer)/vendors?tab=requests&filter=review');
    const row = page.locator('[data-testid^="organizer-request-"]').first();
    const hasOpenRequest = await row
      .waitFor({ state: 'visible', timeout: 30_000 })
      .then(() => true)
      .catch(() => false);
    test.skip(!hasOpenRequest, 'No open request to decline in the seed');
    await row.click();
    const decline = page.getByTestId('review-decline');
    await expect(decline).toBeVisible({ timeout: 45_000 });
    await decline.click();
    await expect(page.getByRole('heading', { name: /^Decline / })).toBeVisible();
    await page.getByRole('button', { name: 'Decline', exact: true }).last().click();
    await expect(page.getByText('Enter at least 3 characters.')).toBeVisible();
    await page.getByRole('button', { name: 'Cancel' }).click();
    await expect(page.getByTestId('review-decline')).toBeVisible();
  });

  test('invites go to a chosen event and a repeat is reported, not duplicated', async ({ page }) => {
    await appLogin(page, organizerUser);
    await page.goto('/(organizer)/vendors?tab=discover');
    const invite = page.getByRole('button', { name: /^Invite .* to apply$/ }).first();
    await expect(invite).toBeVisible();
    for (let attempt = 0; attempt < 2; attempt += 1) {
      await invite.click();
      await expect(page.getByRole('heading', { name: /^Invite / })).toBeVisible();
      const eventChip = page.getByRole('button', { name: /E2E Summer Market/ }).first();
      await eventChip.click();
      const slot = page.getByRole('button', { name: /Food Vendor/ }).first();
      if (await slot.isVisible().catch(() => false)) await slot.click();
      await page.getByRole('button', { name: 'Send invite' }).click();
      await expect(page.getByText(/Invite sent to|already has an open invite/)).toBeVisible();
    }
    await expect(page.getByText(/already has an open invite/).last()).toBeVisible();
  });

  test('pricing refuses an invalid spot count without saving', async ({ page }) => {
    const before = await restAs(page, organizerUser, 'GET', `/marketplace/opportunity/${capacityOpportunityId}`);
    await appLogin(page, organizerUser);
    await page.goto(`/(organizer)/events/${eventId}?tab=slots`);
    const spots = page.getByRole('textbox', { name: /^Food Vendor Space spots$/ });
    await expect(spots).toBeVisible({ timeout: 45_000 });
    await spots.fill('0');
    const slot = page.getByText(/held · .* available of/).first();
    await expect(slot).toBeVisible();
    await page.getByRole('button', { name: 'Save fee & spots' }).first().click();
    await expect(page.getByText(/Enter at least 1 vendor spot|already booked or held — spots can't go below that/)).toBeVisible();
    const after = await restAs(page, organizerUser, 'GET', `/marketplace/opportunity/${capacityOpportunityId}`);
    expect(JSON.stringify(after.body)).toBe(JSON.stringify(before.body));
  });
});
