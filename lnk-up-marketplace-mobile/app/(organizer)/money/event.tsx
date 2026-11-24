import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { ApplicationRow } from "@/components/organizer/application-row"
import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { ListGroup } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchOrganizerEventMoney, formatMoneyOrZero, type OrganizerEventMoneySummary } from "@/lib/api/organizer-financial"
import { fetchOrganizerEventSummary, type OrganizerEventPresentation } from "@/lib/api/organizer-portfolio"
import { fetchOrganizerRequests, type OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { matchesFilter } from "@/components/organizer-vendors/vendor-queue"
import { useAuth } from "@/lib/auth/auth-context"
import { spacing } from "@/lib/theme"

/** One event's money: collected (before LNKup fees), open invoices and who still has to pay. */
export default function OrganizerEventFinancialsScreen() {
  const { eventId } = useLocalSearchParams<{ eventId: string }>()
  const id = String(eventId ?? "")
  const { auth } = useAuth()
  const [money, setMoney] = useState<OrganizerEventMoneySummary | null>(null)
  const [event, setEvent] = useState<OrganizerEventPresentation | null>(null)
  const [unpaid, setUnpaid] = useState<OrganizerVendorRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(async () => {
    if (!id) return
    setError(null)
    try {
      const [summary, m, requests] = await Promise.all([
        auth ? fetchOrganizerEventSummary(id, auth).catch(() => null) : Promise.resolve(null),
        fetchOrganizerEventMoney(id, undefined, auth),
        fetchOrganizerRequests(auth).catch(() => null),
      ])
      setEvent(summary)
      setMoney(m)
      setUnpaid((requests?.items ?? []).filter((r) => r.eventId === id && matchesFilter(r.status, "awaiting_payment")))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  const currency = money?.currency ?? "USD"

  return (
    <Screen
      title={event?.title || money?.eventName || "Event money"}
      subtitle="Booth fees before LNKup fees"
      refreshing={false}
      onRefresh={() => void load()}
      header={<BackLink label="Money" fallbackHref={"/(organizer)/money?segment=invoices" as Href} />}
    >
      {loading && !money ? <LoadingPanel label="Loading event money…" variant="skeleton" count={2} /> : null}
      {!loading && error && !money ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {money ? (
        <View style={styles.stack} testID="event-financials">
          <StatGrid>
            <StatTile icon="checkmark-circle-outline" label="Collected" hint="paid booth fees" value={formatMoneyOrZero(money.collected, currency)} tone="accent" />
            <StatTile
              icon="document-text-outline"
              label="Open invoices"
              hint={money.openInvoiceCount != null ? `${money.openInvoiceCount} unpaid` : "not yet paid"}
              value={formatMoneyOrZero(money.pending, currency)}
              tone={(money.pending ?? 0) > 0 ? "urgent" : "default"}
            />
            {(money.refunds ?? 0) > 0 ? <StatTile icon="return-down-back-outline" label="Refunded" value={formatMoneyOrZero(money.refunds, currency)} /> : null}
          </StatGrid>
          <Meta>
            {money.paidVendorCount ?? 0} vendor{(money.paidVendorCount ?? 0) === 1 ? "" : "s"} paid. Open invoices count only bookings vendors can still pay — refunded or cancelled ones are left out. Your earnings after LNKup fees are on Money.
          </Meta>

          <View style={styles.section}>
            <SectionHeader title="Still to pay" icon="hourglass-outline" subtitle={unpaid.length ? `${unpaid.length} approved vendor${unpaid.length === 1 ? "" : "s"}` : undefined} />
            {unpaid.length === 0 ? (
              <Meta>Every approved vendor has paid.</Meta>
            ) : (
              <>
                <ListGroup>
                  {unpaid.map((request) => (
                    <ApplicationRow key={request.id} request={request} onPress={() => router.push(`/(organizer)/vendors/review/${request.id}?filter=awaiting_payment` as Href)} />
                  ))}
                </ListGroup>
                <Button
                  label="Send a payment reminder"
                  icon="notifications-outline"
                  variant="secondary"
                  onPress={() => router.push(`/(organizer)/event-comms/${id}?group=approved_unpaid` as Href)}
                  testID="event-financials-remind"
                />
              </>
            )}
          </View>
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.lg },
  section: { gap: spacing.md },
})
