import type { VendorRequest } from "@/lib/api/vendor-requests"
import { bookingTimeline } from "@/lib/vendor/booking-timeline"

const NOW = new Date(2026, 10, 16)

function req(status: VendorRequest["status"], eventDateIso = "2026-12-01"): VendorRequest {
  return { id: "1", eventName: "E", status, eventDateIso }
}

function states(status: VendorRequest["status"], date?: string) {
  return bookingTimeline(req(status, date), NOW).map((step) => `${step.key}:${step.state}`)
}

describe("booking timeline", () => {
  it("shows review as current right after applying", () => {
    expect(states("submitted")).toEqual([
      "applied:done",
      "review:current",
      "decision:upcoming",
      "payment:upcoming",
      "event:upcoming",
    ])
  })

  it("adds the organizer question only when one is open", () => {
    expect(states("info_requested")).toContain("info:current")
    expect(states("under_review").some((s) => s.startsWith("info"))).toBe(false)
  })

  it("makes payment the current step after approval", () => {
    expect(states("payment_required")).toContain("payment:current")
    expect(states("payment_required")).toContain("decision:done")
  })

  it("moves to event day once paid and completes after the date", () => {
    expect(states("paid")).toContain("event:current")
    expect(states("paid", "2026-10-01")).toContain("event:done")
  })

  it("stops at a decline or waitlist without payment steps", () => {
    expect(states("rejected")).toEqual(["applied:done", "review:done", "decision:stopped"])
    expect(states("waitlisted")).toEqual(["applied:done", "review:done", "decision:current"])
  })
})
