"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockNotificationPreferences,
  type NotificationPreferences,
} from "@/lib/api/notifications"
import { parseApiResponse } from "@/lib/api/parse-api-error"

export function useNotificationPreferences(enabled = true) {
  const live = isLiveApiEnabled()
  const [preferences, setPreferences] = useState<NotificationPreferences | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [saving, setSaving] = useState(false)

  const fetchPreferences = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setPreferences(mockNotificationPreferences)
      setLoading(false)
      setError(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/notifications/preferences")
      const { data, error: apiError } = await parseApiResponse<NotificationPreferences>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setPreferences(null)
          return
        }
        throw new Error(apiError.message)
      }

      setPreferences(data ?? null)
    } catch (err) {
      console.error("[useNotificationPreferences]", err)
      setError(err instanceof Error ? err.message : "We couldn't load preferences.")
      setPreferences(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  const updatePreference = useCallback(
    async (itemId: string, channel: string, enabled: boolean) => {
      if (!live) {
        setPreferences((prev) => {
          if (!prev) return prev
          return {
            ...prev,
            items: prev.items.map((item) =>
              item.id === itemId
                ? { ...item, channels: { ...item.channels, [channel]: enabled } }
                : item,
            ),
          }
        })
        return
      }

      setSaving(true)
      try {
        const response = await fetch("/api/notifications/preferences", {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ itemId, channel, enabled }),
        })
        const { data, error: apiError } = await parseApiResponse<NotificationPreferences>(response)
        if (apiError) throw new Error(apiError.message)
        if (data) setPreferences(data)
      } finally {
        setSaving(false)
      }
    },
    [live],
  )

  useEffect(() => {
    fetchPreferences()
  }, [fetchPreferences])

  return {
    preferences,
    loading,
    error,
    unauthenticated,
    saving,
    retry: fetchPreferences,
    updatePreference,
    isLive: live,
  }
}
