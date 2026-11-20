"use client"

import { useCallback, useEffect, useState } from "react"
import { useSearchParams } from "next/navigation"
import type {
  BffHealthCheck,
  MarketplaceFunnel,
  OpsAnalyticsRange,
  OpsAnalyticsSummary,
  PrelaunchStatus,
} from "@/lib/ops/types"

type SectionState = "loading" | "content" | "error" | "empty"

export function useOpsAnalytics() {
  const searchParams = useSearchParams()
  const forceError = searchParams.get("error") === "1"
  const forceEmpty = searchParams.get("empty") === "1"

  const [range, setRange] = useState<OpsAnalyticsRange>("7d")
  const [cityFilter, setCityFilter] = useState("")
  const [categoryFilter, setCategoryFilter] = useState("")
  const [statusFilter, setStatusFilter] = useState("")

  const [funnelState, setFunnelState] = useState<SectionState>("loading")
  const [summaryState, setSummaryState] = useState<SectionState>("loading")
  const [healthState, setHealthState] = useState<SectionState>("loading")
  const [prelaunchState, setPrelaunchState] = useState<SectionState>("loading")

  const [funnel, setFunnel] = useState<MarketplaceFunnel | null>(null)
  const [funnelMeta, setFunnelMeta] = useState<{
    mocked?: boolean
    partial?: boolean
    unavailableReasons?: string[]
  } | null>(null)
  const [summary, setSummary] = useState<OpsAnalyticsSummary | null>(null)
  const [healthChecks, setHealthChecks] = useState<BffHealthCheck[]>([])
  const [prelaunch, setPrelaunch] = useState<PrelaunchStatus | null>(null)
  const [errorMessage, setErrorMessage] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (forceError) {
      setFunnelState("error")
      setSummaryState("error")
      setHealthState("error")
      setPrelaunchState("error")
      setErrorMessage("Simulated error (?error=1)")
      return
    }

    setFunnelState("loading")
    setSummaryState("loading")
    setHealthState("loading")
    setPrelaunchState("loading")
    setErrorMessage(null)

    const qs = new URLSearchParams({ range })
    if (cityFilter) qs.set("city", cityFilter)
    if (categoryFilter) qs.set("category", categoryFilter)
    if (statusFilter) qs.set("status", statusFilter)

    try {
      const [funnelRes, summaryRes, healthRes, prelaunchRes] = await Promise.all([
        fetch(`/api/ops/analytics/funnel?range=${range}`, { cache: "no-store" }),
        fetch(`/api/ops/analytics/summary?${qs}`, { cache: "no-store" }),
        fetch("/api/ops/analytics/health", { cache: "no-store" }),
        fetch("/api/ops/analytics/prelaunch", { cache: "no-store" }),
      ])

      if (!funnelRes.ok || !summaryRes.ok || !healthRes.ok || !prelaunchRes.ok) {
        throw new Error("One or more analytics endpoints failed.")
      }

      const funnelData = await funnelRes.json()
      const summaryData = await summaryRes.json()
      const healthData = await healthRes.json()
      const prelaunchData = await prelaunchRes.json()

      setFunnel(funnelData.funnel)
      setFunnelMeta(funnelData.meta)
      setSummary(summaryData)
      setHealthChecks(healthData.checks ?? [])
      setPrelaunch(prelaunchData)

      if (forceEmpty) {
        setFunnelState("empty")
        setSummaryState("empty")
      } else {
        setFunnelState(funnelData.funnel?.steps?.length ? "content" : "empty")
        setSummaryState("content")
      }
      setHealthState(healthData.checks?.length ? "content" : "empty")
      setPrelaunchState("content")
    } catch (err) {
      setErrorMessage(err instanceof Error ? err.message : "Failed to load analytics.")
      setFunnelState("error")
      setSummaryState("error")
      setHealthState("error")
      setPrelaunchState("error")
    }
  }, [range, cityFilter, categoryFilter, statusFilter, forceError, forceEmpty])

  useEffect(() => {
    void load()
  }, [load])

  return {
    range,
    setRange,
    cityFilter,
    setCityFilter,
    categoryFilter,
    setCategoryFilter,
    statusFilter,
    setStatusFilter,
    funnel,
    funnelMeta,
    summary,
    healthChecks,
    prelaunch,
    funnelState,
    summaryState,
    healthState,
    prelaunchState,
    errorMessage,
    retry: load,
    isMocked: funnelMeta?.mocked ?? summary?.meta?.mocked ?? false,
    isPartial: funnelMeta?.partial ?? summary?.meta?.partial ?? false,
    unavailableReasons: [
      ...(funnelMeta?.unavailableReasons ?? []),
      ...(summary?.meta?.unavailableReasons ?? []),
    ],
  }
}
