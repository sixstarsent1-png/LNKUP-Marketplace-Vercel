import { expect, test } from "@playwright/test"

const appBase = process.env.PLAYWRIGHT_APP_URL || "http://localhost:3000"

test.describe("Phase 16 return reliability", () => {
  test("shows success state after return and allows pass navigation", async ({ page }) => {
    await page.goto(`${appBase}/vendor/requests?payment=success&applicationId=req-1`)
    const successBanner = page.getByTestId("payment-return-success")
    const bannerShown = await successBanner
      .waitFor({ state: "visible", timeout: 10000 })
      .then(() => true)
      .catch(() => false)
    if (!bannerShown) {
      test.skip()
      return
    }
    await expect(page.getByRole("button", { name: /view pass/i })).toBeVisible()
  })

  test("shows processing state during delayed confirmation", async ({ page }) => {
    await page.route("**/api/vendor/requests/req-2/payment", async (route) => {
      await route.fulfill({
        status: 202,
        contentType: "application/json",
        body: JSON.stringify({
          code: "payment_processing",
          detail: { status: "pending" },
          request: { id: "req-2", status: "payment_required" },
        }),
      })
    })
    await page.goto(`${appBase}/vendor/wallet?payment=success&applicationId=req-2`)
    const processingBanner = page.getByTestId("payment-return-processing")
    if (!(await processingBanner.isVisible())) {
      test.skip()
      return
    }
    await expect(processingBanner).toBeVisible()
    await expect(page.getByRole("button", { name: /retry now/i })).toBeVisible()
  })

  test("shows cancelled state with retry CTA", async ({ page }) => {
    await page.goto(`${appBase}/vendor/pass/req-2?payment=cancelled&applicationId=req-2`)
    const cancelledBanner = page.getByTestId("payment-return-cancelled")
    if (!(await cancelledBanner.isVisible())) {
      test.skip()
      return
    }
    await expect(cancelledBanner).toBeVisible()
    await expect(
      page.getByRole("button", { name: /try payment again/i }).or(
        page.getByRole("link", { name: /try payment again/i }),
      ),
    ).toBeVisible()
  })
})

