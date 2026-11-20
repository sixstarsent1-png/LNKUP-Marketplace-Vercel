import {
  applicationStatusLabel,
  applicationStatusTone,
  vendorLifecycleBucket,
  vendorStatusExplainer,
  type ApplicationStatus,
} from "@/lib/status/application-status"
import { organizerRequestStatusLabel } from "@/lib/api/organizer-requests"
import { vendorRequestStatusLabel } from "@/lib/api/vendor-requests"

const ALL: ApplicationStatus[] = [
  "submitted",
  "under_review",
  "info_requested",
  "approved",
  "payment_required",
  "paid",
  "waitlisted",
  "rejected",
  "cancelled",
  "completed",
  "unknown",
]

describe("application status wording", () => {
  it("labels every status for both audiences", () => {
    for (const status of ALL) {
      expect(applicationStatusLabel(status, "vendor")).toBeTruthy()
      expect(applicationStatusLabel(status, "organizer")).toBeTruthy()
    }
  })

  it("matches the web dashboard wording", () => {
    expect(organizerRequestStatusLabel("submitted")).toBe("To review")
    expect(organizerRequestStatusLabel("payment_required")).toBe("Awaiting payment")
    expect(organizerRequestStatusLabel("paid")).toBe("Confirmed")
    expect(organizerRequestStatusLabel("rejected")).toBe("Declined")
    expect(vendorRequestStatusLabel("info_requested")).toBe("More info needed")
    expect(vendorRequestStatusLabel("paid")).toBe("Confirmed")
  })

  it("flags vendor action items as due", () => {
    expect(applicationStatusTone("info_requested", "vendor")).toBe("due")
    expect(applicationStatusTone("payment_required", "vendor")).toBe("due")
    expect(applicationStatusTone("info_requested", "organizer")).toBe("review")
  })

  it("buckets the vendor lifecycle and closes stale actions after the event", () => {
    expect(vendorLifecycleBucket("payment_required", false)).toBe("action")
    expect(vendorLifecycleBucket("payment_required", true)).toBe("closed")
    expect(vendorLifecycleBucket("paid", false)).toBe("confirmed")
    expect(vendorLifecycleBucket("paid", true)).toBe("past")
    expect(vendorLifecycleBucket("under_review", false)).toBe("review")
    expect(vendorLifecycleBucket("rejected", false)).toBe("closed")
  })
})

describe("vendorStatusExplainer", () => {
  it("explains every status in plain words and never promises payment before approval", () => {
    for (const status of ALL) expect(vendorStatusExplainer(status).length).toBeGreaterThan(10)
    expect(vendorStatusExplainer("under_review")).toMatch(/only pay if they approve/)
  })
})
