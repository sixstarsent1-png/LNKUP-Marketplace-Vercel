import {
  clearPendingDestination,
  destinationScope,
  takePendingDestination,
} from "@/lib/navigation/pending-destination"
import {
  hrefFromNotificationData,
  inAppHrefForNotification,
  navigateFromNotificationData,
} from "@/lib/push/notification-routing"

const vendor = ["lnkup_vendor"]
const organizer = ["lnkup_organizer"]

const VENDOR_TYPES = [
  "info_requested",
  "application_rejected",
  "application_waitlisted",
  "packet_unlocked",
  "payment_confirmed",
  "payment_paid",
  "payment_required",
  "payment_due_soon",
  "application_approved",
  "system",
  "activation_profile",
  "activation_find",
  "activation_apply",
  "discovery_saved_search",
  "discovery_favorite_deadline",
  "discovery_favorite_organizer",
  "discovery_deadline",
  "discovery_almost_full",
  "discovery_profile_match",
  "discovery_invite",
]

const ORGANIZER_TYPES = [
  "activation_create_event",
  "activation_org_review",
  "review_requested",
  "organizer_event_needs_attention",
  "organizer_new_vendor_request",
  "organizer_vendor_paid",
  "organizer_payment_received",
]

describe("hrefFromNotificationData", () => {
  it("opens the booking for an info request even though the server href is not an app route", () => {
    expect(hrefFromNotificationData({ type: "info_requested", applicationId: "71", href: "/requests" })).toBe("/booking/71")
  })

  it("prefers the booking over the generic list for declines and waitlists", () => {
    expect(
      hrefFromNotificationData({ type: "application_rejected", applicationId: "64", href: "/(vendor)/requests" }),
    ).toBe("/booking/64")
    expect(hrefFromNotificationData({ type: "application_waitlisted", application_id: "65" })).toBe("/booking/65")
  })

  it("uses the server href when it is a real route and nothing more specific is known", () => {
    expect(hrefFromNotificationData({ type: "payment_required", href: "/(vendor)/wallet" })).toBe("/(vendor)/wallet")
    expect(hrefFromNotificationData({ type: "organizer_new_vendor_request", href: "/(organizer)/vendors" })).toBe(
      "/(organizer)/vendors",
    )
  })

  it("ignores unknown or external hrefs", () => {
    expect(hrefFromNotificationData({ type: "unknown_type", href: "https://evil.test" })).toBeNull()
    expect(hrefFromNotificationData({ type: "unknown_type", href: "/not/a/route" })).toBeNull()
    expect(hrefFromNotificationData({ type: "packet_unlocked", applicationId: "0" })).toBe("/(vendor)/requests")
  })

  it("scopes organizer request pushes to the event", () => {
    expect(hrefFromNotificationData({ type: "organizer_new_vendor_request", eventId: 56 })).toBe("/(organizer)/vendors?event=56")
  })

  it.each(VENDOR_TYPES)("%s routes to a vendor-only screen", (type) => {
    for (const data of [{ type }, { type, applicationId: "71" }]) {
      const href = hrefFromNotificationData(data)
      expect(href).not.toBeNull()
      expect(destinationScope(href as string)).toBe("vendor")
    }
  })

  it.each(ORGANIZER_TYPES)("%s routes to an organizer-only screen", (type) => {
    for (const data of [{ type }, { type, eventId: "56" }]) {
      const href = hrefFromNotificationData(data)
      expect(href).not.toBeNull()
      expect(destinationScope(href as string)).toBe("organizer")
    }
  })
})

describe("inAppHrefForNotification", () => {
  it("opens server inbox rows that only carry a web link", () => {
    const row = { type: "payment_paid" as const, href: "https://thelnkup.com/vendor-dashboard/?view=pass", applicationId: "71" }
    expect(inAppHrefForNotification(row, vendor)).toBe("/pass/71")
    expect(inAppHrefForNotification(row, organizer)).toBeNull()
  })

  it("keeps a valid in-app href as is", () => {
    expect(inAppHrefForNotification({ type: "application_rejected", href: "/booking/64" }, vendor)).toBe("/booking/64")
  })
})

describe("navigateFromNotificationData", () => {
  beforeEach(() => clearPendingDestination())

  it("queues the destination for the right role only", async () => {
    navigateFromNotificationData({ type: "payment_paid", applicationId: "71" })
    expect(await takePendingDestination(organizer)).toBeNull()

    navigateFromNotificationData({ type: "payment_paid", applicationId: "71" })
    expect(await takePendingDestination(vendor)).toBe("/pass/71")
  })

  it("keeps an organizer push away from a vendor-only account", async () => {
    navigateFromNotificationData({ type: "organizer_payment_received", applicationId: "71", href: "/(organizer)/money" })
    expect(await takePendingDestination(vendor)).toBeNull()
  })
})
