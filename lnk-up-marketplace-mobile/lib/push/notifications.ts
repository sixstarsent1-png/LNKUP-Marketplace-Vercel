import type { WpAuth } from "@/lib/api/wp-client"

export {
  hrefFromNotificationData,
  navigateFromNotificationData,
} from "@/lib/push/notification-routing"

/** Web / default: badge APIs are native-only. */
export async function syncAppBadge(_count: number): Promise<void> {}

export async function clearAppBadge(): Promise<void> {}

export async function loadStoredPushToken(): Promise<string | null> {
  return null
}

export async function syncPushRegistration(_auth?: WpAuth): Promise<string | null> {
  return null
}

export async function clearPushRegistration(_auth?: WpAuth): Promise<void> {}
