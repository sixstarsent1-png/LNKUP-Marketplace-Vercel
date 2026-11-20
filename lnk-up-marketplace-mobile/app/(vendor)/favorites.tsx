import { useCallback, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, type Href } from "expo-router"

import { OpportunityCard } from "@/components/opportunity-card"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import type { MarketplaceOpportunity } from "@/lib/api/marketplace"
import { fetchVendorFavorites, setVendorFavorite } from "@/lib/api/vendor-favorites"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

/** Saved opportunities. Unsaving is confirmed by the server before the card disappears. */
export default function VendorFavoritesScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<MarketplaceOpportunity[]>([])
  const busy = useRef(new Set<string>())
  const didFocusLoad = useRef(false)

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

  async function handleUnsave(item: MarketplaceOpportunity) {
    if (!item.opportunityId || busy.current.has(item.id)) return
    busy.current.add(item.id)
    try {
      const stillSaved = isLiveApiEnabled() ? await setVendorFavorite(item.opportunityId, false, auth) : false
      if (stillSaved) {
        showToast("This event is still saved. Pull to refresh and try again.", "error")
        return
      }
      setItems((prev) => prev.filter((row) => row.id !== item.id))
      showToast("Removed from saved events.", "success")
    } catch (err) {
      showToast(`Couldn't remove this event. ${appErrorFromWp(err).message}`, "error")
    } finally {
      busy.current.delete(item.id)
    }
  }

  return (
    <Screen
      title="Saved events"
      subtitle="Opportunities you hearted"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <BackLink label="Profile" fallbackHref="/(vendor)/more" />

      {loading && items.length === 0 ? <LoadingPanel label="Loading saved events…" variant="skeleton" withMedia /> : null}

      {!loading && error && items.length === 0 ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {error && items.length > 0 ? (
        <InlineBanner tone="error" message={`Couldn't refresh. ${error.message}`} actionLabel="Retry" onAction={() => void load("refresh")} />
      ) : null}

      {!loading && !error && items.length === 0 ? (
        <EmptyPanel
          icon="heart-outline"
          title="No saved events yet"
          message="Tap the heart on any event in Explore to keep it here."
          actionLabel="Explore events"
          onAction={() => router.push("/(vendor)/find")}
        />
      ) : null}

      {items.length > 0 ? (
        <View style={styles.list}>
          {items.map((item) => (
            <OpportunityCard
              key={item.id}
              item={item}
              saved
              onToggleSave={() => void handleUnsave(item)}
              onPress={item.opportunityId ? () => router.push(`/opportunity/${item.opportunityId}` as Href) : undefined}
              onApply={item.opportunityId ? () => router.push(`/apply/${item.opportunityId}` as Href) : undefined}
            />
          ))}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  list: { gap: spacing.md },
})
