"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  mapVendorProfile,
  mockVendorProfileEdit,
  type VendorProfileEdit,
} from "@/lib/api/vendor-profile"

export function useVendorProfile(enabled = true) {
  const live = isLiveApiEnabled()
  const [profile, setProfile] = useState<VendorProfileEdit | null>(live ? null : mockVendorProfileEdit())
  const [loading, setLoading] = useState(enabled)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchProfile = useCallback(async () => {
    if (!enabled) return

    if (!live) {
      setProfile(mockVendorProfileEdit())
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch("/api/vendor/profile")
      const { data, error: apiError } = await parseApiResponse<VendorProfileEdit & Record<string, unknown>>(
        response,
      )

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setProfile(null)
          return
        }
        throw new Error(apiError.message)
      }

      setProfile(data ? mapVendorProfile(data) : null)
    } catch (err) {
      console.error("[useVendorProfile]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your profile.")
      setProfile(null)
    } finally {
      setLoading(false)
    }
  }, [enabled, live])

  useEffect(() => {
    fetchProfile()
  }, [fetchProfile])

  const saveProfile = useCallback(
    async (patch: Partial<Omit<VendorProfileEdit, "profileId">>) => {
      setSaving(true)
      setSaveError(null)
      try {
        const response = await fetch("/api/vendor/profile", {
          method: "PATCH",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(patch),
        })
        const { data, error: apiError } = await parseApiResponse<{ success?: boolean; message?: string }>(
          response,
        )
        if (apiError) {
          throw new Error(apiError.message)
        }
        await fetchProfile()
        return { success: data?.success !== false, message: data?.message }
      } catch (err) {
        const message = err instanceof Error ? err.message : "Could not save your profile."
        setSaveError(message)
        return { success: false, message }
      } finally {
        setSaving(false)
      }
    },
    [fetchProfile],
  )

  return {
    profile,
    loading,
    saving,
    error,
    saveError,
    unauthenticated,
    retry: fetchProfile,
    saveProfile,
    isLive: live,
  }
}
