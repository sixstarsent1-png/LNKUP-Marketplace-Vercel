import { contractDollars, mapVendorWalletSummary } from "../lib/api/vendor-wallet"
import type { VendorRequest } from "../lib/api/vendor-requests"

const metric = (cents: number, status = "ok") => ({ cents, status, count: 1, source: "wordpress_payments" })

const dashboard = (metrics: Record<string, unknown>) => ({ money: { version: 1, currency: "usd", metrics } })

const dueRequest = { status: "payment_required", amountDue: 999, paymentId: "1" } as unknown as VendorRequest

describe("vendor wallet totals", () => {
  it("uses the server money contract instead of adding up requests", () => {
    const summary = mapVendorWalletSummary(
      {},
      dashboard({ paid: metric(25000), outstanding: metric(4550), processing: metric(0), refunded: metric(0) }),
      [dueRequest],
    )
    expect(summary.totalPaid).toBe(250)
    expect(summary.amountDue).toBe(45.5)
    expect(summary.paymentRequiredCount).toBe(1)
  })

  it("shows a confirmed $0 as zero", () => {
    const summary = mapVendorWalletSummary({}, dashboard({ paid: metric(0), outstanding: metric(0) }), [])
    expect(summary.totalPaid).toBe(0)
    expect(summary.amountDue).toBe(0)
  })

  it("leaves a metric the server did not confirm unavailable", () => {
    const summary = mapVendorWalletSummary({}, dashboard({ paid: metric(1000, "unavailable") }), [dueRequest])
    expect(summary.totalPaid).toBeUndefined()
    expect(summary.amountDue).toBeUndefined()
  })

  it("never invents $0 when an older server sends nothing", () => {
    const summary = mapVendorWalletSummary(null, null, [dueRequest])
    expect(summary.totalPaid).toBeUndefined()
    expect(summary.amountDue).toBeUndefined()
    expect(summary.availableCredits).toBeUndefined()
  })

  it("falls back to an older server's flat fields", () => {
    const summary = mapVendorWalletSummary({ outstanding: 12, total_paid: 0 }, {}, [])
    expect(summary.amountDue).toBe(12)
    expect(summary.totalPaid).toBe(0)
  })

  it("rejects non-numeric cents", () => {
    expect(contractDollars({ cents: "100", status: "ok" })).toBeUndefined()
    expect(contractDollars(undefined)).toBeUndefined()
  })
})
