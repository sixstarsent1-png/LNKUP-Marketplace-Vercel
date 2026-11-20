"use client"

import { useCallback, useEffect, useMemo, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  countNotifications,
  isActionRequiredNotification,
  mockOrganizerNotifications,
  mockVendorNotifications,
  type LnkupNotification,
} from "@/lib/api/notifications"
import { getReadIds, markAllRead, markRead } from "@/lib/notifications/read-state"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import { useAuth } from "@/lib/hooks/use-auth"

export type NotificationFilter = "all" | "unread" | "action_required"

export function useNotifications(enabled = true) {
  const live = isLiveApiEnabled()
  const { user, isAuthenticated } = useAuth()
  const userId = user?.id
  const [items, setItems] = useState<LnkupNotification[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [readVersion, setReadVersion] = useState(0)

  const fetchNotifications = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems([...mockVendorNotifications, ...mockOrganizerNotifications])
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/notifications")
      const { data, error: apiError } = await parseApiResponse<{ items: LnkupNotification[] }>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setItems([])
          return
        }
        throw new Error(apiError.message)
      }

      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useNotifications]", err)
      setError(err instanceof Error ? err.message : "We couldn't load notifications.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchNotifications()
  }, [fetchNotifications])

  const mergedItems = useMemo(() => {
    if (!userId) return items
    const readIds = getReadIds(userId)
    return items.map((n) =>
      readIds.has(n.id) && !n.readAt ? { ...n, readAt: "read" } : n,
    )
  }, [items, userId, readVersion])

  const markNotificationRead = useCallback(
    async (id: string) => {
      if (userId) markRead(userId, id)
      setReadVersion((v) => v + 1)
      if (live && isAuthenticated) {
        await fetch(`/api/notifications/${encodeURIComponent(id)}/read`, { method: "POST" }).catch(() => null)
      }
    },
    [live, isAuthenticated, userId],
  )

  const markAllNotificationsRead = useCallback(async () => {
    const ids = mergedItems.filter((n) => !n.readAt).map((n) => n.id)
    if (userId) markAllRead(userId, ids)
    setReadVersion((v) => v + 1)
    if (live && isAuthenticated) {
      await fetch("/api/notifications/read-all", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ids }),
      }).catch(() => null)
    }
  }, [mergedItems, live, isAuthenticated, userId])

  const filterItems = useCallback(
    (filter: NotificationFilter) => {
      if (filter === "unread") return mergedItems.filter((n) => !n.readAt)
      if (filter === "action_required") {
        return mergedItems.filter((n) => !n.readAt && isActionRequiredNotification(n))
      }
      return mergedItems
    },
    [mergedItems],
  )

  return {
    items: mergedItems,
    loading,
    error,
    unauthenticated,
    retry: fetchNotifications,
    markRead: markNotificationRead,
    markAllRead: markAllNotificationsRead,
    filterItems,
    counts: countNotifications(mergedItems),
    isLive: live,
  }
}
