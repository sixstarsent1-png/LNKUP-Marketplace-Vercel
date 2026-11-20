import { useCallback, useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { searchParamsToQueryRecord } from "@/lib/api/marketplace"
import {
  deleteSavedSearch,
  listSavedSearches,
  runSavedSearch,
  savedSearchToSearchParams,
  type SavedSearch,
} from "@/lib/api/saved-searches"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

function summaryOf(search: SavedSearch): string {
  return (
    search.filterSummary ||
    [search.city, search.state, search.category, search.query].filter(Boolean).join(" · ") ||
    "All open opportunities"
  )
}

export default function VendorSavedSearchesScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<SavedSearch[]>([])
  const [busy, setBusy] = useState<{ id: string; action: "run" | "delete" } | null>(null)

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        setItems(await listSavedSearches(auth))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  function openOnFind(search: SavedSearch) {
    const params = searchParamsToQueryRecord(savedSearchToSearchParams(search))
    router.replace({ pathname: "/(vendor)/find", params } as never)
  }

  async function handleRun(search: SavedSearch) {
    if (busy) return
    setBusy({ id: search.id, action: "run" })
    try {
      await runSavedSearch(search.id, auth)
      openOnFind(search)
    } catch (err) {
      showToast(`Couldn't run this search. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setBusy(null)
    }
  }

  async function handleDelete(search: SavedSearch) {
    if (busy) return
    const ok = await confirmAction({
      title: "Delete saved search?",
      message: `"${search.name}" and its alerts will be removed.`,
      confirmLabel: "Delete",
      destructive: true,
    })
    if (!ok) return
    setBusy({ id: search.id, action: "delete" })
    try {
      await deleteSavedSearch(search.id, auth)
      setItems((prev) => prev.filter((s) => s.id !== search.id))
      showToast("Saved search deleted.", "success")
    } catch (err) {
      showToast(`Couldn't delete this search. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setBusy(null)
    }
  }

  return (
    <Screen
      title="Saved searches"
      subtitle="Re-run filters you care about"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <BackLink label="Explore" fallbackHref="/(vendor)/find" />

      {loading && items.length === 0 ? <LoadingPanel label="Loading saved searches…" variant="skeleton" /> : null}

      {!loading && error && items.length === 0 ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("initial")} />
      ) : null}

      {error && items.length > 0 ? (
        <InlineBanner
          tone="error"
          message={`Couldn't refresh. ${error.message}`}
          actionLabel="Retry"
          onAction={() => void load("refresh")}
        />
      ) : null}

      {!loading && !error && items.length === 0 ? (
        <EmptyPanel
          icon="bookmark-outline"
          title="No saved searches"
          message="On Explore, set filters and tap Save to get alerts for new matches."
          actionLabel="Open Explore"
          onAction={() => router.push("/(vendor)/find")}
        />
      ) : null}

      {items.length > 0 ? (
        <View style={styles.list}>
          {items.map((search) => {
            const running = busy?.id === search.id && busy.action === "run"
            const deleting = busy?.id === search.id && busy.action === "delete"
            return (
              <Surface key={search.id} elevated style={styles.card}>
                <View style={styles.titleRow}>
                  <SectionTitle style={styles.title} numberOfLines={2}>
                    {search.name}
                  </SectionTitle>
                  {(search.newMatches ?? 0) > 0 ? (
                    <StatusBadge label={`${search.newMatches} new`} tone="paid" />
                  ) : null}
                </View>
                <Meta numberOfLines={2}>{summaryOf(search)}</Meta>
                <View style={styles.actions}>
                  <Button
                    label="Run search"
                    icon="search-outline"
                    variant="accent"
                    style={styles.flex}
                    loading={running}
                    disabled={Boolean(busy) && !running}
                    onPress={() => void handleRun(search)}
                    accessibilityLabel={`Run ${search.name}`}
                  />
                  <Button
                    label="Delete"
                    variant="danger"
                    loading={deleting}
                    loadingLabel="Deleting…"
                    disabled={Boolean(busy) && !deleting}
                    onPress={() => void handleDelete(search)}
                    accessibilityLabel={`Delete ${search.name}`}
                  />
                </View>
              </Surface>
            )
          })}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  list: { gap: spacing.md },
  card: { gap: spacing.sm },
  titleRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: spacing.sm },
  title: { flex: 1 },
  actions: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.xs },
  flex: { flex: 1 },
})
