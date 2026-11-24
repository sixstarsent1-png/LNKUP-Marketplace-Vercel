import { useCallback, useEffect, useState } from "react"
import { router, useLocalSearchParams, type Href } from "expo-router"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import { resolveAppPath } from "@/lib/api/deep-links"
import { useAuth } from "@/lib/auth/auth-context"
import { hrefForResolved } from "@/lib/navigation/deep-link-map"
import { savePendingAttribution } from "@/lib/navigation/pending-attribution"

/** Resolves an event, market, tour or tracked link from the website, then opens the matching screen. */
export default function LinkResolverScreen() {
  const { path } = useLocalSearchParams<{ path?: string }>()
  const { session, isAuthenticated, auth } = useAuth()
  const [failed, setFailed] = useState(false)
  const raw = (Array.isArray(path) ? path[0] : path) ?? ""

  const resolve = useCallback(async () => {
    setFailed(false)
    if (!raw.startsWith("/") || raw.startsWith("//")) {
      router.replace("/" as Href)
      return
    }
    try {
      const link = await resolveAppPath(raw)
      if (Object.keys(link.attribution).length > 0) await savePendingAttribution(link.attribution)
      void trackMobileEvent({
        event: "deep_opportunity_opened",
        source: "app_link",
        auth: auth ?? undefined,
        metadata: { route: link.route, tracked_link: raw.startsWith("/l/") },
      })
      router.replace(hrefForResolved(link, isAuthenticated ? session?.user.roles : undefined) as Href)
    } catch {
      setFailed(true)
    }
  }, [auth, isAuthenticated, raw, session?.user.roles])

  useEffect(() => {
    void resolve()
  }, [resolve])

  return (
    <Screen title="Opening link">
      {failed ? (
        <ErrorPanel
          title="We couldn't open this link"
          message="Check your connection and try again, or browse open events instead."
          onAction={() => void resolve()}
        />
      ) : (
        <LoadingPanel label="Opening…" />
      )}
    </Screen>
  )
}
