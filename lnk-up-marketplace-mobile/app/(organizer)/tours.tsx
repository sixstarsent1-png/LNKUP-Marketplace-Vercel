import { useCallback, useState } from "react"
import { ScrollView, StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { KpiGrid } from "@/components/organizer/kpi-grid"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { FilterChip } from "@/components/ui/chip"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { formatMoneyOrZero } from "@/lib/api/format-money"
import { fetchTours, fetchTourSummary, type OrgTour, type TourStop, type TourSummary } from "@/lib/api/organizer-tours"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { spacing } from "@/lib/theme"

/** Tours and series: list, then one tour's summary with a stop switcher. Bulk tour creation stays on web. */
export default function OrganizerToursScreen() {
  const { tourId } = useLocalSearchParams<{ tourId?: string }>()
  return tourId ? <TourSummaryView tourId={String(tourId)} /> : <ToursList />
}

function ToursList() {
  const { auth } = useAuth()
  const [tours, setTours] = useState<OrgTour[] | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(
    async (force = false) => {
      if (!auth) return
      setError(null)
      try {
        setTours(await fetchTours(auth, force))
      } catch (err) {
        setError(appErrorFromWp(err))
      }
    },
    [auth],
  )

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  return (
    <Screen title="Tours" subtitle="Events you run as a series" refreshing={false} onRefresh={() => void load(true)} header={<BackLink label="More" fallbackHref={"/(organizer)/more" as Href} />}>
      {tours === null && !error ? <LoadingPanel label="Loading tours…" variant="skeleton" count={2} /> : null}
      {error && tours === null ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load(true)} /> : null}
      {tours && tours.length === 0 ? (
        <EmptyPanel icon="git-network-outline" title="No tours yet" message="Group events into a tour or series on LNKup web; manage each stop here." />
      ) : null}
      {tours && tours.length > 0 ? (
        <ListGroup>
          {tours.map((tour) => (
            <ListRow
              key={tour.id}
              icon="git-network-outline"
              title={tour.title}
              meta={[`${tour.stopCount} stop${tour.stopCount === 1 ? "" : "s"}`, tour.nextDate ? `next ${formatShortDate(tour.nextDate)}` : null, tour.season].filter(Boolean).join(" · ")}
              rightSlot={<StatusBadge label={tour.status === "active" ? "Active" : tour.status || "Draft"} tone={tour.status === "active" ? "paid" : "neutral"} />}
              onPress={() => router.push(`/(organizer)/tours?tourId=${tour.id}` as Href)}
            />
          ))}
        </ListGroup>
      ) : null}
    </Screen>
  )
}

function TourSummaryView({ tourId }: { tourId: string }) {
  const { auth } = useAuth()
  const [summary, setSummary] = useState<TourSummary | null>(null)
  const [stopId, setStopId] = useState<string | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    if (!auth) return
    setError(null)
    try {
      setSummary(await fetchTourSummary(tourId, auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, tourId])

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  const stop: TourStop | null = summary?.stops.find((s) => s.eventId === stopId) ?? null

  return (
    <Screen
      title={summary?.tour.title ?? "Tour"}
      subtitle={summary ? `${summary.stops.length} stops` : undefined}
      refreshing={false}
      onRefresh={() => void load()}
      header={<BackLink label="Tours" fallbackHref={"/(organizer)/tours" as Href} />}
    >
      {loading && !summary ? <LoadingPanel label="Loading tour…" variant="skeleton" count={3} /> : null}
      {error && !summary ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {summary ? (
        <View style={styles.stack} testID="tour-summary">
          <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.chips}>
            <FilterChip label="Whole tour" selected={!stopId} onPress={() => setStopId(null)} />
            {summary.stops.map((s) => (
              <FilterChip key={s.eventId} label={s.city || s.title} selected={stopId === s.eventId} onPress={() => setStopId(s.eventId)} />
            ))}
          </ScrollView>

          {stop ? (
            <>
              <Meta>{[stop.title, formatShortDate(stop.date), stop.venue].filter(Boolean).join(" · ")}</Meta>
              <KpiGrid
                items={[
                  { key: "pending", label: "To review", value: stop.applications.pending, scope: "applications", icon: "hourglass-outline" },
                  { key: "spots", label: "Open spots", value: `${stop.spotsOpen}/${stop.spotsTotal}`, scope: "this stop", icon: "grid-outline" },
                  { key: "paid", label: "Confirmed", value: stop.applications.paid, scope: "paid vendors", icon: "checkmark-circle-outline" },
                  { key: "collected", label: "Collected", value: formatMoneyOrZero(stop.collected), scope: "booth fees, before LNKup fees", icon: "cash-outline" },
                ]}
              />
              <ListGroup>
                <ListRow icon="calendar-outline" title="Open this stop" meta="Applications, inventory, operations" onPress={() => router.push(`/(organizer)/events/${stop.eventId}` as Href)} />
                {stop.applications.pending > 0 ? (
                  <ListRow icon="people-outline" title={`Review ${stop.applications.pending} application${stop.applications.pending === 1 ? "" : "s"}`} onPress={() => router.push(`/(organizer)/vendors?tab=requests&event=${stop.eventId}` as Href)} />
                ) : null}
                {stop.nextDeadline ? <ListRow icon="time-outline" title="Application deadline" meta={formatShortDate(stop.nextDeadline)} /> : null}
              </ListGroup>
            </>
          ) : (
            <>
              <KpiGrid
                items={[
                  { key: "pending", label: "To review", value: summary.applications.pending, scope: "across all stops", icon: "hourglass-outline" },
                  { key: "spots", label: "Open spots", value: `${summary.inventory.open}/${summary.inventory.total}`, scope: "across all stops", icon: "grid-outline" },
                  { key: "paid", label: "Confirmed", value: summary.applications.paid, scope: "paid vendors", icon: "checkmark-circle-outline" },
                  { key: "collected", label: "Collected", value: formatMoneyOrZero(summary.money.collected), scope: "booth fees, before LNKup fees", icon: "cash-outline" },
                ]}
              />
              <ListGroup title="Stops">
                {summary.stops.map((s) => (
                  <ListRow
                    key={s.eventId}
                    icon="location-outline"
                    title={s.city || s.title}
                    meta={[formatShortDate(s.date), `${s.spotsOpen} open`, s.applications.pending ? `${s.applications.pending} to review` : null].filter(Boolean).join(" · ")}
                    rightSlot={<StatusBadge label={s.state === "published" ? "Published" : s.state || "Draft"} tone={s.state === "published" ? "paid" : "neutral"} />}
                    onPress={() => setStopId(s.eventId)}
                  />
                ))}
              </ListGroup>
              <Meta>Creating tours and adding stops in bulk is easiest on LNKup web.</Meta>
            </>
          )}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.lg },
  chips: { gap: spacing.sm, paddingVertical: spacing.xs },
})
