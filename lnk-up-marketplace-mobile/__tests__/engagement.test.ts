import { mapFulfillment, openCategoryLabels, ORGANIZER_VENDOR_SECTIONS } from "@/lib/api/match"
import { mapVendorInvite } from "@/lib/api/vendor-invites"

describe("vendor invitations", () => {
  it("maps server rows and treats unknown statuses as expired", () => {
    const invite = mapVendorInvite({ id: 7, status: "opened", event_title: "Fest", city: "Charlotte", state: "NC", can_respond: true, opportunity_id: 3 })
    expect(invite).toMatchObject({ id: 7, status: "opened", eventTitle: "Fest", canRespond: true, opportunityId: 3 })
    expect(mapVendorInvite({ id: 8, status: "weird" }).status).toBe("expired")
    expect(mapVendorInvite({ id: 9 }).canRespond).toBe(false)
  })
})

describe("event fulfillment", () => {
  const raw = {
    event_id: 5,
    event_title: "Sunset & Soul",
    days_to_deadline: 3,
    totals: { capacity: 10, held: 4, paid: 3, awaiting_payment: 1, remaining: 6, unlimited: 0, open_opportunities: 2, pending_review: 2 },
    categories: [
      { key: "food", label: "Food", remaining: 4, unlimited: false, open: true },
      { key: "art", label: "Art", remaining: 0, unlimited: false, open: true },
      { key: "music", label: "Music", remaining: 0, unlimited: true, open: true },
      { key: "_any", label: "Any category", remaining: 2, unlimited: false, open: true },
      { key: "closed", label: "Closed", remaining: 5, unlimited: false, open: false },
    ],
    invites: { total: 4, applications: 1, by_status: { accepted: 2, declined: 1 } },
    matching_vendors: 12,
    actions: [{ key: "review_applications", label: "2 applications are awaiting your review.", cta: "Review applications", view: "applications" }],
  }

  it("maps totals, invites and actions", () => {
    const f = mapFulfillment(raw)
    expect(f).toMatchObject({ eventId: 5, remaining: 6, held: 4, pendingReview: 2, matchingVendors: 12, daysToDeadline: 3 })
    expect(f.invites).toEqual({ total: 4, accepted: 2, declined: 1, applications: 1 })
    expect(f.actions[0].view).toBe("applications")
  })

  it("lists only open categories with room, never the catch-all bucket", () => {
    expect(openCategoryLabels(mapFulfillment(raw))).toEqual(["4 Food", "Music"])
  })

  it("survives an empty payload", () => {
    const f = mapFulfillment({})
    expect(f.remaining).toBe(0)
    expect(f.actions).toEqual([])
  })
})

describe("organizer Find Vendors sections", () => {
  it("matches the server section keys", () => {
    expect(ORGANIZER_VENDOR_SECTIONS).toEqual(["recommended", "new_nearby", "previously_booked", "saved", "recently_viewed"])
  })
})
