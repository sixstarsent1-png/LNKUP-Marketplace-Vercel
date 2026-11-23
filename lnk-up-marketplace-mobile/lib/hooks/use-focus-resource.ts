import { useCallback, useRef, useState } from "react"
import { useFocusEffect } from "expo-router"

import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"

export type FocusResource<T> = {
  data: T | null
  loading: boolean
  refreshing: boolean
  error: AppErrorShape | null
  reload: (mode?: "initial" | "refresh") => Promise<void>
}

/**
 * Load on first focus, refresh silently on later focus. Stale responses from an earlier request are
 * dropped, and loading/refreshing always settle so screens never spin forever.
 */
export function useFocusResource<T>(fetcher: () => Promise<T>): FocusResource<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const requestId = useRef(0)
  const loadedOnce = useRef(false)

  const reload = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      const current = ++requestId.current
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        const next = await fetcher()
        if (current === requestId.current) setData(next)
      } catch (err) {
        if (current === requestId.current) setError(appErrorFromWp(err))
      } finally {
        if (current === requestId.current) {
          setLoading(false)
          setRefreshing(false)
        }
      }
    },
    [fetcher],
  )

  useFocusEffect(
    useCallback(() => {
      void reload(loadedOnce.current ? "refresh" : "initial")
      loadedOnce.current = true
    }, [reload]),
  )

  return { data, loading, refreshing, error, reload }
}
