"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  type OrganizerEventWorkspace,
  buildMockEventWorkspace,
} from "@/lib/api/organizer-event-workspace"

export function useOrganizerEventWorkspace(eventId: string) {
  const live = isLiveApiEnabled()
  const [workspace, setWorkspace] = useState<OrganizerEventWorkspace | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [notFound, setNotFound] = useState(false)
  const [forbidden, setForbidden] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    setNotFound(false)
    setForbidden(false)

    if (!live) {
      const mock = buildMockEventWorkspace(eventId)
      if (!mock) {
        setNotFound(true)
        setWorkspace(null)
      } else {
        setWorkspace(mock)
      }
      setLoading(false)
      return
    }

    try {
      const response = await fetch(`/api/organizer/events/${encodeURIComponent(eventId)}`)
      const { data, error: apiError } = await parseApiResponse<OrganizerEventWorkspace>(response)
      if (response.status === 404) {
        setNotFound(true)
        return
      }
      if (response.status === 403) {
        setForbidden(true)
        return
      }
      if (apiError) throw new Error(apiError.message)
      setWorkspace(data)
    } catch (err) {
      setError(err instanceof Error ? err.message : "This event could not be loaded.")
    } finally {
      setLoading(false)
    }
  }, [eventId, live])

  useEffect(() => {
    load()
  }, [load])

  return { workspace, loading, error, notFound, forbidden, reload: load }
}
