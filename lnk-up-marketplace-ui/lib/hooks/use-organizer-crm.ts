"use client"

import { useCallback, useEffect, useState } from "react"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type {
  OrganizerInviteSummary,
  OrganizerListSummary,
  OrganizerVendorDirectoryItem,
} from "@/lib/api/organizer-crm"

export function useOrganizerVendorDirectory(enabled = true) {
  const [items, setItems] = useState<OrganizerVendorDirectoryItem[]>([])
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchDirectory = useCallback(async (query?: {
    q?: string
    category?: string
    city?: string
    sort?: string
    verified?: boolean
  }) => {
    if (!enabled) return
    setLoading(true)
    setError(null)
    try {
      const params = new URLSearchParams()
      if (query?.q) params.set("q", query.q)
      if (query?.category) params.set("category", query.category)
      if (query?.city) params.set("city", query.city)
      if (query?.sort) params.set("sort", query.sort)
      if (query?.verified) params.set("verified", "1")
      const response = await fetch(`/api/organizer/vendors${params.toString() ? `?${params.toString()}` : ""}`)
      const { data, error: apiError } = await parseApiResponse<{ items?: OrganizerVendorDirectoryItem[] }>(response)
      if (apiError) throw new Error(apiError.message)
      setItems(data?.items ?? [])
    } catch (err) {
      setError(err instanceof Error ? err.message : "We couldn't load vendors.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled) return
    fetchDirectory()
  }, [enabled, fetchDirectory])

  return { items, loading, error, retry: fetchDirectory }
}

export function useOrganizerLists(enabled = true) {
  const [items, setItems] = useState<OrganizerListSummary[]>([])
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchLists = useCallback(async () => {
    if (!enabled) return
    setLoading(true)
    setError(null)
    try {
      const response = await fetch("/api/organizer/lists")
      const { data, error: apiError } = await parseApiResponse<{ items?: OrganizerListSummary[] }>(response)
      if (apiError) throw new Error(apiError.message)
      setItems(data?.items ?? [])
    } catch (err) {
      setError(err instanceof Error ? err.message : "We couldn't load lists.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled) return
    fetchLists()
  }, [enabled, fetchLists])

  return { items, loading, error, retry: fetchLists }
}

export function useOrganizerInvites(enabled = true) {
  const [items, setItems] = useState<OrganizerInviteSummary[]>([])
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<string | null>(null)

  const fetchInvites = useCallback(async () => {
    if (!enabled) return
    setLoading(true)
    setError(null)
    try {
      const response = await fetch("/api/organizer/invites")
      const { data, error: apiError } = await parseApiResponse<{ items?: OrganizerInviteSummary[] }>(response)
      if (apiError) throw new Error(apiError.message)
      setItems(data?.items ?? [])
    } catch (err) {
      setError(err instanceof Error ? err.message : "We couldn't load invites.")
      setItems([])
    } finally {
      setLoading(false)
    }
  }, [enabled])

  useEffect(() => {
    if (!enabled) return
    fetchInvites()
  }, [enabled, fetchInvites])

  return { items, loading, error, retry: fetchInvites }
}

