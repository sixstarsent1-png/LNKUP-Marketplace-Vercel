import { normalizeInboxItem } from "@/lib/api/comms"
import { inAppHrefForNotification, hrefFromNotificationData } from "@/lib/push/notification-routing"
import { inboxTabsFor, normalizeNotificationType, notificationTypeLabel, NOTIFICATION_TYPES } from "@/lib/notifications/types"

const vendor = ["lnkup_vendor"]
const organizer = ["lnkup_organizer"]

describe("normalizeNotificationType", () => {
  it("keeps known server types", () => {
    expect(normalizeNotificationType("payment_failed")).toBe("payment_failed")
    expect(normalizeNotificationType("organizer_checkin_activity")).toBe("organizer_checkin_activity")
  })

  it("maps unknown or missing types to a generic update", () => {
    expect(normalizeNotificationType("brand_new_type")).toBe("system")
    expect(normalizeNotificationType(undefined)).toBe("system")
  })

  it("has a label for every type", () => {
    for (const type of NOTIFICATION_TYPES) {
      expect(notificationTypeLabel(type).length).toBeGreaterThan(0)
    }
  })
})

describe("inboxTabsFor", () => {
  it("gives vendors an Opportunities tab and organizers an Operations tab", () => {
    expect(inboxTabsFor("vendor").map((t) => t.value)).toEqual(["all", "applications", "payments", "events", "opportunities", "invitations", "account"])
    expect(inboxTabsFor("organizer").map((t) => t.value)).toEqual(["all", "applications", "payments", "events", "operations", "invitations", "account"])
  })
})

describe("normalizeInboxItem", () => {
  const raw = {
    id: "comms-42",
    type: "application_approved",
    title: "You're approved!",
    message: "Complete payment.",
    createdAt: "2026-11-22T10:00:00Z",
    readAt: null,
    severity: "urgent",
    role: "vendor",
    href: "https://thelnkup.com/vendor-dashboard/?view=payments",
    tab: "applications",
    deepLink: "/(vendor)/wallet",
    applicationId: "12",
    notificationId: 42,
    priority: "high",
  }

  it("maps the server shape", () => {
    const item = normalizeInboxItem(raw)
    expect(item).toMatchObject({
      id: "comms-42",
      type: "application_approved",
      deepLink: "/(vendor)/wallet",
      notificationId: 42,
      tab: "applications",
      applicationId: "12",
      priority: "high",
    })
    expect(item?.readAt).toBeUndefined()
  })

  it("drops rows without a title and ignores non-route deep links", () => {
    expect(normalizeInboxItem({ ...raw, title: "" })).toBeNull()
    expect(normalizeInboxItem({ ...raw, deepLink: "https://evil.example" })?.deepLink).toBeUndefined()
  })

  it("falls back to safe defaults for unknown values", () => {
    const item = normalizeInboxItem({ ...raw, tab: "weird", severity: "loud", priority: "max", type: "x" })
    expect(item).toMatchObject({ tab: "all", severity: "info", priority: "", type: "system" })
  })
})

describe("server-chosen routes", () => {
  it("opens the server deep link for inbox rows when the role may open it", () => {
    const item = normalizeInboxItem({ id: "comms-1", type: "application_approved", title: "t", deepLink: "/(vendor)/wallet", applicationId: "9" })!
    expect(inAppHrefForNotification(item, vendor)).toBe("/(vendor)/wallet")
  })

  it("never opens a vendor route for an organizer-only account", () => {
    const item = normalizeInboxItem({ id: "comms-2", type: "application_approved", title: "t", deepLink: "/(vendor)/wallet" })!
    expect(inAppHrefForNotification(item, organizer)).not.toBe("/(vendor)/wallet")
  })

  it("prefers the server href on lnkup-communications pushes", () => {
    expect(hrefFromNotificationData({ type: "payment_required", applicationId: "5", href: "/(vendor)/wallet", notificationId: "77" })).toBe("/(vendor)/wallet")
  })

  it("keeps the typed route for older pushes without a notification id", () => {
    expect(hrefFromNotificationData({ type: "payment_required", applicationId: "5", href: "/(vendor)/wallet" })).toBe("/pass/5")
  })

  it("ignores non-allowlisted server hrefs", () => {
    expect(hrefFromNotificationData({ type: "payment_required", applicationId: "5", href: "/settings/delete-account", notificationId: "77" })).toBe("/pass/5")
  })

  it("routes organizer event communications", () => {
    expect(hrefFromNotificationData({ type: "organizer_checkin_activity", href: "/(organizer)/events/checkin?event=3", notificationId: "8" })).toBe(
      "/(organizer)/events/checkin?event=3",
    )
  })
})
