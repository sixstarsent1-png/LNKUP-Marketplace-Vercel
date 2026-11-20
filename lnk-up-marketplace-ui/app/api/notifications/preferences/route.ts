import { NextResponse } from "next/server"
import { isLiveApiEnabled, getPublicWpSiteUrl } from "@/lib/api/config"
import {
  getWordPressSettingsHandoffUrl,
  mockNotificationPreferences,
  type NotificationPreferences,
} from "@/lib/api/notifications"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession } from "@/lib/auth/session"

let mockPrefsStore: NotificationPreferences = { ...mockNotificationPreferences, items: [...mockNotificationPreferences.items] }

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to view notification preferences.")
    }

    const role = session.canAccessOrganizer && !session.canAccessVendor ? "organizer" : "vendor"

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ ...mockPrefsStore, role, userId: String(session.userId ?? "") })
    }

    return NextResponse.json({
      role,
      items: [],
      unavailable: true,
      handoffUrl: getWordPressSettingsHandoffUrl(role),
      pushAvailable: false,
      emailVerified: true,
      message: "Notification preferences are managed on LNKup web right now.",
    } satisfies NotificationPreferences & { message?: string })
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/preferences",
      fallbackMessage: "We couldn't load notification preferences.",
      fallbackCode: "notification_preferences_failed",
    })
  }
}

export async function PATCH(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Sign in to update notification preferences.")
    }

    if (!isLiveApiEnabled()) {
      const body = await request.json().catch(() => ({}))
      const itemId = typeof body.itemId === "string" ? body.itemId : undefined
      const channel = typeof body.channel === "string" ? body.channel : undefined
      const enabled = typeof body.enabled === "boolean" ? body.enabled : undefined

      if (itemId && channel && enabled !== undefined) {
        mockPrefsStore = {
          ...mockPrefsStore,
          items: mockPrefsStore.items.map((item) =>
            item.id === itemId
              ? {
                  ...item,
                  channels: {
                    ...item.channels,
                    [channel as keyof typeof item.channels]: enabled,
                  },
                }
              : item,
          ),
        }
      }
      return NextResponse.json(mockPrefsStore)
    }

    const role = session.canAccessOrganizer && !session.canAccessVendor ? "organizer" : "vendor"
    return NextResponse.json(
      {
        code: "handoff_required",
        message: "Notification preferences are managed on LNKup web right now.",
        handoffUrl: getWordPressSettingsHandoffUrl(role) || getPublicWpSiteUrl(),
      },
      { status: 403 },
    )
  } catch (error) {
    return toErrorResponse(error, {
      context: "notifications/preferences",
      fallbackMessage: "We couldn't update notification preferences.",
      fallbackCode: "notification_preferences_failed",
    })
  }
}
