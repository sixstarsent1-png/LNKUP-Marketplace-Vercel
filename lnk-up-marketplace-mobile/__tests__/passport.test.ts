import { asPassportTab, mapMyPassport } from "@/lib/api/passport"
import { destinationScope, isDestinationAllowed } from "@/lib/navigation/pending-destination"

describe("mapMyPassport", () => {
  const raw = {
    profile_id: 9,
    business_name: "Demo Tacos",
    public_url: "https://example.test/vendors/demo-tacos/",
    is_public: true,
    categories: [{ id: 3, name: "Food Truck", is_primary: true }],
    strength: { score: 72, tier: "Good", note: "Profile completeness is not business verification." },
    reputation: {
      verification: { status: "verified", is_verified: true, label: "Business Identity Verified", explanation: "x" },
      experience: { confirmed_events: 4, distinct_cities: 2, distinct_organizers: 2, label: "4 confirmed events" },
      organizer_feedback: { count: 1, display_average: false, average: 5, label: "Not enough reviews yet" },
      document_readiness: { label: "1 reviewed document on file", current_types: [{ type: "coi", label: "Insurance" }] },
      achievements: [{ slug: "first_check_in", label: "First Verified Check-In", rule: "Checked in." }],
      is_new_vendor: false,
    },
    privacy: { public_profile: true, show_history: false, show_reviews: true, show_cities: true, show_portfolio: true, show_socials: true, hidden_event_ids: [5, "7"] },
    required_actions: [{ key: "verification", label: "Renew", detail: "", target: "verification" }, { key: "x", label: "Bad", detail: "", target: "nope" }],
    portfolio: [{ attachment_id: 11, url: "https://img/1.jpg", caption: "Booth", is_cover: true }],
    // The internal score must never surface even if a server sends it.
    score: 88,
  }

  it("maps indicators without the internal score", () => {
    const p = mapMyPassport(raw)
    expect(p.businessName).toBe("Demo Tacos")
    expect(p.indicators.verification.isVerified).toBe(true)
    expect(p.indicators.experience.confirmedEvents).toBe(4)
    expect(JSON.stringify(p)).not.toContain("88")
  })

  it("hides averages below the review minimum", () => {
    const p = mapMyPassport(raw)
    expect(p.indicators.feedback.displayAverage).toBe(false)
    expect(p.indicators.feedback.average).toBeNull()
    expect(p.indicators.feedback.label).toBe("Not enough reviews yet")
  })

  it("normalizes privacy and action targets", () => {
    const p = mapMyPassport(raw)
    expect(p.privacy.showHistory).toBe(false)
    expect(p.privacy.hiddenEventIds).toEqual([5, 7])
    expect(p.requiredActions.map((a) => a.target)).toEqual(["verification", "overview"])
    expect(p.portfolio[0].thumb).toBe("https://img/1.jpg")
  })
})

describe("Passport routing", () => {
  it("accepts known tabs only", () => {
    expect(asPassportTab("reviews")).toBe("reviews")
    expect(asPassportTab("admin")).toBe("overview")
    expect(asPassportTab(undefined)).toBe("overview")
  })

  it("allows notification deep links to the vendor Passport", () => {
    expect(destinationScope("/(vendor)/passport?tab=reviews")).toBe("vendor")
    expect(isDestinationAllowed("/(vendor)/passport", ["lnkup_organizer"])).toBe(false)
  })
})
