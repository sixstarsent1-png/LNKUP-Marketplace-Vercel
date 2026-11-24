import { useEffect } from "react"
import { AppState, type AppStateStatus } from "react-native"
import * as Notifications from "expo-notifications"

import { useAuth } from "@/lib/auth/auth-context"
import { fetchInboxCount, trackNotificationOpened } from "@/lib/api/comms"
import { fetchNotifications, type NotificationRole } from "@/lib/api/notifications"
import {
  loadHomePreference,
  sessionHasOrganizer,
  sessionHasVendor,
} from "@/lib/auth/roles"
import { getUnreadCount } from "@/lib/notifications/read-state"
import {
  ensureAndroidChannels,
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

/** Opens tapped before sign-in completes; reported once the user is authenticated. */
const pendingOpened: unknown[] = []

function handleTap(data: Record<string, unknown> | undefined) {
  if (data?.notificationId != null) pendingOpened.push(data.notificationId)
  navigateFromNotificationData(data)
}

/**
 * Handles notification taps (including the tap that cold-started the app, even before sign-in)
 * by queueing the destination; PendingDestinationRouter opens it once the user and role allow.
 * Registers the device only when permission was already granted (the OS prompt is shown from a
 * contextual in-app prompt instead) and refreshes the badge while signed in.
 */
export function PushBootstrap() {
  const { isAuthenticated, auth, session } = useAuth()

  useEffect(() => {
    void ensureAndroidChannels()
    const sub = Notifications.addNotificationResponseReceivedListener((response) => {
      handleTap(dataOf(response))
    })
    void Notifications.getLastNotificationResponseAsync()
      .then((response) => {
        if (!response) return
        handleTap(dataOf(response))
        return Notifications.clearLastNotificationResponseAsync()
      })
      .catch(() => undefined)
    return () => sub.remove()
  }, [])

  useEffect(() => {
    if (!isAuthenticated || !session) {
      return
    }

    void syncPushRegistration(auth, { prompt: false })
    const flushOpened = () => {
      while (pendingOpened.length > 0) {
        void trackNotificationOpened(pendingOpened.shift(), auth)
      }
    }
    flushOpened()

    const refreshBadge = () => {
      void resolveNotificationRole(session)
        .then(async (role) => {
          const count = await fetchInboxCount(role, auth)
          if (count) return count.unread
          const items = await fetchNotifications(role, { auth, userId: session.user.id })
          return getUnreadCount(items)
        })
        .then((unread) => syncAppBadge(unread))
        .catch(() => undefined)
    }

    const received = Notifications.addNotificationReceivedListener(() => {
      refreshBadge()
    })
    // The tap listener above queues the id; drain after it runs (listener order is not guaranteed).
    const responded = Notifications.addNotificationResponseReceivedListener(() => {
      setTimeout(flushOpened, 0)
    })
    // Read elsewhere (web, another device) while the app was closed or backgrounded.
    refreshBadge()
    const appState = AppState.addEventListener("change", (next: AppStateStatus) => {
      if (next === "active") refreshBadge()
    })

    return () => {
      received.remove()
      responded.remove()
      appState.remove()
    }
  }, [isAuthenticated, auth, session])

  return null
}
