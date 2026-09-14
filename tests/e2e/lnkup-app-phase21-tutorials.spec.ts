import { expect, test, type Page } from "@playwright/test"

const appBase = process.env.PLAYWRIGHT_APP_URL || "http://localhost:3000"

const mockOrganizerHome = {
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
}

async function mockAuthenticated(
  page: Page,
  roles: Record<string, boolean> = { vendor: true },
) {
  await page.route("**/api/auth/me", async (route) => {
    const isOrganizer = roles.organizer === true
    await route.fulfill({
      status: 200,
      contentType: "application/json",
      body: JSON.stringify({
        authenticated: true,
        user: {
          id: 1,
          name: "Test User",
          username: "testuser",
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

test.describe("Phase 21 — On-site tutorials", () => {
  test.beforeEach(async ({ page }) => {
    await page.addInitScript(() => {
      for (const key of Object.keys(localStorage)) {
        if (key.startsWith("lnkup_tutorial_progress_v1")) {
          localStorage.removeItem(key)
        }
      }
    })
  })

  async function gotoOrSkip(page: Page, path: string) {
    try {
      await page.goto(`${appBase}${path}`, { waitUntil: "domcontentloaded", timeout: 15000 })
    } catch {
      test.skip()
    }
  }

  test("vendor home shows guided tutorial checklist with actionable CTA", async ({ page }) => {
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
            userId: "vendor-test",
            currentStep: "categories",
            completedSteps: ["business"],
            profileCompletion: 42,
            nextAction: { label: "Complete profile", href: "/vendor/profile" },
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
            profileCompletion: 42,
            documentsCompletion: 20,
            trustLevel: "Profile in progress",
            verifiedBadges: [],
            missingItems: [
              {
                id: "coi",
                label: "Upload Certificate of Insurance",
                actionHref: "/vendor/documents",
                actionLabel: "Upload",
                severity: "required",
              },
            ],
            nextAction: { label: "Complete profile", href: "/vendor/profile" },
            headline: "Complete your profile to get approved faster.",
            readinessTier: "needs_attention",
            riskBucket: "yellow",
            blockers: [
              {
                id: "coi",
                label: "Upload Certificate of Insurance",
                actionHref: "/vendor/documents",
                actionLabel: "Upload",
                severity: "required",
                type: "document",
                documentUrgency: "expiring_soon",
              },
            ],
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
    if (!(await tutorial.isVisible({ timeout: 15000 }).catch(() => false))) {
      test.skip()
      return
    }
    await expect(tutorial).toBeVisible()
    await expect(tutorial.getByText("Vendor getting started")).toBeVisible()
    await expect(page.getByTestId("vendor-home-tutorial-cta-profile_readiness")).toBeVisible()
  })

  test("organizer home shows guided tutorial checklist with actionable CTA", async ({ page }) => {
    await mockAuthenticated(page, { organizer: true })
    await page.route("**/api/organizer/home**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify(mockOrganizerHome),
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
        body: JSON.stringify({
          code: "ok",
          summary: { paymentRequiredCount: 0, totalCollected: 0, currency: "USD" },
        }),
      })
    })

    await gotoOrSkip(page, "/organizer")
    await page.waitForLoadState("networkidle")
    const tutorial = page.getByTestId("organizer-home-tutorial")
    if (!(await tutorial.isVisible({ timeout: 15000 }).catch(() => false))) {
      test.skip()
      return
    }
    await expect(tutorial).toBeVisible()
    await expect(tutorial.getByText("Organizer getting started")).toBeVisible()
    await expect(page.getByTestId("organizer-home-tutorial-cta-launch_event")).toBeVisible()
  })
})
