import type { VendorRequest } from "@/lib/api/vendor-requests"
import { eventDayBookings, vendorLifecycleCounts, vendorNextStep } from "@/lib/vendor/vendor-stage"

const NOW = new Date(2026, 10, 16, 9, 0, 0)

function req(id: string, status: VendorRequest["status"], eventDateIso = "2026-12-01", extra: Partial<VendorRequest> = {}): VendorRequest {
  return { id, eventName: `Event ${id}`, status, eventDateIso, ...extra }
}

describe("vendor next step", () => {
  it("puts an organizer question first", () => {
    const { stage, step } = vendorNextStep([req("1", "payment_required"), req("2", "info_requested")], 80, NOW)
    expect(stage).toBe("action")
    expect(step.kind).toBe("reply")
  })

  it("asks for payment before showing confirmed events", () => {
    expect(vendorNextStep([req("1", "paid"), req("2", "payment_required")], 80, NOW).step.kind).toBe("pay")
  })

  it("prepares for the soonest confirmed event", () => {
    const { stage, step } = vendorNextStep([req("1", "paid", "2026-12-20"), req("2", "paid", "2026-11-20")], 80, NOW)
    expect(stage).toBe("confirmed")
    expect(step.kind === "prepare" && step.request.id).toBe("2")
  })

  it("tracks pending applications", () => {
    const { stage, step } = vendorNextStep([req("1", "submitted"), req("2", "under_review")], 80, NOW)
    expect(stage).toBe("awaiting")
    expect(step).toEqual({ kind: "track", count: 2 })
  })

  it("starts new vendors on their profile, then exploring", () => {
    expect(vendorNextStep([], 20, NOW).step.kind).toBe("profile")
    expect(vendorNextStep([], 90, NOW).step.kind).toBe("explore")
  })

  it("ignores actions for events that already happened", () => {
    expect(vendorNextStep([req("1", "payment_required", "2026-10-01")], 90, NOW).step.kind).toBe("explore")
  })
})

describe("event day and lifecycle counts", () => {
  it("finds paid bookings today and tomorrow only", () => {
    const list = eventDayBookings(
      [req("t", "paid", "2026-11-16"), req("m", "paid", "2026-11-17"), req("l", "paid", "2026-11-20"), req("u", "payment_required", "2026-11-16")],
      NOW,
    )
    expect(list.map((r) => r.id)).toEqual(["t", "m"])
  })

  it("counts every request in exactly one bucket", () => {
    const counts = vendorLifecycleCounts(
      [req("a", "info_requested"), req("b", "submitted"), req("c", "paid"), req("d", "paid", "2026-10-01"), req("e", "rejected")],
      NOW,
    )
    expect(counts).toEqual({ action: 1, review: 1, confirmed: 1, past: 1, closed: 1 })
  })
})
