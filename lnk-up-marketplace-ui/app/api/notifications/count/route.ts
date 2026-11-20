import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { countNotifications, mockOrganizerNotifications, mockVendorNotifications } from "@/lib/api/notifications"
import { composeNotificationsForSession } from "@/lib/api/notifications-server"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession } from "@/lib/auth/session"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      const items = [...mockVendorNotifications, ...mockOrganizerNotifications]
      return NextResponse.json(countNotifications(items))
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to view notifications.")
    }

    const items = await composeNotificationsForSession(session)
    return NextResponse.json(countNotifications(items))
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/count",
      fallbackMessage: "We couldn't load notification count.",
      fallbackCode: "notifications_count_failed",
    })
  }
}
