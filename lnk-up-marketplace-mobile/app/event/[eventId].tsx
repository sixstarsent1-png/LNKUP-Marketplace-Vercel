import { useCallback, useEffect, useState } from "react"
import { Share, StyleSheet, View } from "react-native"
import { router, useLocalSearchParams, type Href } from "expo-router"

import { VendorEventDetail } from "@/components/marketplace/vendor-event-detail"
import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import { fetchPublicEvent, fetchShareLink, type PublicEvent, type PublicEventState } from "@/lib/api/deep-links"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { useAuth } from "@/lib/auth/auth-context"
import { isVendorRole } from "@/lib/auth/roles"
import { formatShortDate } from "@/lib/format/date"
import { setPendingDestination } from "@/lib/navigation/pending-destination"
import { colors, radii, spacing } from "@/lib/theme"

const STATE_NOTICE: Record<Exclude<PublicEventState, "scheduled">, string> = {
  postponed: "This event has been postponed. Check back for the new date.",
  cancelled: "This event has been cancelled.",
  past: "This event has already happened.",
}

/**
 * Signed-in vendors get the full event with every vendor option; everyone else gets the
 * guest-safe public event.
 */
export default function EventScreen() {
  const { eventId, option } = useLocalSearchParams<{ eventId: string; option?: string }>()
  const { session, isAuthenticated } = useAuth()
  const id = Number(eventId)
  if (isAuthenticated && isVendorRole(session?.user.roles) && Number.isFinite(id) && id > 0) {
    const optionId = Number(option)
    return <VendorEventDetail eventId={id} initialOptionId={Number.isFinite(optionId) && optionId > 0 ? optionId : undefined} />
  }
  return <PublicEventScreen />
}

/** Read-only public event: the same guest-safe details as the event page on the website. */
function PublicEventScreen() {
  const { eventId } = useLocalSearchParams<{ eventId: string }>()
  const { auth, session, isAuthenticated } = useAuth()
  const [event, setEvent] = useState<PublicEvent | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)
  const [sharing, setSharing] = useState(false)
  const id = Number(eventId)
  const isVendor = isVendorRole(session?.user.roles)

  const load = useCallback(async () => {
    if (!Number.isFinite(id) || id <= 0) {
      setError({ code: "not_found", title: "Event not found", message: "This event link is not valid.", retryable: false })
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    try {
      setEvent(await fetchPublicEvent(id))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [id])

  useEffect(() => {
    void load()
  }, [load])

  const handleApply = useCallback(
    (opportunityId: number) => {
      void trackMobileEvent({ event: "apply_clicked", source: "public_event", auth, metadata: { opportunity_id: opportunityId } })
      const href = `/opportunity/${opportunityId}`
      if (isAuthenticated) {
        router.push(href as Href)
        return
      }
      setPendingDestination(href)
      router.push("/signup?role=vendor" as Href)
    },
    [auth, isAuthenticated],
  )

  const handleShare = useCallback(async () => {
    if (!event || sharing) return
    setSharing(true)
    try {
      const url = auth ? (await fetchShareLink(auth, { type: "event", id: event.id }).catch(() => ({ url: event.url }))).url : event.url
      if (!url) return
      const result = await Share.share({ message: `${event.title} is booking vendors on LNKup\n${url}`, url })
      if (result.action === Share.sharedAction) {
        void trackMobileEvent({ event: "share_link_copied", source: "public_event", auth, metadata: { event_id: event.id } })
      }
    } catch {
      // Share sheet dismissed or unavailable.
    } finally {
      setSharing(false)
    }
  }, [auth, event, sharing])

  const place = event ? [event.venue, [event.city, event.stateCode].filter(Boolean).join(", ")].filter(Boolean).join(" · ") : ""

  return (
    <Screen title={event?.title || "Event"} header={<BackLink label="Explore" fallbackHref={"/(vendor)/find" as Href} />}>
      {loading ? <LoadingPanel label="Loading event…" variant="skeleton" count={3} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}
      {!loading && event ? (
        <View style={styles.wrap} testID="public-event">
          {event.image ? <EventImage uri={event.image} aspectRatio={1.91} radius={radii.lg} accessibilityLabel={event.title} /> : null}
          <Meta>{[formatShortDate(event.date), place].filter(Boolean).join(" · ")}</Meta>
          {event.organizerName && event.organizerSlug ? (
            <Meta
              style={styles.link}
              accessibilityRole="link"
              onPress={() => router.push(`/storefront/organizer?organizer=${encodeURIComponent(event.organizerSlug)}` as Href)}
            >
              Hosted by {event.organizerName}
            </Meta>
          ) : null}
          {event.organizerName && !event.organizerSlug ? <Meta>Hosted by {event.organizerName}</Meta> : null}
          {event.state !== "scheduled" ? (
            <Surface>
              <Body testID="public-event-notice">{STATE_NOTICE[event.state]}</Body>
            </Surface>
          ) : null}
          {event.external ? (
            <Surface>
              <Body>This event is listed from a public source and is not managed on LNKup yet. Details may change.</Body>
            </Surface>
          ) : null}
          {event.description ? <Body>{event.description}</Body> : null}

          {event.state === "scheduled" ? (
            <View style={styles.section}>
              <SectionTitle>Vendor opportunities</SectionTitle>
              {event.opportunities.length === 0 ? (
                <Meta>No vendor spaces are open right now.</Meta>
              ) : (
                event.opportunities.map((opp) => (
                  <Surface key={opp.id}>
                    <View style={styles.oppRow} testID="public-event-opportunity">
                      <View style={styles.oppCopy}>
                        <Body style={styles.oppTitle}>{opp.title}</Body>
                        <Meta>{[opp.statusLabel, opp.deadline ? `Apply by ${formatShortDate(opp.deadline)}` : ""].filter(Boolean).join(" · ")}</Meta>
                      </View>
                      {!isAuthenticated || isVendor ? (
                        <Button
                          label={isAuthenticated ? "View & apply" : "Sign up to apply"}
                          size="sm"
                          onPress={() => handleApply(opp.id)}
                        />
                      ) : null}
                    </View>
                  </Surface>
                ))
              )}
              {!isAuthenticated ? <Meta style={styles.muted}>Pricing and booth details are shown after you sign in.</Meta> : null}
            </View>
          ) : null}

          <Button label="Share event" icon="share-outline" variant="secondary" fullWidth loading={sharing} onPress={() => void handleShare()} />
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.md },
  section: { gap: spacing.sm },
  link: { color: colors.gray900, textDecorationLine: "underline" },
  muted: { color: colors.gray500 },
  oppRow: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  oppCopy: { flex: 1, gap: 2 },
  oppTitle: { fontWeight: "600" },
})
