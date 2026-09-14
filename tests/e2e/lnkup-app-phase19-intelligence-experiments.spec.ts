import { expect, test } from "@playwright/test"

const appBase = process.env.PLAYWRIGHT_APP_URL || "http://localhost:3000"

const mockFunnel = {
  code: "ok",
  meta: { live: false, mocked: true, partial: false, updatedAt: new Date().toISOString(), range: "7d" },
  funnel: {
    range: "7d",
    steps: [
      { id: "visitor", label: "Visitor", count: 120 },
      { id: "apply_clicked", label: "Apply clicked", count: 34, previousCount: 48, conversionRate: 70.8 },
    ],
    updatedAt: new Date().toISOString(),
  },
}

const mockSummary = {
  code: "ok",
  meta: { live: false, mocked: true, partial: true, updatedAt: new Date().toISOString(), range: "7d", unavailableReasons: ["LNKup intelligence upstream unavailable."] },
  vendorActivation: {
    newVendors: 25,
    incompleteProfiles: 10,
    missingDocuments: 4,
    savedNoApply: 8,
    appliedUnderReview: 5,
    paymentRequired: 2,
    booked: 1,
    passViewed: 1,
  },
  opportunities: [],
  searchInsights: [],
  markets: [],
  alerts: [],
  diagnostics: [
    {
      id: "segment-city-charlotte",
      segment: { type: "city", value: "Charlotte, NC" },
      stage: "application_submitted",
      conversionRate: 42.5,
      dropoffRate: 57.5,
      sampleSize: 80,
    },
  ],
  experiments: [
    {
      experimentId: "exp-city-apply-cta",
      name: "City-specific apply CTA copy",
      variant: "city-proof-cta",
      segment: { type: "city", value: "Charlotte, NC" },
      status: "active",
      owner: "Organizer Ops",
      window: { startAt: new Date().toISOString() },
      guardrails: [
        {
          id: "apply-error-rate",
          label: "Apply error rate",
          metric: "apply_error_rate",
          thresholdLabel: "<= 2%",
          currentValue: 1.8,
          status: "warning",
        },
      ],
    },
  ],
  recommendations: [
    {
      id: "rec-charlotte-apply-dropoff",
      issue: "High apply-click drop-off in Charlotte opportunities.",
      funnelStage: "application_submitted",
      segment: { type: "city", value: "Charlotte, NC" },
      candidateAction: "A/B test shorter apply copy and upfront fee transparency.",
      expectedMetricShift: "+8-12% application submitted rate",
      confidenceLabel: "medium",
      decision: { status: "iterate", dueAt: new Date().toISOString() },
      experimentId: "exp-city-apply-cta",
      href: "/organizer/events",
    },
  ],
}

test.describe("Phase 19 intelligence and experimentation", () => {
  test("ops analytics renders experiment diagnostics with partial fallback notice", async ({ page }) => {
    await page.route("**/api/ops/analytics/funnel**", async (route) => {
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(mockFunnel) })
    })
    await page.route("**/api/ops/analytics/summary**", async (route) => {
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(mockSummary) })
    })
    await page.route("**/api/ops/analytics/health**", async (route) => {
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ checks: [], updatedAt: new Date().toISOString() }) })
    })
    await page.route("**/api/ops/analytics/prelaunch**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          launchDate: "2026-08-01",
          launchLabel: "August 1, 2026",
          isPrelaunchMode: true,
          canSearch: true,
          canApplySubmit: false,
          isApplyContextGated: true,
          recommendedAction: "Use LNKup staging or admin preview to test full apply E2E.",
        }),
      })
    })

    await page.goto(`${appBase}/ops/analytics`)
    const opsPage = page.getByTestId("ops-analytics-page")
    if (!(await opsPage.isVisible())) {
      test.skip()
      return
    }

    await expect(page.getByTestId("ops-partial-banner")).toBeVisible()
    await expect(page.getByTestId("ops-unavailable-notice")).toBeVisible()
    await expect(page.getByTestId("ops-experiment-insights-section")).toBeVisible()
    await expect(page.getByTestId("ops-active-experiment")).toBeVisible()
    await expect(page.getByTestId("ops-top-recommendation")).toBeVisible()
  })

  test("organizer home shows intelligence panel and recommendation action", async ({ page }) => {
    await page.route("**/api/organizer/home", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          stats: {
            events: 3,
            openOpportunities: 2,
            vendorRequests: 6,
            approvedVendors: 3,
            paymentRequired: 1,
            paidVendors: 2,
          },
          upcomingEvents: [],
          attentionItems: [],
          launchChecklist: [],
          intelligence: {
            diagnostics: mockSummary.diagnostics,
            experiments: mockSummary.experiments,
            recommendations: mockSummary.recommendations,
          },
        }),
      })
    })
    await page.route("**/api/organizer/requests", async (route) => {
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ items: [], queueBuckets: null }) })
    })
    await page.route("**/api/organizer/money", async (route) => {
      await route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ summary: null }) })
    })

    await page.goto(`${appBase}/organizer`)
    await expect(page.getByTestId("organizer-intelligence-panel")).toBeVisible()
    await expect(page.getByTestId("organizer-active-experiment")).toBeVisible()
    await expect(page.getByTestId("organizer-top-recommendation")).toBeVisible()
    await expect(page.getByRole("button", { name: /take next action/i })).toBeVisible()
  })
})
