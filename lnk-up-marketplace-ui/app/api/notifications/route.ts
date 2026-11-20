import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  composeOrganizerNotifications,
  mockOrganizerNotifications,
  mockVendorNotifications,
} from "@/lib/api/notifications"
import { composeNotificationsForSession } from "@/lib/api/notifications-server"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      const session = await getSession()
      const items = []
      if (!session) {
        items.push(...mockVendorNotifications, ...mockOrganizerNotifications)
      } else {
        const isVendor = session.canAccessVendor ?? session.roles?.includes("vendor")
        const isOrganizer = session.canAccessOrganizer ?? session.roles?.includes("organizer")
        if (isVendor) items.push(...mockVendorNotifications)
        if (isOrganizer) items.push(...mockOrganizerNotifications)
        if (items.length === 0) {
          items.push(...mockVendorNotifications)
        }
      }
      return NextResponse.json({ items })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to view notifications.")
    }

    const items = await composeNotificationsForSession(session)
    return NextResponse.json({ items })
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications",
      fallbackMessage: "We couldn't load notifications.",
      fallbackCode: "notifications_failed",
    })
  }
}
