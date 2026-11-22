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
import { isLiveApiEnabled } from "@/lib/api/config"
import { appErrorFromWp, WpFetchError, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorBooking, type BookingPayment, type VendorBooking } from "@/lib/api/vendor-bookings"
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

const money = (amount: number) => formatMoney(amount, "USD")

function PaymentRows({ payment }: { payment: BookingPayment }) {
  return (
    <>
      <ListRow icon="card-outline" title="Booking total" meta={money(payment.total)} />
      {payment.creditApplied > 0 ? (
        <ListRow icon="wallet-outline" title="Wallet credit used" meta={money(payment.creditApplied)} />
      ) : null}
      {payment.cardCharged > 0 ? (
        <ListRow
          icon="checkmark-circle-outline"
          title="Charged to your card"
          meta={[money(payment.cardCharged), payment.paidAt].filter(Boolean).join(" · ")}
        />
      ) : null}
      {payment.amountDue > 0 ? (
        <ListRow
          icon="alert-circle-outline"
          iconTone="accent"
          title="Due now"
          meta={[money(payment.amountDue), payment.dueAt ? `by ${payment.dueAt}` : ""].filter(Boolean).join(" · ")}
        />
      ) : null}
      {payment.refunded > 0 ? <ListRow icon="return-down-back-outline" title="Refunded" meta={money(payment.refunded)} /> : null}
      {payment.receipts.map((receipt) => (
        <ListRow
          key={`receipt-${receipt.id}`}
          icon="receipt-outline"
          title={receipt.number ? `Receipt ${receipt.number}` : "Receipt"}
          meta={[money(receipt.amount), receipt.createdAt].filter(Boolean).join(" · ")}
        />
      ))}
      {payment.refunds.map((refund) => (
        <ListRow
          key={`refund-${refund.id}`}
          icon="return-down-back-outline"
          title={`Refund ${money(refund.amount)}`}
          meta={[refund.status, refund.createdAt].filter(Boolean).join(" · ")}
        />
      ))}
      {payment.receipts.length > 0 ? (
        <ListRow
          icon="wallet-outline"
          title="Receipts and invoices"
          meta="Open in Wallet"
          showChevron
          onPress={() => router.push("/(vendor)/wallet" as Href)}
        />
      ) : null}
    </>
  )
}

/** One application/booking: status, payment, readiness, timeline, organizer question, and the next action. */
export default function BookingDetailScreen() {
  const { applicationId } = useLocalSearchParams<{ applicationId: string }>()
  const { auth, isAuthenticated } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [request, setRequest] = useState<VendorRequest | null>(null)
  const [booking, setBooking] = useState<VendorBooking | null>(null)
  const loadedOnce = useRef(false)
  const id = String(applicationId ?? "")

  const load = useCallback(
    async (force: boolean) => {
      if (force) setRefreshing(true)
      setError(null)
      try {
        // The booking route has no opportunity link or organizer question, and older servers lack it;
        // the (shared, cached) request list fills those in.
        const listPromise = fetchVendorRequests(auth, { force })
        listPromise.catch(() => undefined) // Handled below; avoids an unhandled rejection if the detail call throws first.
        const detail = auth && isLiveApiEnabled() ? await fetchVendorBooking(id, auth) : null
        const listed = detail
          ? await listPromise.then((r) => r.items.find((item) => item.id === id) ?? null).catch(() => null)
          : ((await listPromise).items.find((item) => item.id === id) ?? null)
        setBooking(detail)
        setRequest(detail ? { ...detail.request, opportunityId: listed?.opportunityId, infoRequest: listed?.infoRequest } : listed)
      } catch (err) {
        if (err instanceof WpFetchError && err.status === 404) {
          setBooking(null)
          setRequest(null)
        } else {
          setError(appErrorFromWp(err))
        }
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

  const timeline = useMemo(() => {
    if (booking?.timeline.length) return booking.timeline
    return request ? bookingTimeline(request) : []
  }, [booking, request])
  const action = request ? primaryActionFor(request) : null
  const [withdrawing, setWithdrawing] = useState(false)
  const withdrawable = booking ? booking.canWithdraw : request ? canWithdraw(request.status, request.paymentStatus) : false
  const decided = request ? ["approved", "payment_required", "paid", "completed"].includes(request.status) : false

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

  const venueLine = booking ? [booking.venue, booking.venueAddress].filter(Boolean).join(" · ") : ""

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
            <Meta>{[request.eventDate, booking?.startTime, request.city].filter(Boolean).join(" · ")}</Meta>
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

          {booking ? (
            <View testID="booking-payment">
              <ListGroup title="Payment">
                {booking.payment ? (
                  <PaymentRows payment={booking.payment} />
                ) : (
                  <ListRow
                    icon="card-outline"
                    title={decided ? "No payment due" : "No payment yet"}
                    meta={decided ? undefined : "You only pay after the organizer approves you."}
                  />
                )}
              </ListGroup>
            </View>
          ) : null}

          {booking?.readiness ? (
            <View testID="booking-readiness">
              <ListGroup title={`Before the event · ${booking.readiness.percent}% ready`}>
                {booking.readiness.items.map((item) => (
                  <ListRow
                    key={item.key}
                    icon={item.done ? "checkmark-circle" : "ellipse-outline"}
                    iconTone={item.done ? "default" : "accent"}
                    title={item.label}
                    accessibilityLabel={`${item.label}, ${item.done ? "done" : "to do"}`}
                  />
                ))}
                <ListRow
                  icon="document-text-outline"
                  title="Manage documents"
                  showChevron
                  onPress={() => router.push("/(vendor)/documents" as Href)}
                />
              </ListGroup>
            </View>
          ) : null}

          <ListGroup title="Details">
            {request.organizerName ? <ListRow icon="business-outline" title="Organizer" meta={request.organizerName} /> : null}
            {booking?.booth ? <ListRow icon="grid-outline" title="Booth" meta={booking.booth} /> : null}
            {venueLine ? <ListRow icon="location-outline" title="Venue" meta={venueLine} /> : null}
            {request.category ? <ListRow icon="pricetag-outline" title="Vendor category" meta={request.category} /> : null}
            {!booking && request.amountDue != null && request.amountDue > 0 ? (
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

          {booking && (booking.answers.length > 0 || booking.rejectionReason) ? (
            <ListGroup title="Your application">
              {booking.answers.map((answer) => (
                <ListRow key={answer.id} icon="chatbox-ellipses-outline" title={answer.label} meta={answer.value} />
              ))}
              {booking.rejectionReason ? (
                <ListRow icon="chatbubble-outline" title="Organizer note" meta={booking.rejectionReason} />
              ) : null}
            </ListGroup>
          ) : null}

          {withdrawable ? (
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
