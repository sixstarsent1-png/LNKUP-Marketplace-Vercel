import { useCallback, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect } from "expo-router"

import { OrganizerEventCard } from "@/components/organizer-event-card"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ScreenList } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { FilterChipRow, type ChipOption } from "@/components/ui/chip"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  eventListingState,
  fetchOrganizerEvents,
  type EventListingState,
  type OrganizerEventLite,
} from "@/lib/api/organizer-events"
import { useAuth } from "@/lib/auth/auth-context"
import { spacing } from "@/lib/theme"

type FilterKey = "all" | "live" | "draft" | "past"

function matchesFilter(state: EventListingState, filter: FilterKey): boolean {
  switch (filter) {
    case "all":
      return true
    case "live":
      return state === "live" || state === "no_date"
    case "draft":
      return state === "draft"
    case "past":
      return state === "past"
    default: {
      const exhaustive: never = filter
      return exhaustive
    }
  }
}

/** Upcoming first (soonest), then undated, then past (most recent first). */
function sortEvents(items: OrganizerEventLite[]): OrganizerEventLite[] {
  const rank = (event: OrganizerEventLite) =>
    event.status === "upcoming" ? 0 : event.status === "unknown" ? 1 : 2
  return [...items].sort((a, b) => {
    const r = rank(a) - rank(b)
    if (r !== 0) return r
    const da = a.date ?? ""
    const db = b.date ?? ""
    return a.status === "past" ? db.localeCompare(da) : da.localeCompare(db)
  })
}

export default function OrganizerEventsScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<OrganizerEventLite[]>([])
  const [filter, setFilter] = useState<FilterKey>("all")
  const [refreshError, setRefreshError] = useState<string | null>(null)

  const didFocusLoad = useRef(false)
  const itemsRef = useRef<OrganizerEventLite[]>([])
  itemsRef.current = items

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else if (mode === "initial") setLoading(true)
      setError(null)
      setRefreshError(null)
      try {
        const result = await fetchOrganizerEvents(auth, { force: mode === "refresh" })
        setItems(sortEvents(result.items))
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode !== "initial" && itemsRef.current.length > 0) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "focus" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  const filterOptions = useMemo<ChipOption<FilterKey>[]>(() => {
    const count = (key: FilterKey) =>
      items.filter((item) => matchesFilter(eventListingState(item), key)).length
    return [
      { value: "all", label: "All", count: items.length },
      { value: "live", label: "Live", count: count("live") },
      { value: "draft", label: "Drafts", count: count("draft") },
      { value: "past", label: "Past", count: count("past") },
    ]
  }, [items])

  const visible = useMemo(
    () => items.filter((item) => matchesFilter(eventListingState(item), filter)),
    [items, filter],
  )

  return (
    <ScreenList
      title="Events"
      subtitle="Create, publish and run your events"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      data={visible}
      keyExtractor={(item) => item.id}
      renderItem={(item) => <OrganizerEventCard event={item} />}
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
        <>
          {loading && items.length === 0 ? (
            <LoadingPanel label="Loading events…" variant="skeleton" withMedia />
          ) : null}

          {!loading && error && items.length === 0 ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
          ) : null}

          {!loading && !error && items.length === 0 ? (
            <EmptyPanel
              icon="calendar-outline"
              title="Create your first event"
              message="Add the date and place, set booth prices, and publish to start getting vendor applications."
              actionLabel="Create event"
              onAction={() => router.push("/(organizer)/launch")}
            />
          ) : null}

          {items.length > 0 ? (
            <View style={styles.stack}>
              {refreshError ? (
                <InlineBanner
                  tone="error"
                  message={`Couldn't refresh: ${refreshError}`}
                  actionLabel="Retry"
                  onAction={() => void load("refresh")}
                />
              ) : null}

              <FilterChipRow options={filterOptions} value={filter} onChange={setFilter} />

              {visible.length === 0 ? (
                <EmptyPanel
                  icon="calendar-outline"
                  title={
                    filter === "past" ? "No past events" : filter === "draft" ? "No drafts" : "No live events"
                  }
                  message="Try another filter or create a new event."
                  actionLabel="Create event"
                  onAction={() => router.push("/(organizer)/launch")}
                />
              ) : null}
            </View>
          ) : null}
        </>
      }
    />
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
})
