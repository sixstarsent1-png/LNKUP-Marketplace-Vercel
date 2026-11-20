"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  countNotifications,
  mockOrganizerNotifications,
  mockVendorNotifications,
  type NotificationCount,
} from "@/lib/api/notifications"
import { getReadIds } from "@/lib/notifications/read-state"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import { useAuth } from "@/lib/hooks/use-auth"

export function useNotificationCount(enabled = true) {
  const live = isLiveApiEnabled()
  const { user, isAuthenticated } = useAuth()
  const userId = user?.id
  const [count, setCount] = useState<NotificationCount>({ unread: 0 })
  const [loading, setLoading] = useState(live && enabled)

  const refresh = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      const items = [...mockVendorNotifications, ...mockOrganizerNotifications]
      setCount(countNotifications(items))
      setLoading(false)
      return
    }

    if (!isAuthenticated) {
      setCount({ unread: 0 })
      setLoading(false)
      return
    }

    setLoading(true)
    try {
      const [countRes, listRes] = await Promise.all([
        fetch("/api/notifications/count"),
        fetch("/api/notifications"),
      ])

      if (countRes.status === 401) {
        setCount({ unread: 0 })
        return
      }

      const listParsed = await parseApiResponse<{ items: Array<{ id: string; readAt?: string }> }>(listRes)
      const items = listParsed.data?.items ?? []

      if (userId) {
        const readIds = getReadIds(userId)
        const unread = items.filter((n) => !n.readAt && !readIds.has(n.id)).length
        setCount({ unread, updatedAt: new Date().toISOString() })
      } else {
        const parsed = await parseApiResponse<NotificationCount>(countRes)
        setCount(parsed.data ?? { unread: 0 })
      }
    } catch {
      setCount({ unread: 0 })
    } finally {
      setLoading(false)
    }
  }, [enabled, live, isAuthenticated, userId])

  useEffect(() => {
    refresh()
  }, [refresh])

  return { count, loading, refresh, isLive: live }
}
