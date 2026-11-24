import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Modal, Pressable, Share, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { trackMobileEvent } from "@/lib/analytics/mobile-events"
import { fetchApplyContext, type ApplyContext } from "@/lib/api/apply"
import { fetchShareLink } from "@/lib/api/deep-links"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchEventDetail, type EventDetail, type EventOption } from "@/lib/api/event-groups"
import { fetchVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { isAbortError } from "@/lib/api/wp-client"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { useVendorFavorites } from "@/lib/hooks/use-vendor-favorites"
import { hasOpenBalance, payCtaLabel } from "@/lib/vendor/booking-lifecycle"
import {
  deadlineLabel,
  environmentLabel,
  optionAvailability,
  optionPriceLabel,
} from "@/lib/vendor/event-group-presentation"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

const PREVIEW_CHARS = 320

type Cta = { label: string; onPress?: () => void; icon?: AppIconName; note?: string; disabled?: boolean }

/**
 * Signed-in vendor view of one event: compact identity, every vendor option with its own price,
 * availability and deadline, and one context-aware action for the selected option.
 */
export function VendorEventDetail({ eventId, initialOptionId }: { eventId: number; initialOptionId?: number }) {
  const { auth } = useAuth()
  const favorites = useVendorFavorites(auth)
  const [event, setEvent] = useState<EventDetail | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)
  const [selectedId, setSelectedId] = useState<number | null>(initialOptionId ?? null)
  const [applyCtx, setApplyCtx] = useState<ApplyContext | null>(null)
  const [ctxState, setCtxState] = useState<"idle" | "loading" | "error">("idle")
  const [ctxAttempt, setCtxAttempt] = useState(0)
  const [mine, setMine] = useState<VendorRequest[]>([])
  const [expanded, setExpanded] = useState(false)
  const [preview, setPreview] = useState(false)
  const ctxAbort = useRef<AbortController | null>(null)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const [detail, requests] = await Promise.all([
        fetchEventDetail(eventId, auth),
        fetchVendorRequests(auth).catch(() => ({ items: [] as VendorRequest[], total: 0 })),
      ])
      setEvent(detail)
      setMine(requests.items)
      if (detail) {
        setSelectedId((current) => {
          if (current && detail.options.some((o) => o.opportunityId === current)) return current
          return (detail.options.find((o) => o.isOpen) ?? detail.options[0])?.opportunityId ?? null
        })
      }
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, eventId])

  useEffect(() => {
    void load()
  }, [load])

  useEffect(() => {
    if (!selectedId || !auth) return
    ctxAbort.current?.abort()
    const controller = new AbortController()
    ctxAbort.current = controller
    setCtxState("loading")
    fetchApplyContext(selectedId, auth)
      .then((ctx) => {
        if (controller.signal.aborted) return
        setApplyCtx(ctx)
        setCtxState("idle")
      })
      .catch((err) => {
        if (controller.signal.aborted || isAbortError(err)) return
        setApplyCtx(null)
        setCtxState("error")
      })
    return () => controller.abort()
  }, [auth, selectedId, ctxAttempt])

  const selected = useMemo(() => event?.options.find((o) => o.opportunityId === selectedId) ?? null, [event, selectedId])
  const existing = useMemo(
    () => (selectedId ? mine.find((r) => r.opportunityId === String(selectedId)) ?? null : null),
    [mine, selectedId],
  )
  const saved = selectedId ? favorites.savedIds.has(selectedId) : false

  async function handleShare() {
    if (!event || !auth) return
    try {
      const link = await fetchShareLink(auth, { type: "event", id: event.eventId }).catch(() => null)
      const url = link?.url
      await Share.share({ message: url ? `${event.title} is booking vendors on LNKup\n${url}` : event.title, url })
    } catch {
      // Share sheet dismissed.
    }
  }

  const cta = event ? ctaFor(event, selected, existing, applyCtx, ctxState, () => setCtxAttempt((n) => n + 1)) : null
  const about = event?.description ?? ""
  const aboutText = !expanded && about.length > PREVIEW_CHARS ? `${about.slice(0, PREVIEW_CHARS).trim()}…` : about
  const env = event ? environmentLabel(event.indoor, event.outdoor) : null
  const when = event
    ? [
        event.dateLabel,
        event.endDateIso && event.endDateIso !== event.dateIso ? `to ${formatShortDate(event.endDateIso)}` : null,
        event.startTime ? `· ${event.startTime}${event.endTime ? `–${event.endTime}` : ""}` : null,
      ]
        .filter(Boolean)
        .join(" ")
    : ""

  return (
    <Screen
      title={event?.title ?? "Event"}
      hideTitle
      header={
        <View style={styles.topBar}>
          <BackLink label="Explore" fallbackHref={"/(vendor)/find" as Href} />
          {event ? (
            <View style={styles.topActions}>
              <IconButton icon="share-outline" label="Share event" onPress={() => void handleShare()} />
              {selectedId ? (
                <IconButton
                  icon={saved ? "heart" : "heart-outline"}
                  color={saved ? colors.red600 : colors.ink}
                  label={saved ? "Remove from saved" : "Save this vendor option"}
                  onPress={() => void favorites.toggle(selectedId)}
                />
              ) : null}
            </View>
          ) : null}
        </View>
      }
      footer={
        cta ? (
          <StickyActionBar caption={cta.note}>
            <Button label={cta.label} icon={cta.icon} style={styles.flex} disabled={cta.disabled || !cta.onPress} onPress={cta.onPress} />
          </StickyActionBar>
        ) : null
      }
    >
      {loading && !event ? <LoadingPanel label="Loading event…" variant="skeleton" withMedia count={1} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {event ? (
        <View style={styles.wrap} testID="vendor-event-detail">
          <Pressable
            onPress={() => event.media && setPreview(true)}
            disabled={!event.media}
            accessibilityRole="imagebutton"
            accessibilityLabel={`${event.imageAlt ?? event.title}. Tap to view full image`}
          >
            <EventImage
              uri={event.media?.largeUrl || event.media?.url}
              fallbackUri={event.fallbackImageUrl}
              fit={event.media?.fit}
              background={event.media?.background}
              label={event.categories[0] ?? "Event"}
              height={196}
              radius={radii.lg}
            />
          </Pressable>

          <View style={styles.identity}>
            <Text style={styles.title} accessibilityRole="header">
              {event.title}
            </Text>
            {when ? <Meta>{when}</Meta> : null}
            {event.place ? <Meta>{[event.venue, event.place].filter(Boolean).join(" · ")}</Meta> : null}
            {event.organizerName ? (
              <View style={styles.organizer}>
                <AppIcon name={event.organizerVerified ? "shield-checkmark" : "business-outline"} size={15} color={event.organizerVerified ? colors.green700 : colors.gray500} />
                <Meta style={styles.organizerText}>
                  Hosted by {event.organizerName}
                  {event.organizerVerified ? " · Verified organizer" : ""}
                </Meta>
              </View>
            ) : null}
            {event.eventState === "completed" ? (
              <View style={styles.notice}>
                <Meta style={styles.noticeText}>This event has already happened.</Meta>
              </View>
            ) : null}
          </View>

          <View style={styles.section}>
            <SectionTitle accessibilityRole="header" style={styles.sectionTitle}>
              {event.options.length > 1 ? `Vendor options (${event.options.length})` : "Vendor option"}
            </SectionTitle>
            {event.options.length > 1 ? <Meta>Each option has its own fee, spaces and deadline.</Meta> : null}
            <View style={styles.options} accessibilityRole="radiogroup">
              {event.options.map((option) => (
                <OptionCard
                  key={option.opportunityId}
                  option={option}
                  gated={event.gated}
                  selected={option.opportunityId === selectedId}
                  onSelect={() => setSelectedId(option.opportunityId)}
                  applied={mine.find((r) => r.opportunityId === String(option.opportunityId)) ?? null}
                />
              ))}
            </View>
            {!event.gated ? (
              <Meta style={styles.feeNote}>
                Fees shown are the organizer's booth fee. You only pay if you're approved, and any marketplace fee is shown before you pay.
              </Meta>
            ) : null}
          </View>

          <ListGroup title="Event details">
            {when ? <ListRow icon="calendar-outline" title="Date" meta={when} /> : null}
            {event.venue || event.venueAddress ? (
              <ListRow icon="location-outline" title={event.venue ?? "Venue"} meta={[event.venueAddress, event.place].filter(Boolean).join(" · ")} />
            ) : null}
            {event.attendance ? (
              <ListRow icon="people-outline" title={`${event.attendance.toLocaleString("en-US")} expected`} meta="Organizer-estimated attendance. Not independently verified." />
            ) : null}
            {env ? <ListRow icon="partly-sunny-outline" title={env} /> : null}
            {event.eventTypes.length ? <ListRow icon="pricetags-outline" title="Event type" meta={event.eventTypes.join(", ")} /> : null}
          </ListGroup>

          {about ? (
            <View style={styles.section}>
              <SectionTitle accessibilityRole="header" style={styles.sectionTitle}>
                About this event
              </SectionTitle>
              <Body>{aboutText}</Body>
              {about.length > PREVIEW_CHARS ? (
                <Pressable onPress={() => setExpanded((v) => !v)} hitSlop={8} accessibilityRole="button">
                  <Text style={styles.more}>{expanded ? "Show less" : "Show more"}</Text>
                </Pressable>
              ) : null}
            </View>
          ) : null}
        </View>
      ) : null}

      {event?.media ? (
        <Modal visible={preview} transparent animationType="fade" onRequestClose={() => setPreview(false)}>
          <Pressable style={styles.previewBackdrop} onPress={() => setPreview(false)} accessibilityRole="button" accessibilityLabel="Close image">
            <EventImage uri={event.media.largeUrl} fit="contain" height={520} radius={0} accessibilityLabel={event.imageAlt ?? event.title} />
          </Pressable>
        </Modal>
      ) : null}
    </Screen>
  )
}

function ctaFor(
  event: EventDetail,
  option: EventOption | null,
  existing: VendorRequest | null,
  ctx: ApplyContext | null,
  ctxState: "idle" | "loading" | "error",
  retry: () => void,
): Cta | null {
  if (existing) {
    if (hasOpenBalance(existing)) {
      return { label: payCtaLabel(existing), icon: "card-outline", onPress: () => router.push(`/pass/${existing.id}` as Href) }
    }
    return {
      label: existing.status === "paid" || existing.status === "completed" ? "View booking" : "View application",
      icon: "document-text-outline",
      onPress: () => router.push(`/booking/${existing.id}` as Href),
    }
  }
  if (!option) return null
  if (event.eventState === "completed" || event.eventState === "cancelled") {
    return { label: event.eventState === "cancelled" ? "Event cancelled" : "Event has ended", disabled: true }
  }
  if (ctxState === "loading") return { label: "Checking availability…", disabled: true }
  if (ctxState === "error" || !ctx) {
    return { label: "Try again", icon: "refresh", note: "We couldn't check if applications are open.", onPress: retry }
  }
  if (ctx.draft) {
    return { label: "Continue application", icon: "create-outline", onPress: () => router.push(`/apply/${option.opportunityId}` as Href) }
  }
  if (ctx.canApply && !ctx.buttonDisabled) {
    return {
      label: "Start application",
      icon: "arrow-forward",
      note: "You'll review everything before submitting. Applying is free.",
      onPress: () => {
        void trackMobileEvent({ event: "apply_clicked", source: "event_detail", metadata: { opportunity_id: option.opportunityId, event_id: event.eventId } })
        router.push(`/apply/${option.opportunityId}` as Href)
      },
    }
  }
  if (option.spotsRemaining === 0) return { label: "This option is full", disabled: true, note: ctx.blockReason }
  return { label: "Applications closed", disabled: true, note: ctx.blockReason }
}

function OptionCard({
  option,
  gated,
  selected,
  onSelect,
  applied,
}: {
  option: EventOption
  gated: boolean
  selected: boolean
  onSelect: () => void
  applied: VendorRequest | null
}) {
  const availability = optionAvailability(option)
  const deadline = deadlineLabel(option.deadlineIso)
  const amenities = [option.powerAvailable ? "Power" : null, option.waterAvailable ? "Water" : null, option.loadInTime ? `Load-in ${option.loadInTime}` : null].filter(Boolean)
  return (
    <Pressable
      onPress={onSelect}
      accessibilityRole="radio"
      accessibilityState={{ checked: selected }}
      aria-checked={selected}
      accessibilityLabel={[option.title, optionPriceLabel(option, gated), availability?.label, deadline?.label, applied ? "You applied" : null].filter(Boolean).join(", ")}
      style={({ pressed }) => [styles.option, selected && styles.optionSelected, pressed && styles.pressed]}
    >
      <View style={[styles.radio, selected && styles.radioOn]}>{selected ? <View style={styles.radioDot} /> : null}</View>
      <View style={styles.optionBody}>
        <View style={styles.optionHead}>
          <Text style={styles.optionTitle} numberOfLines={2}>
            {option.title}
          </Text>
          <Text style={styles.optionPrice}>{optionPriceLabel(option, gated)}</Text>
        </View>
        {option.categories.length && option.categories.join(" · ") !== option.title ? <Meta numberOfLines={1}>{option.categories.join(" · ")}</Meta> : null}
        <Meta style={[availability?.tone === "low" && styles.warn, availability?.tone === "closed" && styles.muted]}>
          {[availability?.label, deadline?.label].filter(Boolean).join(" · ") || "Details from the organizer"}
        </Meta>
        {amenities.length || option.documents.length ? (
          <Meta numberOfLines={2} style={styles.optionExtra}>
            {[amenities.join(" · "), option.documents.length ? `Requires ${option.documents.join(", ")}` : null].filter(Boolean).join(" · ")}
          </Meta>
        ) : null}
        {applied ? <Text style={styles.applied}>You applied to this option</Text> : null}
      </View>
    </Pressable>
  )
}

function IconButton({ icon, label, onPress, color = colors.ink }: { icon: AppIconName; label: string; onPress: () => void; color?: string }) {
  return (
    <Pressable onPress={onPress} accessibilityRole="button" accessibilityLabel={label} hitSlop={6} style={({ pressed }) => [styles.iconBtn, pressed && styles.pressed]}>
      <AppIcon name={icon} size={20} color={color} />
    </Pressable>
  )
}

const styles = StyleSheet.create({
  topBar: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  topActions: { flexDirection: "row", gap: spacing.xs },
  iconBtn: { width: 44, height: 44, borderRadius: radii.pill, alignItems: "center", justifyContent: "center" },
  wrap: { gap: spacing.lg },
  identity: { gap: 4 },
  title: { fontFamily: fonts.display, fontSize: typeScale.title + 2, lineHeight: 30, color: colors.ink },
  organizer: { flexDirection: "row", alignItems: "center", gap: 6, marginTop: 2 },
  organizerText: { flexShrink: 1, color: colors.gray700 },
  notice: { marginTop: spacing.sm, padding: spacing.md, borderRadius: radii.md, backgroundColor: colors.gray100 },
  noticeText: { color: colors.gray700 },
  section: { gap: spacing.sm },
  sectionTitle: { fontSize: typeScale.headline + 1 },
  options: { gap: spacing.sm },
  option: {
    flexDirection: "row",
    gap: spacing.md,
    padding: spacing.md,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
  },
  optionSelected: { borderColor: colors.ink, borderWidth: 2, padding: spacing.md - 1 },
  pressed: { opacity: 0.85 },
  radio: { width: 22, height: 22, borderRadius: 11, borderWidth: 2, borderColor: colors.gray400, alignItems: "center", justifyContent: "center", marginTop: 2 },
  radioOn: { borderColor: colors.ink },
  radioDot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.ink },
  optionBody: { flex: 1, gap: 3 },
  optionHead: { flexDirection: "row", gap: spacing.sm, alignItems: "flex-start" },
  optionTitle: { flex: 1, fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  optionPrice: { fontFamily: fonts.bodyBold, fontSize: typeScale.callout, color: colors.ink },
  optionExtra: { color: colors.gray700 },
  warn: { color: colors.amber700 },
  muted: { color: colors.gray500 },
  applied: { fontFamily: fonts.bodySemi, fontSize: typeScale.caption, color: colors.green700 },
  feeNote: { color: colors.gray500 },
  more: { fontFamily: fonts.bodySemi, fontSize: typeScale.body, color: colors.ink },
  flex: { flex: 1 },
  previewBackdrop: { flex: 1, backgroundColor: "rgba(0,0,0,0.92)", justifyContent: "center" },
})
