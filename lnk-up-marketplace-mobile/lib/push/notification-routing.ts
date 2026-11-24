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
    case "discovery_weekly_matches":
    case "discovery_reactivation":
    case "event_completed":
      return list("/(vendor)/find")
    case "discovery_invite":
    case "discovery_invite_expiring":
      return list("/(vendor)/invitations")
    case "application_draft":
      return list("/(vendor)/requests")
    case "application_submitted":
    case "application_withdrawn":
    case "application_cancelled":
    case "event_cancelled":
      return app("/booking", "/(vendor)/requests")
    case "pass_updated":
    case "booth_assigned":
    case "event_logistics_changed":
    case "checked_in":
    case "event_reminder":
    case "organizer_announcement":
      return app("/pass", "/(vendor)/operations")
    case "payment_failed":
    case "receipt_available":
    case "refund_completed":
      return list("/(vendor)/wallet")
    case "document_missing":
    case "document_expiring":
    case "document_approved":
    case "document_rejected":
      return list("/(vendor)/documents")
    case "passport_verification":
    case "passport_verification_expiring":
    case "passport_review_received":
    case "passport_review_moderated":
      return list("/(vendor)/passport")
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
    case "organizer_invite_response":
      return list("/(organizer)/vendors?tab=invites")
    case "organizer_open_spots":
    case "organizer_new_vendors_nearby":
      return eventId
        ? { href: `/(organizer)/vendors/find?event=${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/vendors/find")
    case "organizer_event_needs_attention":
    case "organizer_event_no_slots":
    case "organizer_event_published":
    case "organizer_event_completed":
    case "organizer_payment_failed":
      return eventId
        ? { href: `/(organizer)/events/${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/events")
    case "organizer_new_vendor_request":
    case "organizer_info_response":
    case "organizer_application_withdrawn":
    case "organizer_waitlist_spot":
    case "organizer_document_shared":
    case "organizer_vendor_missing_documents":
      return eventId
        ? { href: `/(organizer)/vendors?event=${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/vendors")
    case "organizer_vendor_paid":
    case "organizer_payment_received":
    case "organizer_payout_setup":
    case "organizer_payout":
    case "organizer_refund_completed":
    case "organizer_dispute_opened":
    case "organizer_dispute_closed":
      return list("/(organizer)/money")
    case "organizer_review_response":
    case "organizer_review_moderated":
      return list("/(organizer)/vendors")
    case "organizer_checkin_activity":
      return eventId
        ? { href: `/(organizer)/events/checkin?event=${encodeURIComponent(eventId)}`, specific: true }
        : list("/(organizer)/events")
    case "partner_application":
      return list("/(partner)")
    case "partner_referral":
      return list("/(partner)/referrals")
    case "partner_lead":
      return list("/(partner)/leads")
    case "partner_commission":
    case "partner_payout":
      return list("/(partner)/earnings")
    default:
      return null
  }
}

/**
 * Last resort for any type (including ones this build does not know yet): the notifications
 * screen for the role the type belongs to, so a tap never leaves the user on a blank screen.
 */
export function fallbackRouteForType(type: string): string {
  if (type.startsWith("organizer_") || type === "activation_create_event") return "/(organizer)/notifications"
  if (type.startsWith("partner_")) return "/(partner)/notifications"
  return "/(vendor)/notifications"
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
  if (typed) return typed.href
  return type ? fallbackRouteForType(type) : null
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
  // Inside the inbox the fallback would reopen the inbox itself.
  if (!routed || routed === fallbackRouteForType(item.type)) return null
  return safeDestination(routed, roles)
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
