"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { OrganizerOnboardingState, OrganizerOnboardingStep } from "@/lib/api/organizer-onboarding"
import { buildMockOnboardingState } from "@/lib/api/organizer-onboarding"

export function useOrganizerOnboarding() {
  const live = isLiveApiEnabled()
  const [state, setState] = useState<OrganizerOnboardingState>(() =>
    live ? { currentStep: "organizer_basics", completedSteps: [], launchReadiness: 0 } : buildMockOnboardingState(),
  )
  const [loading, setLoading] = useState(live)
  const [error, setError] = useState<string | null>(null)
  const [saving, setSaving] = useState(false)

  const load = useCallback(async () => {
    if (!live) {
      setState(buildMockOnboardingState())
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    try {
      const response = await fetch("/api/organizer/onboarding")
      const { data, error: apiError } = await parseApiResponse<OrganizerOnboardingState>(response)
      if (apiError) throw new Error(apiError.message)
      setState(data ?? buildMockOnboardingState())
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not load onboarding")
    } finally {
      setLoading(false)
    }
  }, [live])

  const advance = useCallback(
    async (opts?: {
      step?: OrganizerOnboardingStep
      draft?: OrganizerOnboardingState["draft"]
      skip?: boolean
      complete?: boolean
    }) => {
      setSaving(true)
      setError(null)
      try {
        const response = await fetch("/api/organizer/onboarding", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({
            action: opts?.complete ? "complete" : "advance",
            step: opts?.step ?? state.currentStep,
            draft: opts?.draft,
            skip: opts?.skip,
          }),
        })
        const { data, error: apiError } = await parseApiResponse<OrganizerOnboardingState>(response)
        if (apiError) throw new Error(apiError.message)
        if (data) setState(data)
        return data
      } catch (err) {
        setError(err instanceof Error ? err.message : "Could not save progress")
        throw err
      } finally {
        setSaving(false)
      }
    },
    [state.currentStep],
  )

  useEffect(() => {
    load()
  }, [load])

  return { state, loading, error, saving, reload: load, advance }
}
