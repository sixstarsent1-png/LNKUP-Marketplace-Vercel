import { isLiveApiEnabled } from "@/lib/api/config"
import { fetchOrganizerHome } from "@/lib/api/organizer-home"
import { fetchOrganizerRequests } from "@/lib/api/organizer-requests"
import { fetchVendorReadiness } from "@/lib/api/vendor-readiness"
import { fetchVendorRequests } from "@/lib/api/vendor-requests"
import { fetchVendorReviews } from "@/lib/api/vendor-reviews"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import {
  composeOrganizerNotifications,
  composeVendorNotifications,
} from "@/lib/notifications/compose"
import { mergeReadState } from "@/lib/notifications/read-state"
import type { LnkupNotification } from "@/lib/notifications/types"
import { MOCK_ORGANIZER_NOTIFICATIONS, MOCK_VENDOR_NOTIFICATIONS } from "@/lib/data/fixtures"

export type NotificationRole = "vendor" | "organizer"

export async function fetchNotifications(
  role: NotificationRole,
  options: { auth?: WpAuth; userId?: string | number } = {},
): Promise<LnkupNotification[]> {
  if (!isLiveApiEnabled()) {
    const fixtures =
      role === "organizer" ? MOCK_ORGANIZER_NOTIFICATIONS : MOCK_VENDOR_NOTIFICATIONS
    return mergeReadState(
      options.userId,
      fixtures.map((n) => ({ ...n })),
    )
  }

  const { auth, userId } = options

  if (role === "organizer") {
    const [home, requests] = await Promise.all([
      fetchOrganizerHome(auth).catch(() => null),
      fetchOrganizerRequests(auth).catch(() => ({ items: [], total: 0 })),
    ])
    const pending = requests.items.filter(
      (r) => r.status === "under_review" || r.status === "submitted",
    )
    const composed = composeOrganizerNotifications({
      homeSummary: home,
      pendingRequests: pending,
    })
    return mergeServerInbox(composed, auth, userId)
  }

  const [requests, readiness, reviews, opsHome] = await Promise.all([
    fetchVendorRequests(auth).catch(() => ({ items: [], total: 0 })),
    fetchVendorReadiness(auth).catch(() => null),
    fetchVendorReviews(auth).catch(() => null),
    wpFetchJson<{ unread_broadcasts?: number }>("/operations/vendor-home", { auth }).catch(
      () => null,
    ),
  ])

  const composed = composeVendorNotifications({
    requests: requests.items,
    readiness,
    reviews,
    unreadBroadcasts: opsHome?.unread_broadcasts ?? 0,
  })
  return mergeServerInbox(composed, auth, userId)
}

type WpInboxPayload = {
  items?: LnkupNotification[]
  readIds?: string[]
}

async function mergeServerInbox(
  composed: LnkupNotification[],
  auth?: WpAuth,
  userId?: string | number,
): Promise<LnkupNotification[]> {
  const local = await mergeReadState(userId, composed)
  if (!auth) return local

  try {
    const inbox = await wpFetchJson<WpInboxPayload>("/notifications/inbox", { auth })
    const readIds = new Set(inbox.readIds ?? [])
    const byId = new Map<string, LnkupNotification>()
    for (const item of inbox.items ?? []) {
      if (item.id) byId.set(item.id, item)
    }
    for (const item of local) {
      const existing = byId.get(item.id)
      byId.set(item.id, {
        ...item,
        ...existing,
        title: item.title || existing?.title || "",
        readAt: existing?.readAt || (readIds.has(item.id) ? "read" : item.readAt),
      })
    }
    return Array.from(byId.values())
  } catch {
    return local
  }
}

export async function markInboxRead(
  notificationId: string,
  auth?: WpAuth,
): Promise<void> {
  if (!auth || !isLiveApiEnabled()) return
  await wpFetchJson(`/notifications/inbox/${encodeURIComponent(notificationId)}/read`, {
    method: "POST",
    auth,
    body: {},
  }).catch(() => null)
}

export async function markInboxReadAll(
  ids: string[],
  auth?: WpAuth,
): Promise<void> {
  if (!auth || !isLiveApiEnabled()) return
  await wpFetchJson("/notifications/inbox/read-all", {
    method: "POST",
    auth,
    body: { ids },
  }).catch(() => null)
}
