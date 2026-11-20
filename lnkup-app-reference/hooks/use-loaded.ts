"use client"

import { useEffect, useState } from "react"

/**
 * Simulates a short data-loading delay so screens can show a skeleton
 * state before revealing content. UI-only demo helper.
 */
export function useLoaded(delay = 650) {
  const [loaded, setLoaded] = useState(false)
  useEffect(() => {
    const t = setTimeout(() => setLoaded(true), delay)
    return () => clearTimeout(t)
  }, [delay])
  return loaded
}
