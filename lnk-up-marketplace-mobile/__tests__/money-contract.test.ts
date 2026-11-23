import contract from "../../scripts/e2e/fixtures/money-contract-v1.json"
import { cashOutReasonCopy, mapOrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"

const legacyCategories = [
  { key: "gross", label: "Gross collected", amount: 9999, hint: "legacy" },
  { key: "available", label: "Available to cash out", amount: 0, hint: "legacy" },
  { key: "pending", label: "Pending", amount: 0, hint: "legacy" },
  { key: "paid_out", label: "Paid out", amount: 0, hint: "legacy" },
]

function payload(money: unknown) {
  return {
    money,
    money_categories: legacyCategories,
    display_balances: { available: 0, pending: 0, settlement_mode: "destination" },
    connect: { status: "active", payouts_enabled: true, available: true, settlement_mode: "destination" },
  }
}

describe("money contract v1 (shared fixture with the WordPress contract test)", () => {
  const summary = mapOrganizerFinancialSnapshot(payload(contract))

  it("uses contract cents for figures WordPress could read", () => {
    expect(summary.collectedToDate).toBe(2509.72)
    expect(summary.connectSettlement).toBe(920)
    expect(summary.categories.find((c) => c.key === "gross")).toBeUndefined()
    expect(summary.categories.find((c) => c.key === "fees")).toBeUndefined()
    expect(summary.categories.find((c) => c.key === "paid_out")?.amount).toBe(920)
  })

  it("shows an unreadable Stripe balance as unavailable, never $0", () => {
    expect(summary.availableBalance).toBeUndefined()
    expect(summary.pendingBalance).toBeUndefined()
    expect(summary.balanceUnavailable).toBe(true)
    const available = summary.categories.find((c) => c.key === "available")
    expect(available?.unavailable).toBe(true)
  })

  it("keeps payouts active but explains why cash out is blocked", () => {
    expect(summary.payoutStatus).toBe("active")
    expect(summary.connect.canCashout).toBe(true)
    expect(summary.cashOutReason).toBe("balance_unavailable")
    expect(cashOutReasonCopy(summary.cashOutReason)).toMatch(/couldn’t read your payout balance/i)
  })

  it("falls back to legacy fields when the contract is absent", () => {
    const legacy = mapOrganizerFinancialSnapshot({ ...payload(undefined), money: undefined, collected: 12.5 })
    expect(legacy.collectedToDate).toBe(12.5)
    expect(legacy.availableBalance).toBe(0)
    expect(legacy.balanceUnavailable).toBeUndefined()
  })

  it("blocks cash out when destination payouts are not active", () => {
    const blocked = mapOrganizerFinancialSnapshot(
      payload({
        ...contract,
        payouts: { ...contract.payouts, state: "action_required", can_cash_out: false, cash_out_reason: "payouts_not_active" },
      }),
    )
    expect(blocked.connect.canCashout).toBe(false)
    expect(blocked.payoutStatus).toBe("setup_required")
  })
})
