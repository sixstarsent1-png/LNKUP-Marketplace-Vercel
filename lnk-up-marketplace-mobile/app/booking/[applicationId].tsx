import { useCallback, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { Redirect, router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { InfoRequestReply } from "@/components/info-request-reply"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusPill } from "@/components/ui/status-pill"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { BookingTimelineView } from "@/components/vendor/booking-timeline-view"
import { canWithdraw, withdrawApplication } from "@/lib/api/apply"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorRequests, invalidateVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { formatMoney } from "@/lib/api/vendor-wallet"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { bookingTimeline } from "@/lib/vendor/booking-timeline"
import { spacing, typeScale } from "@/lib/theme"

type PrimaryAction = { label: string; icon: "card-outline" | "ticket-outline" | "search-outline"; href: Href }

function primaryActionFor(request: VendorRequest): PrimaryAction | null {
  if (request.status === "approved" || request.status === "payment_required") {
    const amount =
      request.amountDue != null && request.amountDue > 0 ? formatMoney(request.amountDue, request.currency ?? "USD") : null
    return { label: amount ? `Pay ${amount}` : "Pay booth fee", icon: "card-outline", href: `/pass/${request.id}` as Href }
  }
  if (request.status === "paid" || request.status === "completed") {
    return { label: "Open event pass", icon: "ticket-outline", href: `/pass/${request.id}` as Href }
  }
  if (request.status === "cancelled" && request.opportunityId) {
    return { label: "Apply again", icon: "search-outline", href: `/apply/${request.opportunityId}` as Href }
  }
  if (request.status === "rejected" && request.opportunityId) {
    return { label: "Find similar events", icon: "search-outline", href: "/(vendor)/find" as Href }
  }
  return null
}

/** One application/booking: status, timeline, organizer question, and the next action. */
export default function BookingDetailScreen() {
  const { applicationId } = useLocalSearchParams<{ applicationId: string }>()
  const { auth, isAuthenticated } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [request, setRequest] = useState<VendorRequest | null>(null)
  const loadedOnce = useRef(false)
  const id = String(applicationId ?? "")

  const load = useCallback(
    async (force: boolean) => {
      if (force) setRefreshing(true)
      setError(null)
      try {
        const { items } = await fetchVendorRequests(auth, { force })
        setRequest(items.find((item) => item.id === id) ?? null)
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, id],
  )

  useFocusEffect(
    useCallback(() => {
      void load(loadedOnce.current)
      loadedOnce.current = true
    }, [load]),
  )

  const timeline = useMemo(() => (request ? bookingTimeline(request) : []), [request])
  const action = request ? primaryActionFor(request) : null
  const [withdrawing, setWithdrawing] = useState(false)

  async function handleWithdraw() {
    if (!request || withdrawing) return
    const confirmed = await confirmAction({
      title: "Withdraw this application?",
      message: "The organizer won't review it. You can apply again later while applications are open.",
      confirmLabel: "Withdraw",
      cancelLabel: "Keep it",
      destructive: true,
    })
    if (!confirmed) return
    setWithdrawing(true)
    try {
      await withdrawApplication(request.id, auth)
      showToast("Application withdrawn.", "success")
      void load(true)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setWithdrawing(false)
    }
  }

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/booking/${id}` } }} />
  }

  return (
    <Screen
      title="Booking"
      hideTitle
      refreshing={refreshing}
      onRefresh={() => void load(true)}
      header={<BackLink label="Bookings" fallbackHref="/(vendor)/requests" />}
      footer={
        action ? (
          <StickyActionBar>
            <Button label={action.label} icon={action.icon} style={styles.flex} onPress={() => router.push(action.href)} />
          </StickyActionBar>
        ) : null
      }
    >
      {loading ? <LoadingPanel label="Loading booking…" variant="skeleton" count={2} /> : null}

      {!loading && error && !request ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load(true)} />
      ) : null}

      {!loading && !error && !request ? (
        <EmptyPanel
          icon="file-tray-outline"
          title="Booking not found"
          message="It may have been withdrawn, or it belongs to another account."
          actionLabel="Back to Bookings"
          onAction={() => router.replace("/(vendor)/requests")}
        />
      ) : null}

      {request ? (
        <>
          <View style={styles.hero}>
            <StatusPill status={request.status} audience="vendor" />
            <DisplayTitle accessibilityRole="header" style={styles.title}>
              {request.eventName}
            </DisplayTitle>
            <Meta>{[request.eventDate, request.city].filter(Boolean).join(" · ")}</Meta>
          </View>

          {request.status === "info_requested" && request.infoRequest ? (
            <InfoRequestReply
              applicationId={request.id}
              request={request.infoRequest}
              auth={auth}
              onReplied={() => {
                invalidateVendorRequests()
                showToast("Reply sent. The organizer will review it.", "success")
                void load(true)
              }}
            />
          ) : null}

          <Surface elevated style={styles.card}>
            <SectionTitle accessibilityRole="header" style={styles.cardTitle}>
              Progress
            </SectionTitle>
            <BookingTimelineView steps={timeline} />
          </Surface>

          <ListGroup title="Details">
            {request.organizerName ? <ListRow icon="business-outline" title="Organizer" meta={request.organizerName} /> : null}
            {request.category ? <ListRow icon="pricetag-outline" title="Vendor category" meta={request.category} /> : null}
            {request.amountDue != null && request.amountDue > 0 ? (
              <ListRow
                icon="card-outline"
                title="Booth fee"
                meta={formatMoney(request.amountDue, request.currency ?? "USD")}
              />
            ) : null}
            {request.submittedAt ? <ListRow icon="time-outline" title="Applied" meta={request.submittedAt} /> : null}
            {request.opportunityId ? (
              <ListRow
                icon="storefront-outline"
                title="Event listing"
                meta="Venue, booth types and requirements"
                onPress={() => router.push(`/opportunity/${request.opportunityId}` as Href)}
              />
            ) : null}
          </ListGroup>

          {canWithdraw(request.status, request.paymentStatus) ? (
            <Button
              label="Withdraw application"
              variant="ghost"
              loading={withdrawing}
              loadingLabel="Withdrawing…"
              onPress={() => void handleWithdraw()}
              testID="booking-withdraw"
            />
          ) : null}
        </>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  hero: { gap: spacing.sm },
  title: { fontSize: typeScale.display, lineHeight: 32 },
  card: { gap: spacing.md },
  cardTitle: { fontSize: typeScale.bodyLarge },
  flex: { flex: 1 },
})
