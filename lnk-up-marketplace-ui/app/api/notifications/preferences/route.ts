import { NextResponse } from "next/server"
import { isLiveApiEnabled, getPublicWpSiteUrl } from "@/lib/api/config"
import { isCommsUnavailable, type CommsPreferences, type CommsPreferencesUpdate, type EmailMode } from "@/lib/api/comms"
import {
  getWordPressSettingsHandoffUrl,
  mockNotificationPreferences,
  type NotificationPreferences,
} from "@/lib/api/notifications"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

let mockPrefsStore: NotificationPreferences = { ...mockNotificationPreferences, items: [...mockNotificationPreferences.items] }

const EMAIL_MODES = new Set(["immediate", "daily", "weekly", "off"])
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/

/** Only forward known fields to WordPress (which validates again). */
function sanitizeUpdate(body: unknown): CommsPreferencesUpdate | null {
  if (!body || typeof body !== "object") return null
  const input = body as Record<string, unknown>
  const update: CommsPreferencesUpdate = {}
  if (Array.isArray(input.categories)) {
    update.categories = input.categories
      .filter((c): c is Record<string, unknown> => !!c && typeof c === "object" && typeof (c as { key?: unknown }).key === "string")
      .slice(0, 30)
      .map((c) => ({
        key: String(c.key).slice(0, 32),
        ...(EMAIL_MODES.has(String(c.email)) ? { email: c.email as EmailMode } : {}),
        ...(c.push === "on" || c.push === "off" ? { push: c.push } : {}),
      }))
  }
  if (typeof input.pushEnabled === "boolean") update.pushEnabled = input.pushEnabled
  if (input.quietHours && typeof input.quietHours === "object") {
    const q = input.quietHours as Record<string, unknown>
    update.quietHours = {
      enabled: q.enabled === true,
      start: TIME.test(String(q.start)) ? String(q.start) : "22:00",
      end: TIME.test(String(q.end)) ? String(q.end) : "08:00",
    }
  }
  if (typeof input.timezone === "string" && input.timezone.length <= 64) update.timezone = input.timezone
  return Object.keys(update).length > 0 ? update : null
}

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

    try {
      const comms = await wpFetchJson<CommsPreferences>("/comms/preferences", { auth: toWpAuth(session) })
      return NextResponse.json({
        role,
        items: [],
        pushAvailable: true,
        emailVerified: true,
        comms,
      } satisfies NotificationPreferences & { comms: CommsPreferences })
    } catch (error) {
      if (!isCommsUnavailable(error)) throw error
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

    const body = await request.json().catch(() => ({}))

    if (!isLiveApiEnabled()) {
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
    const update = sanitizeUpdate(body)
    if (update) {
      try {
        const comms = await wpFetchJson<CommsPreferences>("/comms/preferences", {
          method: "PATCH",
          auth: toWpAuth(session),
          body: update,
        })
        return NextResponse.json({ role, items: [], pushAvailable: true, emailVerified: true, comms })
      } catch (error) {
        if (!isCommsUnavailable(error)) throw error
      }
    }

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
