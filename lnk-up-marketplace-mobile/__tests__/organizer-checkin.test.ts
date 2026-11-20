import { WpFetchError } from "@/lib/api/errors"
import {
  canCheckIn,
  canOverride,
  checkInCounts,
  checkInOutcomeFromError,
  eventDayEntries,
  matchesCheckInFilter,
  matchesCheckInSearch,
} from "@/lib/api/organizer-checkin"
import { confirmedVendors, mapRoster } from "@/lib/api/organizer-roster"

const roster = mapRoster("60", {
  groups: {
    checked_in: [{ application_id: 66, business_name: "Taco Cart", status: "paid", checked_in: true, booth: "4", actions: ["setup_started"] }],
    paid: [{ application_id: 67, business_name: "Soap Co", status: "paid", reason: "ready", actions: ["checked_in", "no_show"] }],
    missing_payment: [{ application_id: 68, business_name: "Late Payer", status: "payment_required", reason: "payment_pending", message: "Payment pending." }],
    missing_documents: [{ application_id: 67, business_name: "Soap Co", status: "paid", reason: "ready" }],
    applied: [{ application_id: 69, business_name: "Applicant", status: "submitted" }],
    waitlisted: [{ application_id: 70, business_name: "Maybe", status: "waitlisted" }],
  },
})

describe("roster mapping", () => {
  it("keeps checked-in vendors and lists each application once", () => {
    const ids = roster.entries.map((entry) => entry.applicationId)
    expect(ids).toEqual(["66", "67", "68", "69", "70"])
  })

  it("counts checked-in vendors as confirmed", () => {
    expect(confirmedVendors(roster).map((entry) => entry.applicationId)).toEqual(["66", "67"])
  })
})

describe("event-day list", () => {
  const entries = eventDayEntries(roster)

  it("shows booked vendors only", () => {
    expect(entries.map((entry) => entry.applicationId)).toEqual(["66", "67", "68"])
  })

  it("offers check-in only where the server allows it, and override only for payment or documents gates", () => {
    const [checkedIn, ready, unpaid] = entries
    expect([canCheckIn(checkedIn), canCheckIn(ready), canCheckIn(unpaid)]).toEqual([false, true, false])
    expect([canOverride(checkedIn), canOverride(ready), canOverride(unpaid)]).toEqual([false, false, true])
  })

  it("counts and filters", () => {
    expect(checkInCounts(entries)).toEqual({ expected: 3, checkedIn: 1, attention: 1 })
    expect(entries.filter((e) => matchesCheckInFilter(e, "waiting")).length).toBe(2)
    expect(entries.filter((e) => matchesCheckInSearch(e, "4")).map((e) => e.businessName)).toEqual(["Taco Cart"])
    expect(entries.filter((e) => matchesCheckInSearch(e, "soap")).length).toBe(1)
  })
})

describe("checkInOutcomeFromError", () => {
  it("never reports an offline or timed-out request as saved", () => {
    expect(checkInOutcomeFromError(new WpFetchError("Failed to fetch", "network_error", 0)).kind).toBe("offline")
    expect(checkInOutcomeFromError(new WpFetchError("Request timed out", "wordpress_unavailable", 408)).kind).toBe("unconfirmed")
  })

  it("maps server gates to blocked results with override where allowed", () => {
    const documents = checkInOutcomeFromError(new WpFetchError("Documents need attention.", "documents", 409))
    expect(documents).toMatchObject({ kind: "blocked", canOverride: true })
    const wrongEvent = checkInOutcomeFromError(new WpFetchError("Different event.", "wrong_event", 409))
    expect(wrongEvent).toMatchObject({ kind: "blocked", canOverride: false })
    expect(checkInOutcomeFromError(new WpFetchError("Nope", "forbidden", 403)).kind).toBe("denied")
  })
})
