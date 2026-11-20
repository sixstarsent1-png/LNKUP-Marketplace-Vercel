import { useCallback, useEffect, useRef, useState } from "react"

/**
 * Wrap an async action so repeated taps while it runs are ignored (state updates are async, so a
 * `disabled={busy}` prop alone still lets a fast double tap through). Returns the guarded runner
 * and a `busy` flag for spinners. Keyed variants guard per item (e.g. one favorite per card).
 */
export function useSingleFlight<Args extends unknown[], Result>(
  action: (...args: Args) => Promise<Result>,
): [(...args: Args) => Promise<Result | undefined>, boolean] {
  const inFlight = useRef(false)
  const mounted = useRef(true)
  const actionRef = useRef(action)
  const [busy, setBusy] = useState(false)

  actionRef.current = action

  useEffect(() => {
    mounted.current = true
    return () => {
      mounted.current = false
    }
  }, [])

  const run = useCallback(async (...args: Args) => {
    if (inFlight.current) return undefined
    inFlight.current = true
    setBusy(true)
    try {
      return await actionRef.current(...args)
    } finally {
      inFlight.current = false
      if (mounted.current) setBusy(false)
    }
  }, [])

  return [run, busy]
}

/** Per-key single flight: `run(key, ...)` ignores calls for a key that is already running. */
export function useKeyedSingleFlight<Args extends unknown[], Result>(
  action: (key: string, ...args: Args) => Promise<Result>,
): [(key: string, ...args: Args) => Promise<Result | undefined>, (key: string) => boolean] {
  const inFlight = useRef(new Set<string>())
  const actionRef = useRef(action)
  const [, setVersion] = useState(0)

  actionRef.current = action

  const run = useCallback(async (key: string, ...args: Args) => {
    if (inFlight.current.has(key)) return undefined
    inFlight.current.add(key)
    setVersion((v) => v + 1)
    try {
      return await actionRef.current(key, ...args)
    } finally {
      inFlight.current.delete(key)
      setVersion((v) => v + 1)
    }
  }, [])

  const isBusy = useCallback((key: string) => inFlight.current.has(key), [])

  return [run, isBusy]
}
