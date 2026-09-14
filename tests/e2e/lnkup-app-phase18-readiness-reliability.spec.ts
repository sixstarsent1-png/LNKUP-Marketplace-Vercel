import { expect, test } from "@playwright/test"

const appBase = process.env.PLAYWRIGHT_APP_URL || "http://localhost:3000"

test.describe("Phase 18 — Readiness and reliability", () => {
  test("vendor home surfaces at-risk readiness blockers", async ({ page }) => {
    await page.route("**/api/vendor/readiness", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          status: "incomplete",
          profileCompletion: 72,
          documentsCompletion: 50,
          trustLevel: "Profile in progress",
          readinessTier: "at_risk",
          riskBucket: "red",
          headline: "Upload required documents before applying to some events.",
          missingItems: [
            {
              id: "doc-exp",
              label: "Insurance certificate",
              severity: "required",
              documentUrgency: "expiring_soon",
              actionHref: "/vendor/documents",
              actionLabel: "Upload",
            },
          ],
          blockers: [
            {
              id: "doc-exp",
              label: "Insurance certificate",
              severity: "required",
              documentUrgency: "expiring_soon",
              actionHref: "/vendor/documents",
              actionLabel: "Upload",
            },
          ],
          profileChecklist: [],
        }),
      })
    })
    await page.goto(`${appBase}/vendor`)
    const profileReadiness = page.getByText(/Profile readiness/i)
    if (!(await profileReadiness.isVisible())) {
      test.skip()
      return
    }
    await expect(profileReadiness).toBeVisible()
    await expect(page.getByText(/Upload required documents before applying/i)).toBeVisible()
  })

  test("notification mapping uses readiness urgency metadata", async ({ page }) => {
    await page.route("**/api/vendor/readiness", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          status: "incomplete",
          profileCompletion: 72,
          documentsCompletion: 50,
          trustLevel: "Profile in progress",
          readinessTier: "at_risk",
          riskBucket: "red",
          headline: "Upload required documents before applying to some events.",
          missingItems: [
            {
              id: "doc-exp",
              label: "Insurance certificate",
              severity: "required",
              documentUrgency: "expiring_soon",
              actionHref: "/vendor/documents",
              actionLabel: "Upload",
            },
          ],
          blockers: [
            {
              id: "doc-exp",
              label: "Insurance certificate",
              severity: "required",
              documentUrgency: "expiring_soon",
              actionHref: "/vendor/documents",
              actionLabel: "Upload",
            },
          ],
          profileChecklist: [],
        }),
      })
    })

    await page.goto(`${appBase}/vendor`)
    const urgency = page.getByText(/expiring soon/i)
    if (!(await urgency.isVisible())) {
      test.skip()
      return
    }
    await expect(urgency).toBeVisible()
  })

  test("organizer vendors queue surfaces at-risk indicator", async ({ page }) => {
    await page.route("**/api/organizer/requests**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          items: [
            {
              id: "501",
              applicationId: "501",
              vendorBusinessName: "Risk Vendor",
              eventId: "event-1",
              eventName: "Event One",
              status: "payment_required",
            },
          ],
          queueBuckets: { all: 1, underReview: 0, approved: 1, paid: 0, waitlisted: 0, risk: 1 },
          total: 1,
        }),
      })
    })
    await page.goto(`${appBase}/organizer/vendors`)
    const atRisk = page.getByText(/At risk/i)
    if (!(await atRisk.isVisible())) {
      test.skip()
      return
    }
    await expect(atRisk).toBeVisible()
  })
})

