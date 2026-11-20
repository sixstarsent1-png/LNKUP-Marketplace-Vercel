import { test, expect, type Page } from '@playwright/test';
import { loginPage } from './helpers/wp-session';

const organizerUser = process.env.PLAYWRIGHT_ORGANIZER_USER ?? '';
const organizerPass = process.env.PLAYWRIGHT_ORGANIZER_PASS ?? '';
const CREATE_URL = '/organizer-dashboard/?view=create';

/**
 * Fill the required Create Event fields with a unique title and one custom vendor slot.
 */
async function fillEvent(page: Page, title: string) {
  const next = page.locator('[data-wizard-nav="next"]');
  await page.getByTestId('lnkup-event-name').fill(title);
  await page.locator('#event_type_parent').selectOption({ index: 1 });
  await expect(page.locator('#event_type')).toBeEnabled();
  await page.locator('#event_type').selectOption({ index: 1 });
  await next.click();
  await page.getByTestId('lnkup-event-date').fill('2027-06-15');
  await page.locator('#event_city').fill('Austin');
  await next.click();
  await page.locator('.lnkup-fee-row__custom').first().fill('E2E Food');
  await page.getByTestId('lnkup-create-opp-fee').fill('125');
  await page.getByTestId('lnkup-create-opp-spots').fill('4');
  await expect(page.getByTestId('lnkup-create-app-deadline')).toBeVisible();
  await next.click();
}

/**
 * Wait for the next lnkup_create_event AJAX response and return its JSON body.
 */
async function nextCreateResponse(page: Page): Promise<{ success: boolean; data: Record<string, unknown> }> {
  let done = false;
  return new Promise((resolve) => {
    void page.route('**/admin-ajax.php', async (route) => {
      if (done || !(route.request().postData() ?? '').includes('lnkup_create_event')) {
        await route.fallback();
        return;
      }
      done = true;
      const response = await route.fetch();
      const body = await response.json();
      await route.fulfill({ response });
      resolve(body);
    });
  });
}

test.describe('Organizer Create Event', () => {
  test('create wizard requires login', async ({ page }) => {
    await page.goto(CREATE_URL);
    await expect(page).toHaveURL(/log-?in/);
  });

  test.describe('authenticated organizer', () => {
    test.beforeEach(async ({ page }) => {
      test.skip(!organizerUser || !organizerPass, 'Run scripts/e2e/seed.php');
      await loginPage(page, organizerUser, organizerPass);
      await page.goto(CREATE_URL);
      await expect(page.locator('#lnkup-create-event-form')).toBeAttached();
    });

    test('publishes an event with a vendor slot', async ({ page }) => {
      await fillEvent(page, `E2E Publish ${Date.now()}`);
      const created = nextCreateResponse(page);
      await page.getByTestId('lnkup-publish-event-sidebar').click();
      const body = await created;
      expect(body.success).toBe(true);
      expect(Number(body.data.event_id)).toBeGreaterThan(0);
      expect(body.data.opportunity_created).toBe(true);
      await expect(page.locator('#lnkup-launch-success-root')).toBeVisible();
    });

    test('saves a draft without opening applications', async ({ page }) => {
      await fillEvent(page, `E2E Draft ${Date.now()}`);
      const created = nextCreateResponse(page);
      await page.getByTestId('lnkup-save-event-draft').click();
      const body = await created;
      expect(body.success).toBe(true);
      expect(body.data.draft).toBe(true);
      expect(body.data.opportunity_created).toBeFalsy();
      await expect(page).toHaveURL(/[?&]event=\d+/);
    });

    test('partial failure keeps the event and retry does not duplicate it', async ({ page }) => {
      await fillEvent(page, `E2E Partial ${Date.now()}`);
      await page.locator('#lnkup-create-event-form').evaluate((form) => form.setAttribute('data-test-block-opp', '1'));
      const first = nextCreateResponse(page);
      await page.getByTestId('lnkup-publish-event-sidebar').click();
      const partial = await first;
      expect(partial.data.partial).toBe(true);
      const warning = page.getByTestId('lnkup-create-partial');
      await expect(warning).toBeVisible();
      await expect(warning).toContainText(/event was saved/i);

      await page.locator('#lnkup-create-event-form').evaluate((form) => form.removeAttribute('data-test-block-opp'));
      const second = nextCreateResponse(page);
      await warning.getByRole('button', { name: /retry vendor slots/i }).click();
      const retried = await second;
      expect(retried.success).toBe(true);
      expect(retried.data.event_id).toBe(partial.data.event_id);
      expect(retried.data.idempotent).toBe(true);
      expect(retried.data.opportunity_created).toBe(true);
    });
  });
});
