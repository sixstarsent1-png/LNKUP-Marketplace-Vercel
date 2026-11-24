import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { EventRow } from "@/components/organizer/event-row"
import { SearchField } from "@/components/organizer/search-field"
import { OrganizerEventCard } from "@/components/organizer-event-card"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChipRow, type ChipOption } from "@/components/ui/chip"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchOrganizerEvents as fetchLegacyEvents, type OrganizerEventLite } from "@/lib/api/organizer-events"
import { fetchOrganizerEvents, type OrgEventCard, type OrgEventsResult } from "@/lib/api/organizer-mobile"
import { useAuth } from "@/lib/auth/auth-context"
import {
  PORTFOLIO_SORTS,
  serverFilters,
  sortPortfolio,
  type PortfolioFilter,
  type PortfolioSort,
} from "@/lib/organizer/event-portfolio"
import { colors, layout, radii, spacing, typography } from "@/lib/theme"

const SEARCH_DEBOUNCE_MS = 300

type Row = { kind: "event"; event: OrgEventCard } | { kind: "legacy"; event: OrganizerEventLite }

export default function OrganizerEventsScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ filter?: string }>()
  const [result, setResult] = useState<OrgEventsResult | null>(null)
  const [legacy, setLegacy] = useState<OrganizerEventLite[] | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [filter, setFilter] = useState<PortfolioFilter>(params.filter === "tour" ? "tour" : "all")
  const [query, setQuery] = useState("")
  const [debounced, setDebounced] = useState("")
  const [sort, setSort] = useState<PortfolioSort>("date")
  const [layoutMode, setLayoutMode] = useState<"row" | "card">("row")
  const [sortOpen, setSortOpen] = useState(false)
  const hasData = useRef(false)
  const didFocusLoad = useRef(false)

  useEffect(() => {
    const id = setTimeout(() => setDebounced(query.trim()), SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(id)
  }, [query])

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh") => {
      if (!auth) return
      if (mode === "refresh") setRefreshing(true)
      else if (mode === "initial") setLoading(true)
      setError(null)
      setRefreshError(null)
      try {
        const next = await fetchOrganizerEvents(auth, serverFilters(filter, debounced), mode === "refresh")
        if (next) {
          setResult(next)
        } else {
          const old = await fetchLegacyEvents(auth, { force: mode === "refresh" })
          setLegacy(old.items)
        }
        hasData.current = true
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode !== "initial" && hasData.current) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, filter, debounced],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "focus" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  const rows = useMemo<Row[]>(() => {
    if (result) {
      const events = filter === "tour" ? result.events.filter((e) => e.tourId) : result.events
      return sortPortfolio(events, sort).map((event) => ({ kind: "event", event }))
    }
    return (legacy ?? []).map((event) => ({ kind: "legacy", event }))
  }, [result, legacy, filter, sort])

  const chips = useMemo<ChipOption<PortfolioFilter>[]>(() => {
    const c = result?.counts
    return [
      { value: "all", label: "All", count: c?.all },
      { value: "upcoming", label: "Upcoming", count: c ? c.upcoming + c.inProgress : undefined },
      { value: "open", label: "Applications open" },
      { value: "draft", label: "Drafts", count: c?.draft },
      { value: "past", label: "Past", count: c?.completed },
      { value: "tour", label: "Tours" },
    ]
  }, [result])

  const totalEvents = result?.counts.all ?? legacy?.length ?? 0
  const sortLabel = PORTFOLIO_SORTS.find((s) => s.id === sort)?.label ?? "Event date"

  return (
    <ScreenList
      title="Events"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      data={rows}
      keyExtractor={(row) => row.event.id}
      renderItem={(row) =>
        row.kind === "event" ? (
          <EventRow
            event={row.event}
            variant={layoutMode}
            testID={`event-row-${row.event.id}`}
            onPress={() => router.push(`/(organizer)/events/${row.event.id}` as Href)}
          />
        ) : (
          <OrganizerEventCard event={row.event} />
        )
      }
      rightSlot={
        <Button
          label="Create"
          icon="add"
          variant="accent"
          size="sm"
          onPress={() => router.push("/(organizer)/launch")}
          accessibilityLabel="Create a new event"
        />
      }
      listHeader={
        <View style={styles.stack}>
          {totalEvents > 0 || query ? (
            <>
              <SearchField
                value={query}
                onChangeText={setQuery}
                placeholder="Search events, cities, venues, tours"
                testID="events-search"
              />
              <FilterChipRow options={chips} value={filter} onChange={setFilter} />
              {result ? (
                <View style={styles.toolbar}>
                  <Pressable
                    onPress={() => setSortOpen(true)}
                    accessibilityRole="button"
                    accessibilityLabel={`Sort by ${sortLabel}`}
                    style={styles.toolbarButton}
                  >
                    <AppIcon name="swap-vertical-outline" size={16} color={colors.black} />
                    <Text style={styles.toolbarText}>{sortLabel}</Text>
                  </Pressable>
                  <Pressable
                    onPress={() => setLayoutMode((m) => (m === "row" ? "card" : "row"))}
                    accessibilityRole="button"
                    accessibilityLabel={layoutMode === "row" ? "Show large cards" : "Show compact rows"}
                    style={styles.toolbarButton}
                  >
                    <AppIcon name={layoutMode === "row" ? "albums-outline" : "list-outline"} size={16} color={colors.black} />
                    <Text style={styles.toolbarText}>{layoutMode === "row" ? "Cards" : "Rows"}</Text>
                  </Pressable>
                </View>
              ) : null}
            </>
          ) : null}

          {refreshError ? (
            <InlineBanner tone="error" message={`Couldn't refresh: ${refreshError}`} actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : null}

          {loading && rows.length === 0 ? <LoadingPanel label="Loading events…" variant="skeleton" withMedia /> : null}

          {!loading && error && rows.length === 0 ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
          ) : null}

          {!loading && !error && totalEvents === 0 && !query ? (
            <EmptyPanel
              icon="calendar-outline"
              title="Create your first event"
              message="Add the date and place, set booth prices, and publish to start getting vendor applications."
              actionLabel="Create event"
              onAction={() => router.push("/(organizer)/launch")}
            />
          ) : null}

          {!loading && !error && rows.length === 0 && (totalEvents > 0 || query) ? (
            <EmptyPanel
              icon="search-outline"
              title={query ? `No events match “${query}”` : "No events in this view"}
              message="Try another filter or search."
              actionLabel={query ? "Clear search" : "Show all events"}
              onAction={() => {
                setQuery("")
                setFilter("all")
              }}
            />
          ) : null}

          <BottomSheet visible={sortOpen} onClose={() => setSortOpen(false)} title="Sort events">
            <View style={styles.sortList}>
              {PORTFOLIO_SORTS.map((option) => (
                <Pressable
                  key={option.id}
                  onPress={() => {
                    setSort(option.id)
                    setSortOpen(false)
                  }}
                  accessibilityRole="radio"
                  accessibilityState={{ checked: sort === option.id }}
                  aria-checked={sort === option.id}
                  style={styles.sortRow}
                >
                  <Text style={styles.sortText}>{option.label}</Text>
                  {sort === option.id ? <AppIcon name="checkmark" size={18} color={colors.black} /> : null}
                </Pressable>
              ))}
            </View>
          </BottomSheet>
        </View>
      }
    />
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.md,
  },
  toolbar: {
    flexDirection: "row",
    justifyContent: "space-between",
  },
  toolbarButton: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.xs,
    minHeight: layout.hitTargetMin,
    paddingHorizontal: spacing.sm,
    borderRadius: radii.md,
  },
  toolbarText: {
    ...typography.label,
  },
  sortList: {
    gap: spacing.xs,
  },
  sortRow: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    minHeight: layout.hitTargetMin + 4,
    paddingHorizontal: spacing.md,
    borderRadius: radii.md,
    backgroundColor: colors.gray50,
  },
  sortText: {
    ...typography.label,
  },
})
