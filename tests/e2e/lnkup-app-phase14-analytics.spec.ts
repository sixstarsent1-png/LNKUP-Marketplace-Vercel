import { test, expect, type Page } from '@playwright/test';

const appBase = process.env.PLAYWRIGHT_APP_URL || 'http://localhost:3000';

type AnalyticsBody = {
  event?: string;
  source?: string;
  requestId?: string;
  opportunityId?: string;
  eventId?: string;
};

async function captureAnalytics(page: Page) {
  const events: AnalyticsBody[] = [];
  page.on('request', (request) => {
    if (!request.url().includes('/api/analytics/track')) return;
    try {
      const body = request.postDataJSON() as AnalyticsBody;
      events.push(body);
    } catch {
      // ignore malformed payloads
    }
  });
  return events;
}

function expectEventWithShape(
  events: AnalyticsBody[],
  eventName: string,
  required: Array<keyof AnalyticsBody>,
) {
  const payload = events.find((entry) => entry.event === eventName);
  expect(payload, `missing analytics event ${eventName}`).toBeTruthy();
  for (const field of required) {
    expect(payload?.[field], `missing ${String(field)} for ${eventName}`).toBeTruthy();
  }
}

test.describe('Phase 14 analytics verification', () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      // Avoid clipboard permission flakiness in headless runs.
      Object.assign(navigator, {
        clipboard: {
          writeText: async () => undefined,
        },
      });
    });
  });

  test('tracks deep_opportunity_opened', async ({ page }) => {
    const events = await captureAnalytics(page);
    await page.goto(`${appBase}/vendor/find?opportunityId=charlotte-night-market`);
    await page.waitForTimeout(500);
    expectEventWithShape(events, 'deep_opportunity_opened', ['source', 'opportunityId']);
  });

  test('tracks share_link_copied', async ({ page }) => {
    const events = await captureAnalytics(page);
    await page.goto(`${appBase}/organizer/events/charlotte-night-market`);
    await page.getByTestId('copy-application-link').click();
    await page.waitForTimeout(500);
    expectEventWithShape(events, 'share_link_copied', ['source', 'eventId', 'opportunityId']);
  });

  test('tracks payment return landed and confirmed', async ({ page }) => {
    const events = await captureAnalytics(page);
    await page.goto(`${appBase}/vendor/requests?applicationId=req-1&payment=success`);
    await page.waitForTimeout(1000);
    expectEventWithShape(events, 'payment_return_landed', ['source', 'requestId']);
    expectEventWithShape(events, 'payment_return_confirmed', ['source', 'requestId']);
  });
});
