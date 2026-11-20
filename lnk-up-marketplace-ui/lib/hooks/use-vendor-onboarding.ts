"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { VendorOnboardingState, VendorOnboardingStep } from "@/lib/api/vendor-onboarding"
import { mockVendorOnboarding } from "@/lib/api/vendor-onboarding-mock"

export function useVendorOnboarding(enabled = true) {
  const live = isLiveApiEnabled()
  const [state, setState] = useState<VendorOnboardingState | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [saving, setSaving] = useState(false)

  const fetchOnboarding = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setState(mockVendorOnboarding)
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/onboarding")
      const { data, error: apiError } = await parseApiResponse<VendorOnboardingState>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setState(null)
          return
        }
        throw new Error(apiError.message)
      }

      setState(data)
    } catch (err) {
      console.error("[useVendorOnboarding]", err)
      setError(err instanceof Error ? err.message : "Could not load onboarding.")
      setState(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  const saveStep = useCallback(
    async (step: VendorOnboardingStep, action: "complete" | "skip") => {
      if (!live) {
        setState((prev) =>
          prev
            ? {
                ...prev,
                completedSteps: action === "complete" ? [...prev.completedSteps, step] : prev.completedSteps,
                currentStep: step === "first_search" ? "complete" : "categories",
              }
            : prev,
        )
        return { success: true }
      }

      setSaving(true)
      try {
        const response = await fetch("/api/vendor/onboarding", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ step, action }),
        })
        const { data, error: apiError } = await parseApiResponse<{
          success?: boolean
          state?: VendorOnboardingState
        }>(response)
        if (apiError) throw new Error(apiError.message)
        if (data?.state) setState(data.state)
        await fetchOnboarding()
        return { success: true }
      } catch (err) {
        console.error("[useVendorOnboarding] save", err)
        return { success: false }
      } finally {
        setSaving(false)
      }
    },
    [live, fetchOnboarding],
  )

  useEffect(() => {
    fetchOnboarding()
  }, [fetchOnboarding])

  return {
    state,
    loading,
    error,
    unauthenticated,
    saving,
    retry: fetchOnboarding,
    saveStep,
    isLive: live,
    isComplete: state?.currentStep === "complete",
  }
}
