"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"
import { events, incomingRequests } from "@/lib/data"
import { mockOrganizerFinancial } from "@/lib/api/organizer-mock"
import { useOrganizerHomeContext } from "@/components/organizer/organizer-home-provider"
import {
  buildDefaultExperiments,
  buildDefaultRecommendations,
  buildDefaultSegmentDiagnostics,
} from "@/lib/ops/experimentation-contract"

function buildMockHome(): OrganizerHomeSummary {
  const pending = incomingRequests.filter((r) => r.status === "under-review").length
  const approved = incomingRequests.filter((r) =>
    ["approved", "payment-due", "paid"].includes(r.status),
  ).length
  const paid = incomingRequests.filter((r) => r.status === "paid").length
  const paymentRequired = incomingRequests.filter((r) =>
    ["approved", "payment-due"].includes(r.status),
  ).length
  const myEvents = events.filter((e) => e.organizer === "CultureWave Events")

  return {
    stats: {
      events: myEvents.length,
      openOpportunities: myEvents.filter((e) => e.status === "live").length,
      vendorRequests: incomingRequests.length,
      approvedVendors: approved,
      paymentRequired,
      paidVendors: paid,
    },
    upcomingEvents: myEvents.slice(0, 3).map((e) => ({
      id: e.id,
      title: e.name,
      date: e.date,
      city: e.city,
      state: e.state,
      status: e.status,
      requestsCount: e.vendorRequests,
      approvedCount: e.approvedVendors,
      paidCount: e.paidVendors,
    })),
    attentionItems: [
      ...(pending > 0
        ? [{
            id: "pending",
            label: `${pending} vendor requests awaiting review`,
            description: "Review applications to keep your vendor row on track.",
            href: "/organizer/vendors",
            severity: "warning" as const,
          }]
        : []),
      ...(paymentRequired > 0
        ? [{
            id: "pay",
            label: `${paymentRequired} approved vendors need payment`,
            href: "/organizer/vendors",
            severity: "info" as const,
          }]
        : []),
    ],
    launchChecklist: [
      { id: "1", label: "Create event", complete: true },
      { id: "2", label: "Add vendor opportunities", complete: true },
      { id: "3", label: "Share application link", complete: false },
      { id: "4", label: "Review vendor requests", complete: pending === 0 },
      { id: "5", label: "Approve vendors", complete: false },
      { id: "6", label: "Collect payments", complete: paid > 0 },
      { id: "7", label: "Release vendor packet", complete: false },
      { id: "8", label: "Confirm load-in details", complete: false },
    ],
    financial: mockOrganizerFinancial,
    intelligence: {
      diagnostics: buildDefaultSegmentDiagnostics(),
      experiments: buildDefaultExperiments(),
      recommendations: buildDefaultRecommendations(),
    },
  }
}

export function useOrganizerHomeInternal(enabled = true) {
  const live = isLiveApiEnabled()
  const [summary, setSummary] = useState<OrganizerHomeSummary | null>(null)
  const [loading, setLoading] = useState(live && enabled)
  const [error, setError] = useState<string | null>(null)
  const [unauthenticated, setUnauthenticated] = useState(false)
  const [forbidden, setForbidden] = useState(false)

  const fetchHome = useCallback(async () => {
    if (!live) {
      setSummary(buildMockHome())
      setLoading(false)
      setError(null)
      setUnauthenticated(false)
      setForbidden(false)
      return
    }

    setLoading(true)
    setError(null)
    setUnauthenticated(false)
    setForbidden(false)

    try {
      const response = await fetch("/api/organizer/home")
      const { data, error: apiError } = await parseApiResponse<OrganizerHomeSummary>(response)

      if (apiError) {
        if (response.status === 401) {
          setUnauthenticated(true)
          setSummary(null)
          return
        }
        if (response.status === 403) {
          setForbidden(true)
          setSummary(null)
          return
        }
        throw new Error(apiError.message)
      }

      setSummary(data)
    } catch (err) {
      console.error("[useOrganizerHome]", err)
      setError(err instanceof Error ? err.message : "We couldn't load your organizer summary.")
      setSummary(null)
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    if (!enabled) return
    fetchHome()
  }, [enabled, fetchHome])

  return {
    summary,
    loading,
    error,
    unauthenticated,
    forbidden,
    retry: fetchHome,
    isLive: live,
  }
}

export function useOrganizerHome(enabled = true) {
  const ctx = useOrganizerHomeContext()
  if (ctx) return ctx
  return useOrganizerHomeInternal(enabled)
}
