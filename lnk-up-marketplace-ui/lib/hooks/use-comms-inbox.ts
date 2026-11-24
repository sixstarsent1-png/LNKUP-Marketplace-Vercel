"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import type { CommsInboxCount, CommsInboxResponse, InboxTab } from "@/lib/api/comms"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { LnkupNotification } from "@/lib/api/notifications"
import { parseApiResponse } from "@/lib/api/parse-api-error"

/**
 * Categorized inbox from lnkup-communications. `available` becomes false when the plugin
 * is not installed, so the page can keep its composed feed.
 */
export function useCommsInbox(role: "vendor" | "organizer") {
  const live = isLiveApiEnabled()
  const [available, setAvailable] = useState<boolean | null>(live ? null : false)
  const [tab, setTab] = useState<InboxTab>("all")
  const [tabs, setTabs] = useState<InboxTab[]>([])
  const [items, setItems] = useState<LnkupNotification[]>([])
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loading, setLoading] = useState(live)
  const [error, setError] = useState<string | null>(null)
  const [counts, setCounts] = useState<Partial<Record<InboxTab, number>>>({})
  const [markingAll, setMarkingAll] = useState(false)
  const request = useRef(0)

  const refreshCounts = useCallback(async () => {
    if (!live) return
    try {
      const response = await fetch(`/api/notifications/inbox/count?role=${role}`)
      const { data } = await parseApiResponse<CommsInboxCount>(response)
      if (data?.available) setCounts(data.byTab)
    } catch (err) {
      console.error("[useCommsInbox] counts", err)
    }
  }, [live, role])

  const load = useCallback(
    async (nextTab: InboxTab, nextPage = 1) => {
      if (!live) return
      const current = ++request.current
      if (nextPage === 1) setLoading(true)
      setError(null)
      try {
        const response = await fetch(`/api/notifications/inbox?role=${role}&tab=${nextTab}&page=${nextPage}`)
        const { data, error: apiError } = await parseApiResponse<CommsInboxResponse>(response)
        if (apiError) throw new Error(apiError.message)
        if (current !== request.current || !data) return
        setAvailable(data.available)
        if (!data.available) return
        setTabs(data.tabs)
        setItems((prev) => {
          if (nextPage === 1) return data.items
          const seen = new Set(prev.map((n) => n.id))
          return [...prev, ...data.items.filter((n) => !seen.has(n.id))]
        })
        setPage(data.page)
        setHasMore(data.hasMore)
        if (nextPage === 1) void refreshCounts()
      } catch (err) {
        console.error("[useCommsInbox]", err)
        if (current === request.current) setError(err instanceof Error ? err.message : "We couldn't load notifications.")
      } finally {
        if (current === request.current) setLoading(false)
      }
    },
    [live, refreshCounts, role],
  )

  useEffect(() => {
    void load(tab, 1)
  }, [load, tab])

  // Read on another device or in the app while this tab was in the background.
  useEffect(() => {
    if (!live) return
    const onFocus = () => {
      if (document.visibilityState === "visible") void refreshCounts()
    }
    document.addEventListener("visibilitychange", onFocus)
    window.addEventListener("focus", onFocus)
    return () => {
      document.removeEventListener("visibilitychange", onFocus)
      window.removeEventListener("focus", onFocus)
    }
  }, [live, refreshCounts])

  const markRead = useCallback(
    (id: string) => {
      setItems((prev) => prev.map((n) => (n.id === id ? { ...n, readAt: n.readAt ?? new Date().toISOString() } : n)))
      void fetch(`/api/notifications/${encodeURIComponent(id)}/read`, { method: "POST" })
        .then(() => refreshCounts())
        .catch(() => undefined)
    },
    [refreshCounts],
  )

  const markAll = useCallback(async () => {
    if (markingAll) return
    setMarkingAll(true)
    const previous = items
    const now = new Date().toISOString()
    setItems((prev) => prev.map((n) => (n.readAt ? n : { ...n, readAt: now })))
    try {
      const response = await fetch("/api/notifications/inbox/read-all", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ role, tab }),
      })
      const { error: apiError } = await parseApiResponse<{ ok: boolean }>(response)
      if (apiError) throw new Error(apiError.message)
      await refreshCounts()
    } catch (err) {
      console.error("[useCommsInbox] mark all", err)
      setItems(previous)
      setError("We couldn't mark these notifications as read. Try again.")
    } finally {
      setMarkingAll(false)
    }
  }, [items, markingAll, refreshCounts, role, tab])

  return {
    role,
    available,
    tab,
    setTab,
    tabs,
    items,
    loading,
    error,
    hasMore,
    counts,
    markingAll,
    unreadInTab: counts[tab] ?? items.filter((n) => !n.readAt).length,
    loadMore: () => void load(tab, page + 1),
    retry: () => void load(tab, 1),
    markRead,
    markAll: () => void markAll(),
  }
}
