import { test, expect, devices, type APIResponse } from '@playwright/test';
import { loginAs, loginPage, lnkupRest, type WpSession } from './helpers/wp-session';

const vendorUser = process.env.PLAYWRIGHT_VENDOR_B_USER ?? '';
const password = process.env.PLAYWRIGHT_PASSWORD ?? '';
const draftOpportunity = process.env.PLAYWRIGHT_DRAFT_OPPORTUNITY_ID ?? '';

/**
 * Submit the vendor's open draft for an opportunity with an Idempotency-Key header.
 */
function submitDraft(session: WpSession, opportunityId: string, key: string, body: Record<string, unknown>): Promise<APIResponse> {
  return session.request.fetch(`/wp-json/lnkup/v1/applications/drafts/${opportunityId}/submit`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': session.nonce, 'Content-Type': 'application/json', 'Idempotency-Key': key },
    data: body,
  });
}

test.describe('Application drafts: save on one device, resume on another', () => {
  test.skip(!vendorUser || !password || !draftOpportunity, 'Run scripts/e2e/seed.php');
  test.describe.configure({ mode: 'serial' });

  let desktop: WpSession;

  test.beforeAll(async ({ browser }) => {
    desktop = await loginAs(browser, vendorUser, password, '/opportunities/');
    await lnkupRest(desktop, 'DELETE', `/applications/drafts/${draftOpportunity}`);
    const apps = await lnkupRest(desktop, 'GET', '/applications/vendor');
    const items = (await apps.json()).items as Array<{ application_id: number; opportunity_id: number; status: string }>;
    for (const app of items.filter((a) => String(a.opportunity_id) === draftOpportunity && a.status === 'submitted')) {
      await lnkupRest(desktop, 'POST', `/applications/${app.application_id}/withdraw`, {});
    }
  });

  test.afterAll(async () => {
    await desktop?.context.close();
  });

  test('autosave creates a server draft and rejects stale revisions', async () => {
    const saved = await lnkupRest(desktop, 'PUT', `/applications/drafts/${draftOpportunity}`, {
      step: 'business',
      fields: { application_message: 'Saved from my laptop', selected_category: 'Food Vendor' },
      client: 'web',
    });
    expect(saved.status()).toBe(200);
    const draft = (await saved.json()).draft;
    expect(draft.fields.application_message).toBe('Saved from my laptop');
    expect(draft.price.quoted).toBeGreaterThan(0);

    const stale = await lnkupRest(desktop, 'PUT', `/applications/drafts/${draftOpportunity}`, {
      step: 'review',
      revision: draft.revision - 1,
    });
    expect(stale.status()).toBe(409);
    expect((await stale.json()).code).toBe('lnkup_draft_conflict');

    const list = await lnkupRest(desktop, 'GET', '/applications/drafts');
    const items = (await list.json()).items as Array<{ opportunity_id: number }>;
    expect(items.map((d) => String(d.opportunity_id))).toContain(draftOpportunity);
  });

  test('server-computed price estimate is available before approval', async () => {
    const quote = await lnkupRest(desktop, 'GET', `/applications/price-quote/${draftOpportunity}`);
    expect(quote.status()).toBe(200);
    const body = await quote.json();
    expect(body.is_estimate).toBe(true);
    expect(body.lines[0].key).toBe('booth');
    expect(body.total).toBeGreaterThanOrEqual(body.lines[0].amount);
  });

  test('a phone resumes the saved application and submits it once', async ({ browser }) => {
    const phone = await browser.newContext({ ...devices['iPhone 13'] });
    const page = await phone.newPage();
    await loginPage(page, vendorUser, password);
    await page.goto(`/opportunities/?apply=${draftOpportunity}&resume=1`);
    const wizard = page.getByTestId('lnkup-apply-wizard');
    await expect(page.getByTestId('lnkup-apply-modal')).toBeVisible({ timeout: 15000 });
    test.skip((await wizard.count()) === 0, 'apply_wizard_enabled is off in this environment');
    await expect(page.getByTestId('lnkup-apply-wizard-resume')).toBeVisible();
    await expect(page.getByTestId('lnkup-apply-wizard-quote')).not.toContainText('Loading');

    const next = page.getByTestId('lnkup-apply-wizard-next');
    while ((await next.count()) > 0 && (await next.isVisible())) {
      await next.click();
    }
    const review = page.getByTestId('lnkup-apply-wizard-review');
    if ((await review.count()) > 0) {
      await expect(review).toContainText('Saved from my laptop');
    } else {
      await expect(wizard.locator('textarea[name="application_message"]')).toHaveValue('Saved from my laptop');
    }
    await wizard.getByRole('checkbox').last().check();
    await page.getByTestId('lnkup-apply-submit').click();
    await expect(page.locator('#lnkup-apply-success')).toBeVisible({ timeout: 15000 });
    await phone.close();
  });

  test('replaying the submit does not create a second application', async () => {
    const replay = await submitDraft(desktop, draftOpportunity, 'replay-key-123456', { accepted_terms: true });
    expect(replay.status()).toBe(200);
    const body = await replay.json();
    expect(body.replayed).toBe(true);

    const apps = await lnkupRest(desktop, 'GET', '/applications/vendor');
    const items = (await apps.json()).items as Array<{ opportunity_id: number; status: string }>;
    expect(items.filter((a) => String(a.opportunity_id) === draftOpportunity && a.status !== 'cancelled')).toHaveLength(1);
  });

  test('submit without an Idempotency-Key is refused', async () => {
    const res = await lnkupRest(desktop, 'POST', `/applications/drafts/${draftOpportunity}/submit`, { accepted_terms: true });
    expect(res.status()).toBe(400);
    expect((await res.json()).code).toBe('lnkup_idempotency_key_required');
  });
});
