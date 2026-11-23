import {
  connectStateCopy,
  friendlyRequirement,
  mapConnectState,
  mapMoneyCategories,
  mapOrganizerPayouts,
} from "@/lib/api/organizer-financial"

describe("mapConnectState", () => {
  it("treats a marketplace without Connect as LNKup-paid and still allows cash out", () => {
    const state = mapConnectState({
      status: "not_started",
      available: false,
      code: "connect_not_configured",
      settlement_mode: "platform_treasury",
    })
    expect(state.kind).toBe("unavailable")
    expect(state.canCashout).toBe(true)
    expect(state.canOnboard).toBe(false)
    expect(connectStateCopy(state).badge).not.toMatch(/stripe|connect|settlement/i)
  })

  it("blocks destination cash out until the account is active", () => {
    const state = mapConnectState({ status: "not_started", available: true, settlement_mode: "destination" })
    expect(state.kind).toBe("not_started")
    expect(state.canCashout).toBe(false)
    expect(state.canOnboard).toBe(true)
  })

  it("surfaces past-due requirements in plain words", () => {
    const state = mapConnectState({
      status: "active",
      payouts_enabled: true,
      available: true,
      settlement_mode: "destination",
      requirements_past_due: ["external_account", "individual.verification.document"],
    })
    expect(state.kind).toBe("verification")
    expect(state.canCashout).toBe(false)
    expect(state.requirements).toEqual(["Bank account for payouts", "Photo ID"])
  })

  it("allows destination cash out when active with no past-due items", () => {
    const state = mapConnectState({ status: "active", payouts_enabled: true, settlement_mode: "destination" })
    expect(state.kind).toBe("active")
    expect(state.canCashout).toBe(true)
    expect(state.canOnboard).toBe(false)
  })
})

describe("friendlyRequirement", () => {
  it("softens unknown Stripe codes", () => {
    expect(friendlyRequirement("company.owners_provided")).toBe("Owners provided")
  })
})

describe("mapMoneyCategories", () => {
  it("keeps known server categories with hints and drops malformed rows", () => {
    const rows = mapMoneyCategories([
      { key: "available", label: "Available to cash out", amount: "40.5" },
      { key: "mystery", label: "Unknown", amount: 1 },
      { key: "refunds", label: "Refunds" },
    ])
    expect(rows).toEqual([{ key: "available", label: "Available to cash out", amount: 40.5, hint: undefined }])
  })

  it("never shows gross or platform fee rows, and labels net as Earnings", () => {
    const rows = mapMoneyCategories([
      { key: "gross", label: "Gross collected", amount: 120, hint: "Before fees and refunds." },
      { key: "fees", label: "Platform fees", amount: 6, hint: "LNKup fees on those bookings." },
      { key: "net", label: "Net earnings", amount: 114, hint: "Gross collected minus platform fees and refunds." },
    ])
    expect(rows).toEqual([
      { key: "net", label: "Earnings", amount: 114, hint: "What you've earned from paid booths, after refunds." },
    ])
  })
})

describe("mapOrganizerPayouts", () => {
  it("only lets requested or approved cash outs be cancelled", () => {
    const payouts = mapOrganizerPayouts([
      { id: 1, amount: 50, net_amount: 50, fee_amount: 0, payout_type: "standard", status: "requested" },
      { id: 2, amount: 20, status: "processing" },
      { id: 3, amount: 10, status: "failed", failure_reason: "Bank rejected the transfer." },
      { id: 4, amount: 5, status: "weird" },
    ])
    expect(payouts.map((p) => [p.id, p.status, p.canCancel])).toEqual([
      ["1", "requested", true],
      ["2", "processing", false],
      ["3", "failed", false],
      ["4", "unknown", false],
    ])
    expect(payouts[2].failureReason).toBe("Bank rejected the transfer.")
  })
})
