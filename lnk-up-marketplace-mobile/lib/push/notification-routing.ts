import { destinationScope, safeDestination, setPendingDestination } from "@/lib/navigation/pending-destination"
import type { LnkupNotification } from "@/lib/notifications/types"

type TypedRoute = {
  href: string
  /** True when the route points at one booking or event rather than a list. */
  specific: boolean
}

function idFrom(data: Record<string, unknown>, ...keys: string[]): string | null {
  for (const key of keys) {
    const value = data[key]
    if ((typeof value === "string" && value.trim() !== "" && value !== "0") || (typeof value === "number" && value > 0)) {
      return String(value)
    }
  }
  return null
}

function routeForType(
  type: string,
  applicationId: string | null,
  eventId: string | null,
  tourId: string | null = null,
): TypedRoute | null {
  const app = (path: string, fallback: string): TypedRoute =>
    applicationId ? { href: `${path}/${applicationId}`, specific: true } : { href: fallback, specific: false }
  const list = (href: string): TypedRoute => ({ href, specific: false })

  switch (type) {
    case "info_requested":
      return app("/booking", "/(vendor)/requests?filter=action")
    case "application_rejected":
    case "application_waitlisted":
      return app("/booking", "/(vendor)/requests")
    case "packet_unlocked":
    case "payment_confirmed":
    case "payment_paid":
      return app("/pass", "/(vendor)/requests")
    case "payment_required":
    case "payment_due_soon":
    case "application_approved":
      return app("/pass", "/(vendor)/wallet")
    case "system":
      return app("/pass", "/(vendor)/operations")
    case "activation_profile":
      return list("/(vendor)/profile")
    case "activation_find":
    case "activation_apply":
    case "discovery_favorite_organizer":
    case "discovery_deadline":
    case "discovery_almost_full":
    case "discovery_profile_match":
    case "discovery_invite":
    case "discovery_weekly_matches":
      return list("/(vendor)/find")
    case "discovery_tour_match":
      return tourId ? { href: `/tour/${encodeURIComponent(tourId)}`, specific: true } : list("/(vendor)/find")
    case "discovery_saved_search":
      return list("/(vendor)/saved-searches")
    case "discovery_favorite_deadline":
      return list("/(vendor)/favorites")
    case "activation_create_event":
      return list("/(organizer)/launch")
    case "activation_org_review":
    case "review_requested":
    case "organizer_recruit_vendors":
      return list("/(organizer)/vendors")
    case "organizer_open_spots":
      return eventId
        ? { href: `/(organizer)/vendors/find?event=${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/vendors/find")
    case "organizer_event_needs_attention":
      return eventId
        ? { href: `/(organizer)/events/${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/events")
    case "organizer_new_vendor_request":
      return eventId
        ? { href: `/(organizer)/vendors?event=${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/vendors")
    case "organizer_vendor_paid":
    case "organizer_payment_received":
      return list("/(organizer)/money")
    default:
      return null
  }
}

/**
 * In-app route for a push payload. A booking- or event-specific route wins; otherwise the server's
 * `href` is used when it is a real app route; otherwise the type's list screen.
 */
export function hrefFromNotificationData(data: Record<string, unknown> | undefined): string | null {
  if (!data) return null
  // lnkup-communications pushes carry the exact screen; trust it when it is an allowlisted route.
  const serverHref = typeof data.href === "string" ? data.href : null
  if (data.notificationId != null && serverHref && destinationScope(serverHref)) return serverHref
  const type = typeof data.type === "string" ? data.type : ""
  const typed = routeForType(
    type,
    idFrom(data, "applicationId", "application_id"),
    idFrom(data, "eventId", "event_id"),
    idFrom(data, "tourId", "tour_id"),
  )
  if (typed?.specific) return typed.href

  const href = typeof data.href === "string" ? data.href : null
  if (href && destinationScope(href)) return href
  return typed?.href ?? null
}

/**
 * In-app route for an inbox row, checked against the signed-in roles. Server inbox rows carry a
 * web link, so they fall back to the push routing rules for their type and booking.
 */
export function inAppHrefForNotification(
  item: Pick<LnkupNotification, "href" | "type" | "requestId" | "applicationId"> & Partial<Pick<LnkupNotification, "deepLink">>,
  roles: string[] | undefined,
): string | null {
  const server = item.deepLink ? safeDestination(item.deepLink, roles) : null
  if (server) return server
  const direct = item.href ? safeDestination(item.href, roles) : null
  if (direct) return direct
  const routed = hrefFromNotificationData({
    type: item.type,
    applicationId: item.applicationId ?? item.requestId,
  })
  return routed ? safeDestination(routed, roles) : null
}

/**
 * Route a notification tap through the pending-destination queue: it opens immediately when the
 * user is signed in with a role allowed there, otherwise right after sign-in (or not at all).
 */
export function navigateFromNotificationData(data: Record<string, unknown> | undefined): void {
  const href = hrefFromNotificationData(data)
  if (href) {
    setPendingDestination(href)
  }
}
