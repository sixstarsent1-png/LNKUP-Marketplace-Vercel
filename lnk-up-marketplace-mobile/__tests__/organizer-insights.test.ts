import { mapOrganizerHomeSummary } from "@/lib/api/organizer-home"
import { mapOrganizerEventHighlights, mapOrganizerRecommendedActions } from "@/lib/api/organizer-intelligence"

/** Shape returned by GET /intelligence/organizer/summary (LNKup_Organizer_Intelligence::summary). */
const intel = {
  total_events: 3,
  active_opportunities: 4,
  approved_vendors: 5,
  paid_vendors: 3,
  top_events: [
    { event_id: 11, title: "Sunset & Soul R&B Festival", score: 88, label: "Strong" },
    { event_id: 12, title: "Greek Picnic", score: 52, label: "At risk" },
  ],
  weak_events: [
    { event_id: 12, title: "Greek Picnic", score: 52, label: "At risk" },
    { event_id: 11, title: "Sunset & Soul R&B Festival", score: 88, label: "Strong" },
  ],
  recommended_actions: [
    {
      priority: "high",
      action_key: "send_payment_reminder",
      title: "Remind unpaid vendors",
      reason: "3 approved vendors haven't paid.",
      deadline: "2027-05-01 12:00:00",
      cta: { label: "Send reminder", url: "https://thelnkup.com/organizer-dashboard/?event=12#comms" },
    },
    {
      priority: "low",
      action_key: "approve_waitlisted",
      title: "Approve waitlisted vendors",
      reason: "2 vendors are waitlisted.",
      deadline: null,
      cta: { label: "Review apps", url: "https://thelnkup.com/organizer-dashboard/?view=applications" },
    },
  ],
}

describe("organizer home insights", () => {
  it("shows server recommended actions instead of hiding the card", () => {
    const summary = mapOrganizerHomeSummary(intel, { pending_applications: 1 }, [])
    expect(summary.intelligence.recommendations).toHaveLength(2)
    const top = summary.intelligence.recommendations[0]
    expect(top.issue).toBe("Remind unpaid vendors")
    expect(top.candidateAction).toBe("3 approved vendors haven't paid.")
    expect(top.funnelStage).toBe("payment")
    expect(top.kind).toBe("action")
    expect(top.confidenceLabel).toBe("high")
    expect(top.href).toContain("#comms")
  })

  it("maps weak events into event health, falling back to the strongest event", () => {
    const summary = mapOrganizerHomeSummary(intel, {}, [])
    expect(summary.intelligence.eventHighlights).toEqual([
      { eventId: "12", title: "Greek Picnic", score: 52, label: "At risk", tone: "attention" },
    ])
    expect(mapOrganizerEventHighlights([{ event_id: 11, title: "Fest", score: 90, label: "Strong" }], intel.top_events)).toEqual([
      { eventId: "11", title: "Sunset & Soul R&B Festival", score: 88, label: "Strong", tone: "strong" },
    ])
  })

  it("ignores malformed actions and empty payloads", () => {
    expect(mapOrganizerRecommendedActions(undefined)).toEqual([])
    expect(mapOrganizerRecommendedActions([{ priority: "high" }])).toEqual([])
    expect(mapOrganizerEventHighlights(undefined, undefined)).toEqual([])
  })
})
