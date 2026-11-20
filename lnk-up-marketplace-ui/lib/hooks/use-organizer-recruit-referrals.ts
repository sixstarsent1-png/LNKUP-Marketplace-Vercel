"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  mockOrganizerRecruit,
  type OrganizerRecruitState,
} from "@/lib/api/organizer-recruit-referrals"

export function useOrganizerRecruitReferrals(enabled = true) {
  const live = isLiveApiEnabled()
  const [state, setState] = useState<OrganizerRecruitState | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchDashboard = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setState(mockOrganizerRecruit)
      setLoading(false)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const response = await fetch("/api/referrals/organizer-recruit")
      const { data, error: apiError } =
        await parseApiResponse<OrganizerRecruitState>(response)

      if (apiError) {
        throw new Error(apiError.message)
      }

      setState(data)
    } catch (err) {
      console.error("[useOrganizerRecruitReferrals]", err)
      setError(err instanceof Error ? err.message : "Could not load Referral Invites.")
      setState(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  const ensureCode = useCallback(async () => {
    if (!live) {
      setState(mockOrganizerRecruit)
      return mockOrganizerRecruit
    }

    const response = await fetch("/api/referrals/organizer-recruit/code", {
      method: "POST",
    })
    const { error: apiError } = await parseApiResponse(response)
    if (apiError) throw new Error(apiError.message)
    await fetchDashboard()
    return null
  }, [live, fetchDashboard])

  useEffect(() => {
    void fetchDashboard()
  }, [fetchDashboard])

  return {
    state,
    loading,
    error,
    retry: fetchDashboard,
    ensureCode,
    isLive: live,
  }
}
