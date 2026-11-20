"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  mockVendorPacketLocked,
  mockVendorPacketUnlocked,
  type VendorEventPass,
  type VendorPacketPreview,
} from "@/lib/api/vendor-packet"

export function useVendorPacket(applicationId?: string, options?: { forceLocked?: boolean }) {
  const live = isLiveApiEnabled()
  const [packet, setPacket] = useState<VendorEventPass | null>(null)
  const [loading, setLoading] = useState(Boolean(applicationId) && live)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)

  const fetchPacket = useCallback(async () => {
    if (!applicationId) return

    if (!live) {
      const mock =
        applicationId === "req-1" || applicationId === "charlotte-night-market"
          ? mockVendorPacketUnlocked
          : mockVendorPacketLocked
      const result = options?.forceLocked
        ? { ...mock, status: "locked" as const, headline: mockVendorPacketLocked.headline, message: mockVendorPacketLocked.message }
        : mock
      setPacket({ ...result, requestId: applicationId, applicationId })
      setError(null)
      setUnauthenticated(false)
      setLoading(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)

    try {
      const response = await fetch(`/api/vendor/requests/${applicationId}/packet`)
      const data = await response.json()

      if (response.status === 401) {
        setUnauthenticated(true)
        setPacket(null)
        return
      }

      if (!response.ok) {
        throw new Error(data.message || "We couldn't load your event pass.")
      }

      let result = data as VendorEventPass
      if (options?.forceLocked) {
        result = {
          ...result,
          status: "locked",
          headline: result.headline || "Your vendor pass unlocks after payment",
          message: result.message || "Your vendor pass unlocks after payment.",
        }
      }
      setPacket(result)
    } catch (err) {
      console.error("[useVendorPacket]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your event pass.")
      setPacket(null)
    } finally {
      setLoading(false)
    }
  }, [applicationId, live, options?.forceLocked])

  useEffect(() => {
    if (!applicationId) {
      setPacket(null)
      setLoading(false)
      return
    }
    fetchPacket()
  }, [applicationId, fetchPacket])

  return {
    packet,
    loading,
    error,
    unauthenticated,
    retry: fetchPacket,
    isLive: live,
  }
}
