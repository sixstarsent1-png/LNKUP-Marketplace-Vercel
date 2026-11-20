import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { opportunityApplyCta } from "@/lib/vendor/opportunity-cta"

const TODAY = "2026-11-16"

function opp(extra: Partial<MarketplaceOpportunity> = {}): MarketplaceOpportunity {
  return {
    id: "opp-1",
    opportunityId: 1,
    title: "Market",
    categories: [],
    vendorFee: 100,
    expectedAttendance: 0,
    spotsRemaining: 3,
    spotsTotal: 10,
    organizerVerified: false,
    trending: false,
    eventDateIso: "2026-12-01",
    ...extra,
  }
}

describe("opportunity apply state", () => {
  it("lets a vendor apply to an open listing", () => {
    expect(opportunityApplyCta(opp(), TODAY)).toEqual({ label: "Apply", canApply: true })
  })

  it("never offers Apply after the vendor has applied, including with an open question", () => {
    expect(opportunityApplyCta(opp({ applicationStatus: "info_requested" }), TODAY).reason).toBe("applied")
    expect(opportunityApplyCta(opp({ applicationStatus: "paid" }), TODAY).reason).toBe("applied")
  })

  it("closes listings for past events and passed deadlines", () => {
    expect(opportunityApplyCta(opp({ eventDateIso: "2026-11-01" }), TODAY).reason).toBe("ended")
    expect(opportunityApplyCta(opp({ deadlineIso: "2026-11-10" }), TODAY).reason).toBe("deadline")
  })

  it("marks sold-out listings as full", () => {
    expect(opportunityApplyCta(opp({ spotsRemaining: 0 }), TODAY).reason).toBe("full")
  })
})
