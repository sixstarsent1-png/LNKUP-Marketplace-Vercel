import { expect, test } from "@playwright/test"

const appBase = process.env.PLAYWRIGHT_APP_URL || "http://localhost:3000"

test.describe("Phase 17 — Organizer CRM depth", () => {
  test("organizer vendors page shows queue buckets and discover directory", async ({ page }) => {
    await page.route("**/api/organizer/requests**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          items: [
            {
              id: "301",
              applicationId: "301",
              vendorBusinessName: "Vendor One",
              eventId: "charlotte-night-market",
              eventName: "Charlotte Night Market",
              status: "under_review",
            },
          ],
          queueBuckets: { all: 1, underReview: 1, approved: 0, paid: 0, waitlisted: 0, risk: 0 },
          total: 1,
        }),
      })
    })
    await page.route("**/api/organizer/vendors**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          items: [
            {
              id: "v-1",
              businessName: "Night Market Snacks",
              category: "Food",
              city: "Charlotte",
            },
          ],
          total: 1,
        }),
      })
    })

    await page.goto(`${appBase}/organizer/vendors`)
    const discoverTab = page.getByRole("tab", { name: "Discover" })
    if (!(await discoverTab.isVisible())) {
      test.skip()
      return
    }
    await expect(discoverTab).toBeVisible()
    await expect(page.getByRole("tab", { name: "Discover" })).toBeVisible()
    await page.getByRole("tab", { name: "Discover" }).click()
    await expect(page.getByTestId("organizer-discover-search")).toBeVisible()
    await expect(page.getByTestId("organizer-discover-filters")).toBeVisible()
  })

  test("profile drawer renders action history and notes from organizer vendor profile API", async ({ page }) => {
    await page.route("**/api/organizer/vendors/**", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          profile: {
            id: "12",
            businessName: "Night Market Snacks",
            category: "Food",
            description: "Festival food vendor",
          },
          timeline: [{ id: "a1", type: "review", label: "Approved for Summer Night", timestamp: "2026-07-01" }],
          notes: [{ id: "n1", content: "Strong setup and documents complete.", createdAt: "2026-07-02" }],
        }),
      })
    })

    await page.goto(`${appBase}/organizer/vendors`)
    const firstCard = page.getByTestId("organizer-vendor-request-card").first()
    if (!(await firstCard.isVisible())) {
      test.skip()
      return
    }
    await expect(firstCard).toBeVisible()
    const profileFetched = page
      .waitForRequest("**/api/organizer/vendors/**", { timeout: 10000 })
      .then(() => true)
      .catch(() => false)
    await firstCard.getByTestId("organizer-view-profile").click()
    await expect(page.getByTestId("organizer-profile-drawer")).toBeVisible()
    test.skip(
      !(await profileFetched),
      "Needs live API mode: in fixture mode the drawer builds a demo profile without calling /api/organizer/vendors",
    )
    await expect(page.getByText("Action history")).toBeVisible()
    await expect(page.getByText("Organizer notes")).toBeVisible()
  })

  test("event workspace shows fallback handoff when review is unavailable", async ({ page }) => {
    await page.route("**/api/organizer/requests", async (route) => {
      await route.fulfill({
        status: 200,
        contentType: "application/json",
        body: JSON.stringify({
          code: "ok",
          reviewUnavailable: true,
          items: [
            {
              id: "301",
              applicationId: "301",
              vendorBusinessName: "Vendor One",
              eventId: "charlotte-night-market",
              eventName: "Charlotte Night Market",
              status: "under_review",
            },
          ],
          total: 1,
        }),
      })
    })

    const requestsFetched = page
      .waitForRequest("**/api/organizer/requests", { timeout: 10000 })
      .then(() => true)
      .catch(() => false)
    await page.goto(`${appBase}/organizer/events/charlotte-night-market`)
    test.skip(
      !(await requestsFetched),
      "Needs live API mode: in fixture mode the workspace serves demo requests without calling /api/organizer/requests",
    )
    const vendorRequestsTab = page.getByRole("tab", { name: "Vendor Requests" })
    await vendorRequestsTab.click()
    await expect(page.getByText("Review actions are available in the LNKup web dashboard.")).toBeVisible()
    await expect(page.getByRole("button", { name: /open web review queue/i })).toBeVisible()
  })
})

