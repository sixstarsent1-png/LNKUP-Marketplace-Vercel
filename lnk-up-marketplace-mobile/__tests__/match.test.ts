import { mapRecommendations } from "@/lib/api/match"

describe("vendor match recommendations", () => {
  it("treats a disabled engine as an empty, hidden section", () => {
    expect(mapRecommendations({ enabled: false, items: [] })).toEqual({ status: "disabled" })
  })

  it("asks for a profile before showing matches", () => {
    expect(mapRecommendations({ enabled: true, needs_profile: true })).toEqual({ status: "needs_profile" })
  })

  it("maps opportunities with bands and reasons, and tours as grouped cards", () => {
    const result = mapRecommendations({
      enabled: true,
      total: 2,
      items: [
        {
          opportunity_id: 501,
          event_id: 401,
          name: "Charlotte Food Fest",
          city: "Charlotte",
          state: "NC",
          vendor_fee: 150,
          spots_remaining: 4,
          total_spots: 10,
          categories: ["Food Vendor"],
          match: { band: "strong", band_label: "Strong match", reasons: ["Matches your Food Vendor category"] },
          more_dates: 2,
        },
        {
          kind: "tour",
          id: 9,
          title: "Southeast Tour",
          type: "tour",
          matching_stops: 3,
          places: ["Atlanta, GA", "Charlotte, NC", "Nashville, TN"],
          next_date: "2027-05-01",
          match: { band: "good", reasons: ["3 stops match your markets"] },
        },
      ],
    })
    expect(result.status).toBe("ready")
    if (result.status !== "ready") return
    const [opportunity, tour] = result.items
    expect(opportunity.kind).toBe("opportunity")
    if (opportunity.kind === "opportunity") {
      expect(opportunity.opportunity.opportunityId).toBe(501)
      expect(opportunity.match).toEqual({
        band: "strong",
        bandLabel: "Strong match",
        reasons: ["Matches your Food Vendor category"],
      })
      expect(opportunity.moreDates).toBe(2)
    }
    expect(tour).toMatchObject({ kind: "tour", id: 9, matchingStops: 3, reasons: ["3 stops match your markets"] })
  })

  it("never trusts unknown band values", () => {
    const result = mapRecommendations({
      enabled: true,
      items: [{ opportunity_id: 7, name: "X", match: { band: "perfect", reasons: [] } }],
    })
    expect(result.status === "ready" && result.items[0].kind === "opportunity" && result.items[0].match?.band).toBe("possible")
  })
})
