"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { mapProfileChecklist, mockProfileChecklist, type ProfileChecklistItem } from "@/lib/api/profile-checklist"

export function useProfileChecklist(enabled = true) {
  const live = isLiveApiEnabled()
  const [items, setItems] = useState<ProfileChecklistItem[]>(live ? [] : mockProfileChecklist)
  const [completion, setCompletion] = useState(live ? 0 : 67)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchChecklist = useCallback(async () => {
    if (!live) {
      setItems(mockProfileChecklist)
      const done = mockProfileChecklist.filter((i) => i.done).length
      setCompletion(Math.round((done / mockProfileChecklist.length) * 100))
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/readiness")
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setItems(mockProfileChecklist)
        const done = mockProfileChecklist.filter((i) => i.done).length
        setCompletion(Math.round((done / mockProfileChecklist.length) * 100))
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "Could not load profile checklist.")
      }

      const checklist = (data.profileChecklist ?? mapProfileChecklist()) as ProfileChecklistItem[]
      setItems(checklist)
      const done = checklist.filter((i) => i.done).length
      setCompletion(checklist.length ? Math.round((done / checklist.length) * 100) : data.profileCompletion ?? 0)
    } catch (err) {
      console.error("[useProfileChecklist]", err)
      setError(err instanceof Error ? err.message : "Could not load profile checklist.")
      setItems(mockProfileChecklist)
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    if (!enabled) return
    fetchChecklist()
  }, [enabled, fetchChecklist])

  return {
    items,
    completion,
    loading,
    error,
    unauthenticated,
    retry: fetchChecklist,
    isLive: live,
  }
}
