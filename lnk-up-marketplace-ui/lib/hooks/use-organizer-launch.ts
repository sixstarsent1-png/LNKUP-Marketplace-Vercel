"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  type OrganizerLaunchDraft,
  type OrganizerLaunchPublishResult,
  buildMockLaunchDraft,
  emptyLaunchDraft,
} from "@/lib/api/organizer-launch"

export function useOrganizerLaunch() {
  const live = isLiveApiEnabled()
  const [draft, setDraft] = useState<OrganizerLaunchDraft>(() =>
    live ? emptyLaunchDraft() : buildMockLaunchDraft(),
  )
  const [loading, setLoading] = useState(live)
  const [saving, setSaving] = useState(false)
  const [publishing, setPublishing] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [publishResult, setPublishResult] = useState<OrganizerLaunchPublishResult | null>(null)

  const loadDraft = useCallback(async () => {
    if (!live) {
      setDraft(buildMockLaunchDraft())
      setLoading(false)
      return
    }
    setLoading(true)
    try {
      const response = await fetch("/api/organizer/launch/draft")
      const { data, error: apiError } = await parseApiResponse<OrganizerLaunchDraft>(response)
      if (apiError) throw new Error(apiError.message)
      setDraft(data ?? emptyLaunchDraft())
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not load draft")
    } finally {
      setLoading(false)
    }
  }, [live])

  const saveDraft = useCallback(async (next: OrganizerLaunchDraft) => {
    setSaving(true)
    setError(null)
    try {
      const response = await fetch("/api/organizer/launch/draft", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(next),
      })
      const { data, error: apiError } = await parseApiResponse<OrganizerLaunchDraft>(response)
      if (apiError) throw new Error(apiError.message)
      if (data) setDraft(data)
      return data
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save draft")
      throw err
    } finally {
      setSaving(false)
    }
  }, [])

  const publish = useCallback(async (next?: OrganizerLaunchDraft) => {
    setPublishing(true)
    setError(null)
    setPublishResult(null)
    try {
      const response = await fetch("/api/organizer/launch/publish", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(next ?? draft),
      })
      const payload = (await response.json()) as OrganizerLaunchPublishResult
      if (!response.ok && payload.status !== "handoff_required" && payload.status !== "validation_error") {
        throw new Error(payload.message || "Publish failed")
      }
      setPublishResult(payload)
      return payload
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not publish")
      throw err
    } finally {
      setPublishing(false)
    }
  }, [draft])

  useEffect(() => {
    loadDraft()
  }, [loadDraft])

  return {
    draft,
    setDraft,
    loading,
    saving,
    publishing,
    error,
    publishResult,
    saveDraft,
    publish,
    reload: loadDraft,
  }
}
