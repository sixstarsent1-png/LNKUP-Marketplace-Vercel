"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockOrganizerEventMoney,
  type OrganizerEventMoneySummary,
} from "@/lib/api/organizer-financial"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useOrganizerEventMoney(eventId?: string, enabled = true) {
  const live = isLiveApiEnabled()
  const [summary, setSummary] = useState<OrganizerEventMoneySummary | null>(null)
  const [loading, setLoading] = useState(live && enabled && Boolean(eventId))
  const [error, setError] = useState<string | null>(null)

  const fetchEventMoney = useCallback(async () => {
    if (!enabled || !eventId) return

    if (!live) {
      setSummary({ ...mockOrganizerEventMoney, eventId })
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch(`/api/organizer/events/${eventId}/money`)
      const { data, error: apiError } = await parseApiResponse<{ summary: OrganizerEventMoneySummary }>(response)

      if (apiError) {
        throw new Error(apiError.message)
      }

      setSummary(data?.summary ?? null)
    } catch (err) {
      console.error("[useOrganizerEventMoney]", err)
      setError(err instanceof Error ? err.message : "We couldn't load event finances.")
      setSummary(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, eventId, live])

  useEffect(() => {
    fetchEventMoney()
  }, [fetchEventMoney])

  return { summary, loading, error, retry: fetchEventMoney, isLive: live }
}

export function useOrganizerMoneyEvents(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<OrganizerEventMoneySummary[]>([])
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchEvents = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setItems([mockOrganizerEventMoney])
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/organizer/money/events")
      const { data, error: apiError } = await parseApiResponse<{ items: OrganizerEventMoneySummary[] }>(response)

      if (apiError) {
        throw new Error(apiError.message)
      }

      setItems(data?.items ?? [])
    } catch (err) {
      console.error("[useOrganizerMoneyEvents]", err)
      setError(err instanceof Error ? err.message : "We couldn't load event finances.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchEvents()
  }, [fetchEvents])

  return { items, loading, error, retry: fetchEvents, isLive: live }
}
