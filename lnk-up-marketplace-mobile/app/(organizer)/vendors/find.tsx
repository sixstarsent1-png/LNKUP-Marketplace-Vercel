import { useCallback, useEffect, useMemo, useState } from "react"
import { Pressable, ScrollView, StyleSheet, View } from "react-native"
import { router, useLocalSearchParams, type Href } from "expo-router"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchOrganizerEvents, type OrganizerEventLite } from "@/lib/api/organizer-events"
import {
  fetchEventVendorMatches,
  fetchRecruitment,
  previewInvites,
  sendBatchInvites,
  shortlistVendors,
  type EventVendorMatches,
  type InvitePreview,
  type MatchedVendor,
  type RecruitmentSummary,
} from "@/lib/api/match"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { colors, radii, spacing } from "@/lib/theme"

const STAGE_LABELS: Record<string, string> = {
  shortlisted: "Shortlisted",
  invited: "Invited",
  opened: "Opened invite",
  applied: "Applied",
  approved: "Approved",
  paid: "Booked",
  declined: "Declined",
}

/** Organizer Find Vendors: matching discoverable vendors for one event, shortlist and batch invite. */
export default function OrganizerFindVendorsScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ event?: string }>()
  const [events, setEvents] = useState<OrganizerEventLite[]>([])
  const [eventId, setEventId] = useState<number | null>(params.event ? Number(params.event) : null)
  const [matches, setMatches] = useState<EventVendorMatches | null>(null)
  const [recruitment, setRecruitment] = useState<RecruitmentSummary | null>(null)
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [preview, setPreview] = useState<InvitePreview | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let cancelled = false
    fetchOrganizerEvents(auth ?? undefined)
      .then((result) => {
        if (cancelled) return
        const upcoming = result.items.filter((e) => e.status !== "past" && e.publishStatus !== "draft")
        setEvents(upcoming)
        setEventId((current) => current ?? (upcoming[0] ? Number(upcoming[0].id) : null))
        if (!upcoming.length) setLoading(false)
      })
      .catch((err) => {
        if (!cancelled) {
          setError(appErrorFromWp(err))
          setLoading(false)
        }
      })
    return () => {
      cancelled = true
    }
  }, [auth])

  const load = useCallback(async () => {
    if (!auth || !eventId) return
    setLoading(true)
    setError(null)
    setSelected(new Set())
    setPreview(null)
    try {
      const [vendors, summary] = await Promise.all([
        fetchEventVendorMatches(auth, eventId),
        fetchRecruitment(auth, eventId).catch(() => null),
      ])
      setMatches(vendors)
      setRecruitment(summary)
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, eventId])

  useEffect(() => {
    void load()
  }, [load])

  const vendors: MatchedVendor[] = useMemo(() => (matches?.status === "ready" ? matches.items : []), [matches])

  function toggleSelected(profileId: number) {
    setSelected((current) => {
      const next = new Set(current)
      if (next.has(profileId)) next.delete(profileId)
      else next.add(profileId)
      return next
    })
    setPreview(null)
  }

  async function handleShortlist() {
    if (!auth || !eventId || !selected.size || busy) return
    setBusy(true)
    try {
      await shortlistVendors(auth, eventId, Array.from(selected))
      showToast("Added to your shortlist.", "success")
      await load()
    } catch (err) {
      showToast(`Couldn't update your shortlist. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setBusy(false)
    }
  }

  async function handlePreview() {
    if (!auth || !eventId || !selected.size || busy) return
    setBusy(true)
    try {
      setPreview(await previewInvites(auth, eventId, Array.from(selected)))
    } catch (err) {
      showToast(`Couldn't preview invites. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setBusy(false)
    }
  }

  async function handleSend() {
    if (!auth || !eventId || !preview || !preview.recipients.length || busy) return
    const ok = await confirmAction({
      title: `Invite ${preview.recipients.length} vendor${preview.recipients.length === 1 ? "" : "s"}?`,
      message: "Each vendor gets an invite to apply. Inviting a vendor doesn't book them.",
      confirmLabel: "Send invites",
    })
    if (!ok) return
    setBusy(true)
    try {
      const job = await sendBatchInvites(auth, eventId, preview.recipients.map((r) => r.profileId))
      showToast(`Sending ${job.total} invite${job.total === 1 ? "" : "s"}.`, "success")
      await load()
    } catch (err) {
      showToast(`Couldn't send invites. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen
      title="Find vendors"
      subtitle="Discoverable vendors that match your event"
      header={<BackLink label="Vendors" fallbackHref={"/(organizer)/vendors" as Href} />}
      onRefresh={() => void load()}
      footer={
        selected.size > 0 ? (
          <StickyActionBar caption={`${selected.size} selected`}>
            <Button label="Shortlist" variant="secondary" loading={busy} onPress={() => void handleShortlist()} />
            {preview ? (
              <Button
                label={`Send ${preview.recipients.length}`}
                loading={busy}
                disabled={!preview.recipients.length}
                style={styles.flex}
                onPress={() => void handleSend()}
              />
            ) : (
              <Button label="Preview invites" loading={busy} style={styles.flex} onPress={() => void handlePreview()} />
            )}
          </StickyActionBar>
        ) : null
      }
    >
      {events.length > 0 ? (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.events}>
          {events.map((event) => (
            <FilterChip
              key={event.id}
              label={event.title}
              selected={Number(event.id) === eventId}
              onPress={() => setEventId(Number(event.id))}
            />
          ))}
        </ScrollView>
      ) : null}

      {recruitment?.suggestions.length ? (
        <View testID="recruitment-suggestions" style={styles.suggestions}>
          {recruitment.suggestions.slice(0, 3).map((s) => (
            <Surface key={`${s.type}-${s.title}`}>
              <SectionTitle>{s.title}</SectionTitle>
              <Meta>{s.message}</Meta>
            </Surface>
          ))}
        </View>
      ) : null}

      {preview ? (
        <Surface>
          <SectionTitle>Invite preview</SectionTitle>
          <Meta>{preview.subject}</Meta>
          <Body>{preview.body}</Body>
          {preview.suppressed.map((s) => (
            <Meta key={s.profileId} style={styles.suppressed}>
              Not sent to {s.businessName}: {s.reasonLabel}
            </Meta>
          ))}
        </Surface>
      ) : null}

      {loading ? <LoadingPanel label="Finding matching vendors…" variant="skeleton" count={3} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}
      {!loading && !error && !events.length ? (
        <EmptyPanel
          icon="calendar-outline"
          title="Publish an event first"
          message="Vendor recommendations appear for published, upcoming events with open vendor spots."
          actionLabel="Create event"
          onAction={() => router.push("/(organizer)/launch" as Href)}
        />
      ) : null}
      {!loading && !error && matches?.status === "disabled" ? (
        <EmptyPanel icon="sparkles-outline" title="Coming soon" message="Vendor recommendations are not available yet." />
      ) : null}
      {!loading && !error && matches?.status === "needs_open_opportunity" ? (
        <EmptyPanel
          icon="pricetag-outline"
          title="Open a vendor slot"
          message="Add an open vendor slot with available spaces to see matching vendors."
        />
      ) : null}
      {!loading && !error && matches?.status === "ready" && vendors.length === 0 ? (
        <EmptyPanel
          icon="people-outline"
          title="No matching vendors yet"
          message="Add vendor categories and an address to your event, or share your application link."
        />
      ) : null}

      <View style={styles.list}>
        {vendors.map((vendor) => {
          const isSelected = selected.has(vendor.profileId)
          const stageLabel = STAGE_LABELS[vendor.stage]
          return (
            <Pressable
              key={vendor.profileId}
              onPress={() => toggleSelected(vendor.profileId)}
              onLongPress={() => router.push(`/(organizer)/vendors/${vendor.profileId}` as Href)}
              accessibilityRole="checkbox"
              accessibilityState={{ checked: isSelected }}
              accessibilityLabel={`${vendor.businessName}. ${vendor.reasons.join(". ")}`}
              testID="find-vendor-card"
            >
              <View style={[styles.card, isSelected && styles.cardSelected]}>
                <View style={styles.cardHeader}>
                  <SectionTitle style={styles.flex}>{vendor.businessName}</SectionTitle>
                  {vendor.verified ? <Meta style={styles.verified}>Verified</Meta> : null}
                </View>
                <Meta>
                  {[vendor.primaryCategory, [vendor.city, vendor.state].filter(Boolean).join(", ")].filter(Boolean).join(" · ")}
                </Meta>
                {vendor.reasons.slice(0, 3).map((reason) => (
                  <Meta key={reason} style={styles.reason}>
                    • {reason}
                  </Meta>
                ))}
                {stageLabel ? <Meta style={styles.stage}>{stageLabel}</Meta> : null}
                {!vendor.acceptsInvites ? <Meta style={styles.suppressed}>Not accepting invites from new organizers</Meta> : null}
              </View>
            </Pressable>
          )
        })}
      </View>
    </Screen>
  )
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  events: { gap: spacing.sm, paddingVertical: spacing.sm },
  suggestions: { gap: spacing.sm, marginBottom: spacing.md },
  list: { gap: spacing.md },
  card: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: spacing.xs,
    backgroundColor: colors.white,
  },
  cardSelected: { borderColor: colors.black, backgroundColor: colors.limeMuted },
  cardHeader: { flexDirection: "row", alignItems: "center", gap: spacing.sm },
  verified: { color: colors.green700 },
  reason: { color: colors.gray700 },
  stage: { color: colors.black },
  suppressed: { color: colors.amber700 },
})
