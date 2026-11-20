import { applyLiveRequestCounts, type OrganizerHomeSummary } from "@/lib/api/organizer-home"
import { decisionLabel, experimentStatusLabel, humanizeKey, segmentLabel } from "@/lib/format/labels"

function summary(paymentRequired: number): OrganizerHomeSummary {
  return {
    stats: { events: 2, openOpportunities: 3, vendorRequests: 9, approvedVendors: 5, paymentRequired, paidVendors: 3 },
    attentionItems: [{ id: "payment-required", label: `${paymentRequired} approved vendors still need to pay` }],
    launchChecklist: [],
    intelligence: { diagnostics: [], experiments: [], recommendations: [] },
    wordpressUrl: "",
  }
}

describe("organizer home counts", () => {
  it("uses live application statuses instead of cached approved − paid arithmetic", () => {
    const next = applyLiveRequestCounts(summary(2), [
      { status: "submitted" },
      { status: "under_review" },
      { status: "payment_required" },
      { status: "paid" },
      { status: "rejected" },
    ])
    expect(next.stats.vendorRequests).toBe(2)
    expect(next.stats.paymentRequired).toBe(1)
    expect(next.stats.paidVendors).toBe(1)
    expect(next.attentionItems.map((i) => i.id)).toEqual(["pending-requests", "payment-required"])
  })

  it("drops stale attention items when nothing is pending", () => {
    const next = applyLiveRequestCounts(summary(2), [{ status: "paid" }])
    expect(next.stats.paymentRequired).toBe(0)
    expect(next.attentionItems).toEqual([])
  })
})

describe("insight labels", () => {
  it("never shows raw internal keys", () => {
    expect(humanizeKey("apply_clicked")).toBe("Apply clicked")
    expect(experimentStatusLabel("active")).toBe("Running")
    expect(decisionLabel("iterate")).toBe("Adjust and retest")
    expect(segmentLabel("city", "charlotte")).toBe("City: Charlotte")
  })
})
