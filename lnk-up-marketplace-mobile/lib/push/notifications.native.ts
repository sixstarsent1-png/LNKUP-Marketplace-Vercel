import { Platform } from "react-native"
import Constants from "expo-constants"
import * as Device from "expo-device"
import * as Notifications from "expo-notifications"

import { registerPushDevice, unregisterPushDevice } from "@/lib/api/push"
import type { WpAuth } from "@/lib/api/wp-client"
import * as SecureStore from "@/lib/storage/secure-store"

import {
  hrefFromNotificationData,
  navigateFromNotificationData,
} from "@/lib/push/notification-routing"

export { hrefFromNotificationData, navigateFromNotificationData }

const TOKEN_KEY = "lnkup_expo_push_token"

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowBanner: true,
    shouldShowList: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
  }),
})

/** Sync iOS/Android app icon badge to unread count (no-ops on web). */
export async function syncAppBadge(count: number): Promise<void> {
  try {
    await Notifications.setBadgeCountAsync(Math.max(0, Math.floor(count)))
  } catch {
    // Badge APIs may be unavailable on simulators / some Android builds.
  }
}

export async function clearAppBadge(): Promise<void> {
  await syncAppBadge(0)
}

function resolveProjectId(): string | undefined {
  const easId = Constants.easConfig?.projectId
  const extraId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas
    ?.projectId
  const id = easId || extraId
  if (!id || id === "replace-with-eas-project-id") {
    return undefined
  }
  return id
}

export async function loadStoredPushToken(): Promise<string | null> {
  try {
    return await SecureStore.getItemAsync(TOKEN_KEY)
  } catch {
    return null
  }
}

async function saveStoredPushToken(token: string | null): Promise<void> {
  try {
    if (token) {
      await SecureStore.setItemAsync(TOKEN_KEY, token)
    } else {
      await SecureStore.deleteItemAsync(TOKEN_KEY)
    }
  } catch {
    // SecureStore may be limited on web — ignore.
  }
}

/**
 * Request permission, obtain Expo push token, register with WordPress.
 * Gracefully no-ops on simulators without push or missing EAS projectId.
 */
export async function syncPushRegistration(auth?: WpAuth): Promise<string | null> {
  if (!Device.isDevice) {
    return null
  }

  const projectId = resolveProjectId()
  if (!projectId) {
    return null
  }

  const existing = await Notifications.getPermissionsAsync()
  let status = existing.status
  if (status !== "granted") {
    const requested = await Notifications.requestPermissionsAsync()
    status = requested.status
  }
  if (status !== "granted") {
    return null
  }

  try {
    const result = await Notifications.getExpoPushTokenAsync({ projectId })
    const token = result.data
    await saveStoredPushToken(token)
    await registerPushDevice(
      {
        token,
        platform: Platform.OS === "ios" ? "ios" : Platform.OS === "android" ? "android" : "unknown",
        appVersion: Constants.expoConfig?.version ?? "1.0.0",
      },
      auth,
    )
    return token
  } catch {
    return null
  }
}

/** Unregister stored token with WordPress and clear local storage. */
export async function clearPushRegistration(auth?: WpAuth): Promise<void> {
  const token = await loadStoredPushToken()
  if (token) {
    try {
      await unregisterPushDevice(token, auth)
    } catch {
      // Best-effort on logout.
    }
  }
  await saveStoredPushToken(null)
}
