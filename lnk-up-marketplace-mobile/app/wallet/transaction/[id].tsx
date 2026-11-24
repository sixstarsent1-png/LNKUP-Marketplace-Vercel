import { useCallback, useEffect, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Surface } from "@/components/ui/surface"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { formatMoney } from "@/lib/api/format-money"
import { fetchTransactionDetail, formatLedgerDate, type VendorTransactionDetail } from "@/lib/api/vendor-transactions"
import { openVendorDispute } from "@/lib/api/vendor-wallet"
import { useAuth } from "@/lib/auth/auth-context"
import { promptText, showToast } from "@/lib/feedback/feedback"
import { colors, fonts, spacing, typeScale } from "@/lib/theme"

const STATUS_LABEL: Record<VendorTransactionDetail["status"], string> = {
  paid: "Paid",
  refunded: "Refunded",
  failed: "Failed · nothing was charged",
  pending: "Pending",
  removed: "No longer due",
  other: "Recorded",
}

/** One ledger transaction: what was paid, for which event, the recorded line items and the receipt. */
export default function TransactionDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { auth, isAuthenticated } = useAuth()
  const [txn, setTxn] = useState<VendorTransactionDetail | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)
  const disputing = useRef(false)

  const load = useCallback(async () => {
    if (!auth || !id) return
    setLoading(true)
    setError(null)
    try {
      setTxn(await fetchTransactionDetail(String(id), auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
  }, [load])

  async function handleDispute() {
    if (!txn?.paymentId || disputing.current) return
    disputing.current = true
    try {
      const reason = await promptText({
        title: "Dispute this charge",
        message: `Tell us what's wrong${txn.eventTitle ? ` with the ${txn.eventTitle} charge` : ""}. The organizer and LNKup will review it.`,
        placeholder: "e.g. I was charged twice for the same booth",
        confirmLabel: "Open dispute",
        minLength: 10,
      })
      if (!reason) return
      await openVendorDispute(txn.paymentId, reason, auth ?? undefined)
      showToast("Dispute opened. LNKup will follow up by email.", "success")
    } catch (err) {
      showToast(`Couldn't open the dispute. ${appErrorFromWp(err).message}`, "error")
    } finally {
      disputing.current = false
    }
  }

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/wallet/transaction/${id}` } }} />
  }

  const date = formatLedgerDate(txn?.occurredAt)
  const itemsTotal = txn?.lineItems.reduce((sum, item) => sum + item.amount, 0) ?? 0

  return (
    <Screen title="Payment" hideTitle header={<BackLink label="Wallet" fallbackHref={"/(vendor)/wallet?tab=history" as Href} />}>
      {loading ? <LoadingPanel label="Loading payment…" variant="skeleton" count={2} /> : null}
      {!loading && error ? (
        error.code === "not_found" ? (
          <EmptyPanel icon="receipt-outline" title="Payment not found" message="It may belong to another account." />
        ) : (
          <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
        )
      ) : null}
      {!loading && txn ? (
        <View style={styles.wrap} testID="transaction-detail">
          <Surface style={styles.hero}>
            <Meta>{txn.typeLabel}</Meta>
            <Text style={styles.amount}>{formatMoney(txn.amount, txn.currency)}</Text>
            <Text style={styles.status}>{STATUS_LABEL[txn.status]}</Text>
            {date ? <Meta>{date}</Meta> : null}
          </Surface>

          <ListGroup title="For">
            <ListRow icon="calendar-outline" title={txn.eventTitle || "Event no longer listed"} meta={[formatLedgerDate(txn.eventDateIso), txn.place].filter(Boolean).join(" · ") || undefined} />
            {txn.organizerName ? <ListRow icon="business-outline" title="Organizer" meta={txn.organizerName} /> : null}
            {txn.applicationId ? (
              <ListRow icon="document-text-outline" title="View booking" onPress={() => router.push(`/booking/${txn.applicationId}` as Href)} />
            ) : null}
          </ListGroup>

          <ListGroup title="Charges">
            {txn.lineItems.length > 0 ? (
              txn.lineItems.map((item, index) => <ListRow key={`${item.description}-${index}`} title={item.description} rightSlot={<Text style={styles.line}>{formatMoney(item.amount, txn.currency)}</Text>} />)
            ) : (
              <ListRow title="Recorded payment total" meta="Itemized fees were not stored for this payment." rightSlot={<Text style={styles.line}>{formatMoney(txn.amount, txn.currency)}</Text>} />
            )}
            {txn.lineItems.length > 0 && Math.abs(itemsTotal - txn.amount) > 0.009 ? (
              <ListRow title="Total recorded" rightSlot={<Text style={styles.line}>{formatMoney(txn.amount, txn.currency)}</Text>} />
            ) : null}
          </ListGroup>

          <ListGroup title="Details">
            {txn.invoiceNumber ? <ListRow icon="document-outline" title="Invoice" meta={txn.invoiceNumber} /> : null}
            {txn.publicId ? <ListRow icon="key-outline" title="Reference" meta={txn.publicId} /> : null}
            {txn.receipt ? (
              <ListRow icon="receipt-outline" title="Receipt" meta={txn.receipt.number} onPress={() => router.push(`/wallet/receipt/${txn.receipt!.id}` as Href)} />
            ) : null}
          </ListGroup>

          <Meta style={styles.note}>Amounts are what was recorded when the payment happened. They are not recalculated with today's fees.</Meta>

          {txn.status === "paid" && txn.paymentId ? (
            <Button label="Dispute this charge" icon="flag-outline" variant="ghost" onPress={() => void handleDispute()} />
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.lg },
  hero: { alignItems: "center", gap: 2, paddingVertical: spacing.xl },
  amount: { fontFamily: fonts.display, fontSize: typeScale.large, color: colors.ink },
  status: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.gray700 },
  line: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  note: { color: colors.gray500 },
})
