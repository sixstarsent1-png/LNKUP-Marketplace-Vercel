"use client"

import { useCallback, useEffect, useState } from "react"
import { useSearchParams } from "next/navigation"

export type PanelState = "loading" | "content" | "empty" | "error"

type Options = {
  /** Simulate initial load delay (ms). */
  loadMs?: number
  /** When true, panel shows empty state after load. */
  forceEmpty?: boolean
  /** When true, panel shows error state after load. */
  forceError?: boolean
}

/**
 * Standard panel lifecycle for marketplace screens: loading → content | empty | error.
 * Append `?error=1` or `?empty=1` to the URL for QA.
 */
export function usePanelState(options: Options = {}) {
  const searchParams = useSearchParams()
  const urlError = searchParams.get("error") === "1"
  const urlEmpty = searchParams.get("empty") === "1"

  const loadMs = options.loadMs ?? 600
  const forceEmpty = options.forceEmpty ?? urlEmpty
  const forceError = options.forceError ?? urlError

  const [state, setState] = useState<PanelState>("loading")
  const [retryKey, setRetryKey] = useState(0)

  const retry = useCallback(() => {
    setState("loading")
    setRetryKey((k) => k + 1)
  }, [])

  useEffect(() => {
    setState("loading")
    const t = setTimeout(() => {
      if (forceError) setState("error")
      else if (forceEmpty) setState("empty")
      else setState("content")
    }, loadMs)
    return () => clearTimeout(t)
  }, [loadMs, forceEmpty, forceError, retryKey])

  return { state, retry, isLoading: state === "loading", isError: state === "error", isEmpty: state === "empty" }
}
