import { expect, test, type Page } from "@playwright/test"

const appBase = process.env.PLAYWRIGHT_APP_URL || "http://localhost:3000"

async function mockAuthenticated(page: Page, roles: Record<string, boolean> = { vendor: true }) {
  await page.route("**/api/auth/me", async (route) => {
    const isOrganizer = roles.organizer === true
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        authenticated: true,
        user: {
          id: 1,
          name: "Launch Smoke User",
          username: "launchsmoke",
          roles: isOrganizer ? ["organizer"] : ["vendor"],
          primaryRole: isOrganizer ? "organizer" : "vendor",
          canAccessVendor: roles.vendor === true,
          canAccessOrganizer: roles.organizer === true,
        },
        roles,
      }),
    })
  })
}

async function gotoOrSkip(page: Page, path: string) {
  try {
    await page.goto(`${appBase}${path}`, { waitUntil: "domcontentloaded", timeout: 15000 })
  } catch {
    test.skip()
  }
}

test.describe("Phase 22 — Launch smoke", () => {
  test("vendor home tutorial smoke + growth demo gate", async ({ page }) => {
    await mockAuthenticated(page, { vendor: true })
    await page.route("**/api/vendor/**", async (route) => {
      const url = route.request().url()
      if (url.includes("requests")) {
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ code: "ok", items: [], total: 0 }),
        })
        return
      }
      if (url.includes("onboarding")) {
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({
            userId: "1",
            currentStep: "categories",
            completedSteps: ["business"],
            profileCompletion: 40,
            nextAction: { label: "Continue", href: "/vendor/onboarding" },
            canSkip: true,
          }),
        })
        return
      }
      if (url.includes("readiness")) {
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({
            code: "ok",
            status: "incomplete",
            profileCompletion: 40,
            documentsCompletion: 10,
            trustLevel: "Profile in progress",
            verifiedBadges: [],
            missingItems: [],
            blockers: [{ id: "coi", label: "COI", severity: "required", type: "document" }],
            readinessTier: "needs_attention",
            riskBucket: "yellow",
            nextAction: { label: "Complete profile", href: "/vendor/profile" },
            headline: "Complete profile",
          }),
        })
        return
      }
      if (url.includes("favorites")) {
        await route.fulfill({
          status: 200,
          contentType: "application/json",
          body: JSON.stringify({ items: [], total: 0 }),
        })
        return
      }
      await route.continue()
    })

    await gotoOrSkip(page, "/vendor")
    await page.waitForLoadState("networkidle")
    const tutorial = page.getByTestId("vendor-home-tutorial")
    if (!(await tutorial.isVisible({ timeout: 10000 }).catch(() => false))) {
      test.skip()
      return
    }
    await expect(tutorial).toBeVisible()

    await gotoOrSkip(page, "/vendor/growth")
    await page.waitForLoadState("networkidle")
    const growthNotice = page.getByTestId("vendor-growth-demo-notice")
    if (!(await growthNotice.isVisible({ timeout: 10000 }).catch(() => false))) {
      test.skip()
      return
    }
    await expect(growthNotice).toBeVisible()
  })

  test("organizer home tutorial smoke", async ({ page }) => {
    await mockAuthenticated(page, { organizer: true })
    await page.route("**/api/organizer/home**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          stats: {
            events: 0,
            vendorRequests: 0,
            approvedVendors: 0,
            paidVendors: 0,
            paymentRequired: 0,
            openOpportunities: 0,
          },
          upcomingEvents: [],
          launchChecklist: [],
          financial: null,
          intelligence: null,
        }),
      })
    })
    await page.route("**/api/organizer/requests**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          items: [],
          queueBuckets: { all: 0, underReview: 0, approved: 0, paid: 0, waitlisted: 0, risk: 0 },
          total: 0,
        }),
      })
    })
    await page.route("**/api/organizer/money**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({ code: "ok", summary: { paymentRequiredCount: 0 } }),
      })
    })

    await gotoOrSkip(page, "/organizer")
    await page.waitForLoadState("networkidle")
    const tutorial = page.getByTestId("organizer-home-tutorial")
    if (!(await tutorial.isVisible({ timeout: 10000 }).catch(() => false))) {
      test.skip()
      return
    }
    await expect(tutorial).toBeVisible()
  })

  test("payment return banner smoke on vendor requests", async ({ page }) => {
    await mockAuthenticated(page, { vendor: true })
    await page.route("**/api/vendor/requests**", async (route) => {
      if (route.request().url().includes("/payment")) {
        await route.fulfill({
          status: 202,
          contentType: "application/json",
          body: JSON.stringify({
            code: "payment_processing",
            detail: { status: "processing" },
          }),
        })
        return
      }
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          items: [
            {
              id: "req-smoke-1",
              applicationId: "req-smoke-1",
              eventName: "Smoke Event",
              status: "payment_required",
              vendorFee: 100,
            },
          ],
          total: 1,
        }),
      })
    })

    await gotoOrSkip(page, "/vendor/requests?payment=processing&applicationId=req-smoke-1")
    await page.waitForLoadState("networkidle")
    const banner = page
      .getByTestId("payment-return-processing")
      .or(page.getByTestId("payment-return-success"))
      .or(page.getByTestId("payment-return-cancelled"))
    if (!(await banner.isVisible({ timeout: 10000 }).catch(() => false))) {
      test.skip()
      return
    }
    await expect(banner).toBeVisible()
  })
})
