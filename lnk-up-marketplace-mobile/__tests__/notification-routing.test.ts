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
  "discovery_weekly_matches",
]

const ORGANIZER_TYPES = [
  "activation_create_event",
  "organizer_recruit_vendors",
  "organizer_open_spots",
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

  it("ignores unknown or external hrefs and falls back to the role's notifications", () => {
    expect(hrefFromNotificationData({ type: "unknown_type", href: "https://evil.test" })).toBe("/(vendor)/notifications")
    expect(hrefFromNotificationData({ type: "organizer_unknown", href: "/not/a/route" })).toBe("/(organizer)/notifications")
    expect(hrefFromNotificationData({ type: "partner_unknown" })).toBe("/(partner)/notifications")
    expect(hrefFromNotificationData({ type: "packet_unlocked", applicationId: "0" })).toBe("/(vendor)/requests")
    expect(hrefFromNotificationData({})).toBeNull()
  })

  it("never routes an inbox row back to the inbox itself", () => {
    expect(inAppHrefForNotification({ type: "system_future_type" as never, href: "https://thelnkup.com/x" }, ["lnkup_vendor"])).toBeNull()
  })

  it("routes engagement types to their screens", () => {
    expect(hrefFromNotificationData({ type: "discovery_invite_expiring" })).toBe("/(vendor)/invitations")
    expect(hrefFromNotificationData({ type: "organizer_new_vendors_nearby", eventId: 12 })).toBe("/(organizer)/vendors/find?event=12")
    expect(hrefFromNotificationData({ type: "organizer_invite_response" })).toBe("/(organizer)/vendors?tab=invites")
    expect(hrefFromNotificationData({ type: "organizer_event_no_slots", eventId: 9 })).toBe("/(organizer)/events/9")
    expect(hrefFromNotificationData({ type: "application_draft" })).toBe("/(vendor)/requests")
    expect(hrefFromNotificationData({ type: "payment_failed" })).toBe("/(vendor)/wallet")
  })

  it("scopes organizer request pushes to the event", () => {
    expect(hrefFromNotificationData({ type: "organizer_new_vendor_request", eventId: 56 })).toBe("/(organizer)/vendors?event=56")
  })

  it("opens the tour for a tour match and falls back to Find without one", () => {
    expect(hrefFromNotificationData({ type: "discovery_tour_match", tourId: "12" })).toBe("/tour/12")
    expect(hrefFromNotificationData({ type: "discovery_tour_match", tour_id: 12 })).toBe("/tour/12")
    expect(hrefFromNotificationData({ type: "discovery_tour_match" })).toBe("/(vendor)/find")
  })

  it("trusts a comms tour href and routes open-spots reminders to vendor search for the event", () => {
    expect(hrefFromNotificationData({ type: "discovery_tour_match", notificationId: 9, href: "/tour/12" })).toBe("/tour/12")
    expect(hrefFromNotificationData({ type: "organizer_open_spots", eventId: "56" })).toBe("/(organizer)/vendors/find?event=56")
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
