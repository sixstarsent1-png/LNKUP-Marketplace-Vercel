"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type {
  EventVendorMatchesResult,
  InviteJob,
  InvitePreview,
  RecruitmentSummary,
  VendorMatchSection,
  VendorMatchSectionResult,
} from "@/lib/api/match"

type SectionState = Partial<Record<VendorMatchSection, VendorMatchSectionResult>>

/**
 * Loads vendor recommendation sections in parallel. Failed sections are dropped
 * (never block the page); a disabled engine yields an empty map.
 */
export function useVendorMatchSections(sections: readonly VendorMatchSection[], enabled: boolean) {
  const live = isLiveApiEnabled()
  const [results, setResults] = useState<SectionState>({})
  const [loading, setLoading] = useState(live && enabled)
  const key = sections.join(",")

  useEffect(() => {
    if (!live || !enabled) {
      setResults({})
      setLoading(false)
      return
    }
    let cancelled = false
    setLoading(true)
    Promise.allSettled(
      key.split(",").map(async (section) => {
        const response = await fetch(`/api/match/vendor/recommendations?section=${encodeURIComponent(section)}&per_page=8`)
        const { data, error } = await parseApiResponse<VendorMatchSectionResult>(response)
        if (error || !data) throw new Error(error?.message ?? "failed")
        return [section, data] as const
      }),
    ).then((settled) => {
      if (cancelled) return
      const next: SectionState = {}
      settled.forEach((result) => {
        if (result.status === "fulfilled") {
          const [section, data] = result.value
          next[section as VendorMatchSection] = data
        } else {
          console.error("[useVendorMatchSections]", result.reason)
        }
      })
      setResults(next)
      setLoading(false)
    })
    return () => {
      cancelled = true
    }
  }, [live, enabled, key])

  return { results, loading }
}

/**
 * Whether organizer Find Vendors is switched on (flag + rollout audience).
 * Stays false while loading or when the check fails, so recruit entry points stay hidden.
 */
export function useFindVendorsEnabled(): boolean {
  const live = isLiveApiEnabled()
  const [enabled, setEnabled] = useState(false)

  useEffect(() => {
    if (!live) return
    let cancelled = false
    fetch("/api/match/organizer/status")
      .then((response) => parseApiResponse<{ findVendorsEnabled: boolean }>(response))
      .then(({ data }) => {
        if (!cancelled) setEnabled(data?.findVendorsEnabled === true)
      })
      .catch((err) => {
        console.error("[useFindVendorsEnabled]", err)
      })
    return () => {
      cancelled = true
    }
  }, [live])

  return enabled
}

export interface EventVendorFilters {
  opportunityId?: string
  category?: string
  city?: string
  state?: string
  verified?: boolean
  radius?: number
  excludeApplied?: boolean
  page?: number
}

export function useEventVendorMatches(eventId: string | null, filters: EventVendorFilters) {
  const [result, setResult] = useState<EventVendorMatchesResult | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const filterKey = JSON.stringify(filters)

  const load = useCallback(async () => {
    if (!eventId) {
      setResult(null)
      return
    }
    setLoading(true)
    setError(null)
    try {
      const parsedFilters = JSON.parse(filterKey) as EventVendorFilters
      const qs = new URLSearchParams()
      if (parsedFilters.opportunityId) qs.set("opportunity_id", parsedFilters.opportunityId)
      if (parsedFilters.category) qs.set("category", parsedFilters.category)
      if (parsedFilters.city) qs.set("city", parsedFilters.city)
      if (parsedFilters.state) qs.set("state", parsedFilters.state)
      if (parsedFilters.verified) qs.set("verified", "1")
      if (parsedFilters.radius) qs.set("radius", String(parsedFilters.radius))
      qs.set("exclude_applied", parsedFilters.excludeApplied === false ? "0" : "1")
      if (parsedFilters.page) qs.set("page", String(parsedFilters.page))
      const response = await fetch(`/api/match/organizer/events/${encodeURIComponent(eventId)}/vendors?${qs.toString()}`)
      const { data, error: apiError } = await parseApiResponse<EventVendorMatchesResult>(response)
      if (apiError || !data) throw new Error(apiError?.message ?? "We couldn't load matching vendors.")
      setResult(data)
    } catch (err) {
      console.error("[useEventVendorMatches]", err)
      setError(err instanceof Error ? err.message : "We couldn't load matching vendors.")
      setResult(null)
    } finally {
      setLoading(false)
    }
  }, [eventId, filterKey])

  useEffect(() => {
    void load()
  }, [load])

  return { result, loading, error, retry: load }
}

export function useRecruitment(eventId: string | null) {
  const [summary, setSummary] = useState<RecruitmentSummary | null>(null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!eventId) {
      setSummary(null)
      return
    }
    setError(null)
    try {
      const response = await fetch(`/api/match/organizer/events/${encodeURIComponent(eventId)}/recruitment`)
      const { data, error: apiError } = await parseApiResponse<RecruitmentSummary>(response)
      if (apiError || !data) throw new Error(apiError?.message ?? "failed")
      setSummary(data)
    } catch (err) {
      console.error("[useRecruitment]", err)
      setError("We couldn't load recruitment tips.")
      setSummary(null)
    }
  }, [eventId])

  useEffect(() => {
    void load()
  }, [load])

  return { summary, error, retry: load }
}

export async function requestInvitePreview(body: {
  eventId: string
  opportunityId?: string
  profileIds: string[]
  message: string
}): Promise<InvitePreview> {
  const response = await fetch("/api/match/organizer/invites", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      mode: "preview",
      eventId: Number(body.eventId),
      opportunityId: body.opportunityId ? Number(body.opportunityId) : 0,
      profileIds: body.profileIds.map(Number),
      message: body.message,
    }),
  })
  const { data, error } = await parseApiResponse<{ preview: InvitePreview }>(response)
  if (error || !data) throw new Error(error?.message ?? "We couldn't preview these invites.")
  return data.preview
}

export async function sendBatchInvites(body: {
  eventId: string
  opportunityId?: string
  profileIds: string[]
  message: string
}): Promise<InviteJob> {
  const response = await fetch("/api/match/organizer/invites", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      mode: "send",
      eventId: Number(body.eventId),
      opportunityId: body.opportunityId ? Number(body.opportunityId) : 0,
      profileIds: body.profileIds.map(Number),
      message: body.message,
    }),
  })
  const { data, error } = await parseApiResponse<{ job: InviteJob }>(response)
  if (error || !data) throw new Error(error?.message ?? "We couldn't send these invites.")
  return data.job
}

export async function fetchInviteJob(jobId: string): Promise<InviteJob> {
  const response = await fetch(`/api/match/organizer/invites/jobs/${encodeURIComponent(jobId)}`)
  const { data, error } = await parseApiResponse<{ job: InviteJob }>(response)
  if (error || !data) throw new Error(error?.message ?? "We couldn't load invite progress.")
  return data.job
}

export async function updateShortlist(eventId: string, profileIds: string[], action: "add" | "remove") {
  const response = await fetch("/api/match/organizer/shortlist", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ eventId: Number(eventId), profileIds: profileIds.map(Number), action }),
  })
  const { error } = await parseApiResponse<{ updated: number[] }>(response)
  if (error) throw new Error(error.message)
}
