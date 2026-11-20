"use client"

import { useCallback, useEffect, useState } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import {
  type OrganizerLaunchDraft,
  hasMeaningfulLaunchDraft,
} from "@/lib/api/organizer-launch"

export function useOrganizerLaunchDraftPresence() {
  const live = isLiveApiEnabled()
  const [hasDraft, setHasDraft] = useState(false)
  const [loading, setLoading] = useState(live)

  const load = useCallback(async () => {
    if (!live) {
      setHasDraft(false)
      setLoading(false)
      return
    }
    setLoading(true)
    try {
      const response = await fetch("/api/organizer/launch/draft")
      const { data } = await parseApiResponse<OrganizerLaunchDraft>(response)
      setHasDraft(hasMeaningfulLaunchDraft(data))
    } catch {
      setHasDraft(false)
    } finally {
      setLoading(false)
    }
  }, [live])

  useEffect(() => {
    load()
  }, [load])

  return { hasDraft, loading, reload: load }
}
