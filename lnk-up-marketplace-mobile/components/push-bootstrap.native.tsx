import { useEffect } from "react"
import * as Notifications from "expo-notifications"

import { useAuth } from "@/lib/auth/auth-context"
import { fetchNotifications, type NotificationRole } from "@/lib/api/notifications"
import {
  loadHomePreference,
  sessionHasOrganizer,
  sessionHasVendor,
} from "@/lib/auth/roles"
import { getUnreadCount } from "@/lib/notifications/read-state"
import {
  navigateFromNotificationData,
  syncAppBadge,
  syncPushRegistration,
} from "@/lib/push/notifications"

async function resolveNotificationRole(
  session: NonNullable<ReturnType<typeof useAuth>["session"]>,
): Promise<NotificationRole> {
  const hasOrg = sessionHasOrganizer(session)
  const hasVendor = sessionHasVendor(session)
  if (hasOrg && !hasVendor) return "organizer"
  if (hasVendor && !hasOrg) return "vendor"
  if (hasOrg && hasVendor) {
    const pref = await loadHomePreference()
    return pref === "vendor" ? "vendor" : "organizer"
  }
  return "vendor"
}

function dataOf(response: Notifications.NotificationResponse): Record<string, unknown> | undefined {
  return response.notification.request.content.data as Record<string, unknown> | undefined
}

/**
 * Handles notification taps (including the tap that cold-started the app, even before sign-in)
 * by queueing the destination; PendingDestinationRouter opens it once the user and role allow.
 * Registers the device and refreshes the badge while signed in.
 */
export function PushBootstrap() {
  const { isAuthenticated, auth, session } = useAuth()

  useEffect(() => {
    const sub = Notifications.addNotificationResponseReceivedListener((response) => {
      navigateFromNotificationData(dataOf(response))
    })
    void Notifications.getLastNotificationResponseAsync()
      .then((response) => {
        if (!response) return
        navigateFromNotificationData(dataOf(response))
        return Notifications.clearLastNotificationResponseAsync()
      })
      .catch(() => undefined)
    return () => sub.remove()
  }, [])

  useEffect(() => {
    if (!isAuthenticated || !session) {
      return
    }

    void syncPushRegistration(auth)

    const refreshBadge = () => {
      void resolveNotificationRole(session)
        .then((role) => fetchNotifications(role, { auth, userId: session.user.id }))
        .then((items) => syncAppBadge(getUnreadCount(items)))
        .catch(() => undefined)
    }

    const received = Notifications.addNotificationReceivedListener(() => {
      refreshBadge()
    })

    return () => received.remove()
  }, [isAuthenticated, auth, session])

  return null
}
