import { withPresentation, type OrganizerEventLite } from "@/lib/api/organizer-events"
import { mapEventSummary } from "@/lib/api/organizer-portfolio"

const base: OrganizerEventLite = { id: "968", title: "Market &amp; Fair", city: "Houston", state: "TX", status: "upcoming" }

describe("organizer portfolio presentation", () => {
  it("maps capacity and gross money from the summary route", () => {
    const summary = mapEventSummary({
      id: 968,
      title: "Market & Fair",
      place: "Austin, TX",
      capacity: { total: 10, confirmed: 3, held: 2, open: 5, waitlisted: 1, booth_types: 2 },
      money: { collected_gross: 450, outstanding: 300, paid_bookings: 3 },
    })
    expect(summary?.capacity).toEqual({ total: 10, confirmed: 3, held: 2, open: 5, waitlisted: 1, boothTypes: 2 })
    expect(summary?.money).toEqual({ collectedGross: 450, outstanding: 300, paidBookings: 3 })
  })

  it("prefers the server's canonical title and place over local fields", () => {
    const merged = withPresentation(base, mapEventSummary({ id: 968, title: "Market & Fair", place: "Austin, TX" }))
    expect(merged.title).toBe("Market & Fair")
    expect(merged.place).toBe("Austin, TX")
    expect(merged.city).toBe("Houston")
  })

  it("keeps the event unchanged when the server has no summary route", () => {
    expect(withPresentation(base, null)).toBe(base)
    expect(mapEventSummary({})).toBeNull()
  })
})
