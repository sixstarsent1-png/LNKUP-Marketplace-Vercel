import { useCallback, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { ProfileMark } from "@/components/profile-mark"
import { ApproveIntelModal } from "@/components/organizer-vendors/approve-intel-modal"
import { InfoRequestSheet } from "@/components/organizer-vendors/info-request-sheet"
import { matchesFilter, parseFilterKey, vendorProfileHref, type FilterKey } from "@/components/organizer-vendors/vendor-queue"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { StatusBadge } from "@/components/ui/status-badge"
import { StatusPill } from "@/components/ui/status-pill"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { formatMoney } from "@/lib/api/format-money"
import { invalidateOrganizerMoney } from "@/lib/api/organizer-financial"
import { fetchApplicationReview, invalidateOrganizerMobile, type ApplicationReview } from "@/lib/api/organizer-mobile"
import {
  fetchApplicationIntel,
  fetchOrganizerRequests,
  normalizeOrganizerRequestStatus,
  reviewOrganizerRequest,
  type ApplicationIntel,
  type OrganizerReviewAction,
  type OrganizerVendorRequest,
} from "@/lib/api/organizer-requests"
import { useAuth } from "@/lib/auth/auth-context"
import { promptText, showToast } from "@/lib/feedback/feedback"
import { formatDateTime, formatShortDate } from "@/lib/format/date"
import { colors, fonts, spacing, typeScale, typography, type StatusTone } from "@/lib/theme"

type Outcome = { tone: "success" | "info"; message: string }

const DOC_TONE: Record<string, { label: string; tone: StatusTone }> = {
  approved: { label: "Approved", tone: "paid" },
  expiring: { label: "Expiring soon", tone: "due" },
  pending: { label: "Waiting for your review", tone: "review" },
  rejected: { label: "Rejected", tone: "rejected" },
  expired: { label: "Expired", tone: "rejected" },
  missing: { label: "Not uploaded", tone: "neutral" },
}

function toRequest(review: ApplicationReview): OrganizerVendorRequest {
  return {
    id: review.applicationId,
    vendorBusinessName: review.vendor.businessName,
    vendorCategory: review.vendor.category,
    vendorProfileId: review.vendor.profileId ?? undefined,
    eventId: review.event.id,
    eventName: review.event.title,
    opportunityName: review.event.boothType,
    status: normalizeOrganizerRequestStatus(review.status),
    amountDue: review.fee,
  }
}

function outcomeFor(name: string, status: string): Outcome {
  switch (status) {
    case "payment_required":
    case "approved":
      return { tone: "success", message: `${name} is approved. We've sent the invoice; the booth is confirmed when they pay.` }
    case "paid":
      return { tone: "success", message: `${name} is confirmed.` }
    case "waitlisted":
      return { tone: "info", message: `${name} is on the waitlist.` }
    case "rejected":
      return { tone: "info", message: `${name} was declined and has been told why.` }
    case "info_requested":
      return { tone: "info", message: `We've asked ${name} for more information.` }
    default:
      return { tone: "info", message: "Saved." }
  }
}

/** One application, full context, and the decision bar. */
export default function ApplicationReviewScreen() {
  const { applicationId, filter: filterParam, eventId: eventParam } = useLocalSearchParams<{
    applicationId: string
    filter?: string
    eventId?: string
  }>()
  const { auth } = useAuth()
  const id = String(applicationId ?? "")
  const queueFilter: FilterKey = parseFilterKey(filterParam) ?? "review"
  const [review, setReview] = useState<ApplicationReview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [acting, setActing] = useState<OrganizerReviewAction | null>(null)
  const [outcome, setOutcome] = useState<Outcome | null>(null)
  const [queue, setQueue] = useState<OrganizerVendorRequest[]>([])
  const [intelOpen, setIntelOpen] = useState(false)
  const [intel, setIntel] = useState<ApplicationIntel | null>(null)
  const [intelLoading, setIntelLoading] = useState(false)
  const [infoOpen, setInfoOpen] = useState(false)
  const inFlight = useRef(false)

  const load = useCallback(async () => {
    if (!auth || !id) return
    setError(null)
    try {
      const [data, requests] = await Promise.all([
        fetchApplicationReview(id, auth),
        fetchOrganizerRequests(auth).catch(() => null),
      ])
      setReview(data)
      if (requests) setQueue(requests.items)
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useFocusEffect(
    useCallback(() => {
      setOutcome(null)
      void load()
    }, [load]),
  )

  const remaining = useMemo(
    () =>
      queue.filter(
        (item) => item.id !== id && matchesFilter(item.status, queueFilter) && (!eventParam || item.eventId === eventParam),
      ),
    [queue, id, queueFilter, eventParam],
  )

  async function decide(action: OrganizerReviewAction, reason?: string) {
    if (!review || !auth || inFlight.current) return
    inFlight.current = true
    setActing(action)
    try {
      await reviewOrganizerRequest(toRequest(review), action, auth, reason)
      invalidateOrganizerMoney()
      invalidateOrganizerMobile(review.event.id)
      const fresh = await fetchApplicationReview(id, auth)
      if (fresh) {
        setReview(fresh)
        setOutcome(outcomeFor(fresh.vendor.businessName, fresh.status))
      }
      const requests = await fetchOrganizerRequests(auth, { force: true }).catch(() => null)
      if (requests) setQueue(requests.items)
    } catch (err) {
      showToast(`Couldn't update this request. ${appErrorFromWp(err).message}`, "error")
      void load()
    } finally {
      inFlight.current = false
      setActing(null)
    }
  }

  function openApprove() {
    setIntelOpen(true)
    setIntel(null)
    setIntelLoading(true)
    void fetchApplicationIntel(id, auth)
      .then(setIntel)
      .catch(() => setIntel(null))
      .finally(() => setIntelLoading(false))
  }

  async function decline() {
    if (!review) return
    const reason = await promptText({
      title: `Decline ${review.vendor.businessName}?`,
      message: "The vendor sees this reason with the decision. Keep it short and kind.",
      placeholder: "e.g. We already have enough food vendors for this date",
      confirmLabel: "Decline",
      minLength: 3,
    })
    if (reason) void decide("reject", reason)
  }

  function reviewNext() {
    const next = remaining[0]
    if (!next) {
      router.replace(`/(organizer)/vendors?tab=requests&filter=${queueFilter}` as Href)
      return
    }
    const query = [`filter=${queueFilter}`, eventParam ? `eventId=${eventParam}` : ""].filter(Boolean).join("&")
    router.replace(`/(organizer)/vendors/review/${next.id}?${query}` as Href)
  }

  const request = review ? toRequest(review) : null
  const decided = Boolean(outcome)

  return (
    <Screen
      title={review?.vendor.businessName ?? "Application"}
      hideTitle
      refreshing={false}
      onRefresh={() => void load()}
      header={<BackLink label="Requests" fallbackHref="/(organizer)/vendors?tab=requests" />}
      footer={
        review && !decided && (review.actions.approve || review.actions.reject) ? (
          <StickyActionBar insideTabs>
            <View style={styles.decisionColumn}>
            <View style={styles.decisionRow}>
              {review.actions.approve ? (
                <Button label="Approve" icon="checkmark" style={styles.flex} loading={acting === "approve"} disabled={Boolean(acting)} onPress={openApprove} testID="review-approve" />
              ) : null}
              {review.actions.waitlist ? (
                <Button label="Waitlist" variant="secondary" style={styles.flex} loading={acting === "waitlist"} disabled={Boolean(acting)} onPress={() => void decide("waitlist")} testID="review-waitlist" />
              ) : null}
            </View>
            <View style={styles.decisionRow}>
              {review.actions.requestInfo ? (
                <Button label="Ask a question" variant="ghost" size="sm" style={styles.flex} disabled={Boolean(acting)} onPress={() => setInfoOpen(true)} testID="review-request-info" />
              ) : null}
              {review.actions.reject ? (
                <Button label="Decline" variant="danger" size="sm" style={styles.flex} loading={acting === "reject"} disabled={Boolean(acting)} onPress={() => void decline()} testID="review-decline" />
              ) : null}
            </View>
            </View>
          </StickyActionBar>
        ) : decided ? (
          <StickyActionBar insideTabs>
            <Button
              style={styles.flex}
              label={remaining.length > 0 ? `Review next · ${remaining.length} left` : "Back to requests"}
              icon={remaining.length > 0 ? "arrow-forward" : "list-outline"}
              onPress={reviewNext}
              testID="review-next"
            />
          </StickyActionBar>
        ) : null
      }
    >
      {loading && !review ? <LoadingPanel label="Loading application…" variant="skeleton" count={3} /> : null}
      {!loading && error && !review ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}
      {!loading && !error && !review ? (
        <EmptyPanel
          icon="file-tray-outline"
          title="Application not available"
          message="It may have been withdrawn, or it belongs to another organizer."
          actionLabel="Back to requests"
          onAction={() => router.replace("/(organizer)/vendors?tab=requests" as Href)}
        />
      ) : null}

      {review ? (
        <View style={styles.page} testID="application-review">
          {outcome ? <InlineBanner tone={outcome.tone === "success" ? "success" : "info"} message={outcome.message} /> : null}

          <View style={styles.identity}>
            <ProfileMark name={review.vendor.businessName} logoUrl={review.vendor.logoUrl || undefined} size={56} />
            <View style={styles.identityText}>
              <Text style={styles.name} accessibilityRole="header">
                {review.vendor.businessName}
              </Text>
              <Text style={styles.meta}>{[review.vendor.category, review.vendor.place].filter(Boolean).join(" · ")}</Text>
              <View style={styles.badges}>
                <StatusPill status={normalizeOrganizerRequestStatus(review.status)} audience="organizer" />
                {review.vendor.verified ? <StatusBadge label="Verified" tone="paid" /> : null}
              </View>
            </View>
          </View>

          <Surface style={styles.card}>
            <SectionHeader title="Track record" icon="ribbon-outline" />
            <Text style={styles.meta}>
              {review.vendor.completedEvents > 0
                ? `${review.vendor.completedEvents} completed event${review.vendor.completedEvents === 1 ? "" : "s"} on LNKup`
                : "No completed events on LNKup yet"}
              {review.vendor.reviewCount > 0 ? ` · ${review.vendor.rating.toFixed(1)} from ${review.vendor.reviewCount} review${review.vendor.reviewCount === 1 ? "" : "s"}` : ""}
              {review.vendor.reputation ? ` · ${review.vendor.reputation}` : ""}
            </Text>
            {review.vendor.profileId ? (
              <Button
                label="Open full profile"
                variant="ghost"
                size="sm"
                icon="person-outline"
                onPress={() => router.push(vendorProfileHref(review.vendor.profileId!, review.applicationId) as Href)}
              />
            ) : null}
          </Surface>

          <ListGroup title="Booking">
            <ListRow icon="calendar-outline" title={review.event.title} meta={[formatShortDate(review.event.date), review.event.place].filter(Boolean).join(" · ")} onPress={() => router.push(`/(organizer)/events/${review.event.id}` as Href)} />
            <ListRow
              icon="storefront-outline"
              title={review.event.boothType || "Vendor booth"}
              meta={review.event.unlimited ? "No spot limit" : `${review.event.open} open spot${review.event.open === 1 ? "" : "s"}`}
              rightSlot={<Text style={styles.fee}>{review.fee > 0 ? formatMoney(review.fee) : "Free"}</Text>}
            />
            <ListRow icon="time-outline" title="Applied" meta={formatDateTime(review.submittedAt) ?? "—"} />
            {review.paymentDueAt ? <ListRow icon="card-outline" title="Payment due" meta={formatDateTime(review.paymentDueAt, { gmt: true }) ?? ""} /> : null}
          </ListGroup>

          {!review.event.unlimited && review.event.open < 1 && review.actions.approve ? (
            <InlineBanner tone="attention" title="This booth type is full" message="Approving won't be possible until a spot opens. You can waitlist the vendor instead." />
          ) : null}

          <Surface style={styles.card}>
            <SectionHeader title="Application" icon="chatbubble-ellipses-outline" />
            {review.message ? <Text style={styles.body}>{review.message}</Text> : <Text style={styles.meta}>No message from the vendor.</Text>}
            {review.setupNotes ? (
              <>
                <Text style={styles.question}>Setup notes</Text>
                <Text style={styles.body}>{review.setupNotes}</Text>
              </>
            ) : null}
            {review.answers.map((answer, index) => (
              <View key={`${answer.question}-${index}`} style={styles.answer}>
                <Text style={styles.question}>{answer.question}</Text>
                <Text style={styles.body}>{answer.answer}</Text>
              </View>
            ))}
          </Surface>

          <ListGroup title="Documents">
            {review.documents.items.length === 0 ? (
              <ListRow icon="document-outline" title="No documents required for this booth" />
            ) : (
              review.documents.items.map((doc) => {
                const tone = DOC_TONE[doc.status] ?? { label: doc.status, tone: "neutral" as StatusTone }
                return <ListRow key={doc.slug} icon="document-text-outline" title={doc.label} rightSlot={<StatusBadge label={tone.label} tone={tone.tone} />} />
              })
            )}
          </ListGroup>
        </View>
      ) : null}

      <ApproveIntelModal
        item={intelOpen ? request : null}
        intel={intel}
        loading={intelLoading}
        acting={acting === "approve"}
        note={review?.approveNote}
        onCancel={() => setIntelOpen(false)}
        onApprove={() => {
          setIntelOpen(false)
          void decide("approve")
        }}
      />

      <InfoRequestSheet
        item={infoOpen ? request : null}
        auth={auth}
        onClose={() => setInfoOpen(false)}
        onSent={() => {
          setInfoOpen(false)
          invalidateOrganizerMobile(review?.event.id)
          setOutcome(review ? outcomeFor(review.vendor.businessName, "info_requested") : null)
          void load()
        }}
      />
    </Screen>
  )
}

const styles = StyleSheet.create({
  page: { gap: spacing.lg },
  identity: { flexDirection: "row", gap: spacing.md, alignItems: "center" },
  identityText: { flex: 1, gap: spacing.xs },
  name: { fontFamily: fonts.display, fontSize: typeScale.title, color: colors.black },
  meta: { ...typography.meta },
  badges: { flexDirection: "row", flexWrap: "wrap", gap: spacing.xs },
  card: { gap: spacing.sm },
  fee: { fontFamily: fonts.bodyBold, fontSize: typeScale.callout, color: colors.black },
  body: { ...typography.body },
  answer: { gap: 2 },
  question: { ...typography.label },
  decisionColumn: { flex: 1, gap: spacing.xs },
  decisionRow: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
})
