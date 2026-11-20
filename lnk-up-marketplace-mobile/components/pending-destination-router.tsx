import { useEffect, useState } from "react"
import { router, useRootNavigationState, useSegments, type Href } from "expo-router"

import { useAuth } from "@/lib/auth/auth-context"
import { subscribePendingDestination, takePendingDestination } from "@/lib/navigation/pending-destination"

const ENTRY_ROUTES = new Set(["login", "signup", "no-access", "stripe-redirect"])

/**
 * Opens a remembered destination (push tap, deep link, returnTo) once the user is signed in,
 * the navigator is mounted, and the home redirect has finished — pushed on top of home so Back works.
 */
export function PendingDestinationRouter() {
  const { session, isAuthenticated } = useAuth()
  const navState = useRootNavigationState()
  const segments = useSegments()
  const [signal, setSignal] = useState(0)

  useEffect(() => subscribePendingDestination(() => setSignal((n) => n + 1)), [])

  const root = segments[0] as string | undefined
  const ready = Boolean(navState?.key) && Boolean(root) && !ENTRY_ROUTES.has(String(root))

  useEffect(() => {
    if (!ready || !isAuthenticated || !session) return
    let cancelled = false
    void takePendingDestination(session.user.roles).then((href) => {
      if (href && !cancelled) router.push(href as Href)
    })
    return () => {
      cancelled = true
    }
  }, [ready, isAuthenticated, session, signal, root])

  return null
}
