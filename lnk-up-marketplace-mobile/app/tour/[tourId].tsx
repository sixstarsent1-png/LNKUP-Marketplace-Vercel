import { useCallback, useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useLocalSearchParams, type Href } from "expo-router"
import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchTourApplyContext } from "@/lib/api/apply"
import { fetchTour, type TourDetail, type TourStop } from "@/lib/api/match"
import { useAuth } from "@/lib/auth/auth-context"
import { tourStopCta } from "@/lib/vendor/tour-stop-cta"
import { colors, radii, spacing } from "@/lib/theme"

function formatFee(stop: TourStop): string | null {
  if (stop.feeMin == null) return null
  return stop.feeMin === stop.feeMax ? `$${stop.feeMin}` : `$${stop.feeMin}–$${stop.feeMax}`
}

/** Tour / recurring series: each stop with its own availability, pricing and application. */
export default function TourScreen() {
  const { tourId } = useLocalSearchParams<{ tourId: string }>()
  const { auth } = useAuth()
  const [tour, setTour] = useState<TourDetail | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)
  const [canMultiApply, setCanMultiApply] = useState(false)

  const load = useCallback(async () => {
    const id = Number(tourId)
    if (!Number.isFinite(id) || id <= 0) {
      setError({ code: "not_found", title: "Tour not found", message: "This tour link is not valid.", retryable: false })
      setLoading(false)
      return
    }
    setError(null)
    try {
      setTour(await fetchTour(auth ?? undefined, id))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
    // Multi-stop apply is optional: hidden when the feature is off or the viewer can't apply.
    if (auth) {
      fetchTourApplyContext(id, auth)
        .then((ctx) => setCanMultiApply(ctx.slots.filter((s) => s.canApply).length > 1))
        .catch(() => setCanMultiApply(false))
    }
  }, [auth, tourId])

  useEffect(() => {
    void load()
  }, [load])

  return (
    <Screen title={tour?.title ?? "Tour"} header={<BackLink label="Explore" fallbackHref={"/(vendor)/find" as Href} />}>
      {loading ? <LoadingPanel label="Loading tour…" variant="skeleton" count={3} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}
      {tour ? (
        <View style={styles.wrap} testID="tour-detail">
          <Meta>{tour.type === "series" ? "Recurring market" : "Tour"}{tour.organizerName ? ` · by ${tour.organizerName}` : ""}</Meta>
          {tour.matchingStops > 0 ? (
            <Body style={styles.match}>
              {tour.matchingStops} stop{tour.matchingStops === 1 ? "" : "s"} match your markets
            </Body>
          ) : null}
          {tour.description ? <Body>{tour.description}</Body> : null}
          <Meta style={styles.note}>
            Each stop has its own application, booth inventory and payment. Applying to one stop doesn't apply you to the others.
          </Meta>
          {canMultiApply ? (
            <Button
              label="Apply to several stops"
              icon="albums-outline"
              fullWidth
              onPress={() => router.push(`/tour/apply/${tour.id}` as Href)}
              testID="tour-multi-apply-open"
            />
          ) : null}
          {tour.stops.map((stop) => {
            const cta = tourStopCta(stop)
            const fee = formatFee(stop)
            return (
              <View key={stop.eventId} testID="tour-stop">
                <Surface accentBorderTop={stop.matchesMe}>
                  <SectionTitle>{[stop.city, stop.state].filter(Boolean).join(", ") || stop.title}</SectionTitle>
                  <Meta>
                    {stop.date || "Date TBA"}
                    {fee ? ` · ${fee}` : ""}
                  </Meta>
                  <View style={styles.badges}>
                    <View style={[styles.badge, stop.accepting ? styles.badgeOpen : styles.badgeClosed]}>
                      <Meta style={styles.badgeText}>{stop.accepting ? "Accepting vendors" : "Not accepting"}</Meta>
                    </View>
                    {stop.matchesMe ? (
                      <View style={[styles.badge, styles.badgeMatch]}>
                        <Meta style={styles.badgeText}>Matches you</Meta>
                      </View>
                    ) : null}
                  </View>
                  {cta.kind === "status" ? <Meta>{cta.label}</Meta> : null}
                  {cta.kind === "apply" ? (
                    <Button
                      label={cta.label}
                      size="sm"
                      onPress={() => router.push(`/apply/${cta.opportunityId}` as Href)}
                    />
                  ) : null}
                </Surface>
              </View>
            )
          })}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.md },
  match: { color: colors.green700 },
  note: { color: colors.gray500 },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  badge: { borderRadius: radii.pill, paddingHorizontal: spacing.md, paddingVertical: 2 },
  badgeOpen: { backgroundColor: colors.green50 },
  badgeClosed: { backgroundColor: colors.gray100 },
  badgeMatch: { backgroundColor: colors.limeMuted },
  badgeText: { color: colors.gray900 },
})
