import { useCallback, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect } from "expo-router"

import { EventCard } from "@/components/marketplace/event-card"
import { openEventGroup } from "@/components/marketplace/event-rail"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { groupOpportunitiesLocally, type EventGroup } from "@/lib/api/event-groups"
import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { fetchVendorFavorites, setVendorFavorite } from "@/lib/api/vendor-favorites"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

/**
 * Saved events, one card per event. Saves are stored per vendor option; unsaving an event
 * removes every saved option of it, and the server confirms each before the card disappears.
 */
export default function VendorFavoritesScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<MarketplaceOpportunity[]>([])
  const busy = useRef(new Set<string>())
  const didFocusLoad = useRef(false)
  const groups = useMemo(() => groupOpportunitiesLocally(items), [items])

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        const result = await fetchVendorFavorites(auth)
        setItems(result.items)
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "refresh" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  async function handleUnsave(group: EventGroup) {
    if (busy.current.has(group.key)) return
    busy.current.add(group.key)
    try {
      const ids = group.options.map((o) => o.opportunityId)
      const results = isLiveApiEnabled() ? await Promise.all(ids.map((id) => setVendorFavorite(id, false, auth))) : ids.map(() => false)
      if (results.some(Boolean)) {
        showToast("This event is still saved. Pull to refresh and try again.", "error")
        return
      }
      const removed = new Set(ids)
      setItems((prev) => prev.filter((row) => !row.opportunityId || !removed.has(row.opportunityId)))
      showToast("Removed from saved events.", "success")
    } catch (err) {
      showToast(`Couldn't remove this event. ${appErrorFromWp(err).message}`, "error")
    } finally {
      busy.current.delete(group.key)
    }
  }

  return (
    <Screen title="Saved events" refreshing={refreshing} onRefresh={() => void load("refresh")} header={<BackLink label="Profile" fallbackHref="/(vendor)/more" />}>
      {loading && items.length === 0 ? <LoadingPanel label="Loading saved events…" variant="skeleton" withMedia /> : null}

      {!loading && error && items.length === 0 ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} /> : null}

      {error && items.length > 0 ? <InlineBanner tone="error" message={`Couldn't refresh. ${error.message}`} actionLabel="Retry" onAction={() => void load("refresh")} /> : null}

      {!loading && !error && items.length === 0 ? (
        <EmptyPanel
          icon="heart-outline"
          title="No saved events yet"
          message="Tap the heart on any event in Explore to keep it here."
          actionLabel="Explore events"
          onAction={() => router.push("/(vendor)/find")}
        />
      ) : null}

      {groups.length > 0 ? (
        <View style={styles.list}>
          <Meta>Saved events whose applications have closed drop off this list.</Meta>
          {groups.map((group) => (
            <EventCard key={group.key} group={group} variant="row" saved onToggleSave={() => void handleUnsave(group)} onPress={() => openEventGroup(group)} />
          ))}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  list: { gap: spacing.sm },
})
