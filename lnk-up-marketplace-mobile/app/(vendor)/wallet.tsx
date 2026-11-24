import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { AppState, StyleSheet, View, type AppStateStatus } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"
import * as WebBrowser from "expo-web-browser"

import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { RequestCard } from "@/components/request-card"
import { Screen } from "@/components/screen"
import { WalletSummary } from "@/components/wallet-summary"
import { TransactionRow } from "@/components/wallet/transaction-row"
import { InlineBanner, type BannerTone } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { SegmentedControl } from "@/components/ui/chip"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { Eyebrow } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { groupTransactionsByDate, isMoneyMovement, formatLedgerDate, type VendorTransaction } from "@/lib/api/vendor-transactions"
import {
  fetchDocumentUrl,
  fetchVendorWallet,
  formatMoney,
  type VendorDispute,
  type VendorInvoiceOrReceipt,
  type VendorWalletSummary,
} from "@/lib/api/vendor-wallet"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { payBoothFee } from "@/lib/payments/stripe-checkout"
import { isPastEventInvoice, payCtaLabel } from "@/lib/vendor/booking-lifecycle"
import { colors, radii, spacing } from "@/lib/theme"

type Section = "due" | "history" | "documents"

const SUCCESS_PREFIXES = ["Payment received", "Dispute opened"]

function bannerTone(message: string): BannerTone {
  return SUCCESS_PREFIXES.some((prefix) => message.startsWith(prefix)) ? "success" : "attention"
}

const DOC_STATUS: Record<NonNullable<VendorInvoiceOrReceipt["status"]>, string> = {
  open: "Open",
  paid: "Paid",
  void: "Void",
  refunded: "Refunded",
  unknown: "Recorded",
}

function parseSection(value: string | string[] | undefined): Section {
  const raw = Array.isArray(value) ? value[0] : value
  if (raw === "history" || raw === "documents") return raw
  if (raw === "receipts") return "documents"
  return "due"
}

/**
 * Wallet: what's due (upcoming booth fees first, past-event invoices labelled as such), the money
 * ledger, and invoices and receipts. Amounts come from WordPress; nothing is recalculated here.
 */
export default function VendorWalletScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ tab?: string }>()
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [payingId, setPayingId] = useState<string | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [summary, setSummary] = useState<VendorWalletSummary | null>(null)
  const [dues, setDues] = useState<VendorRequest[]>([])
  const [transactions, setTransactions] = useState<VendorTransaction[]>([])
  const [documents, setDocuments] = useState<VendorInvoiceOrReceipt[]>([])
  const [disputes, setDisputes] = useState<VendorDispute[]>([])
  const [paymentBanner, setPaymentBanner] = useState<string | null>(null)
  const [section, setSection] = useState<Section>(() => parseSection(params.tab))
  const hasLoadedOnce = useRef(false)
  const awaitingPaymentReturn = useRef(false)
  const didFocusLoad = useRef(false)
  const payingRef = useRef(false)

  useEffect(() => {
    if (params.tab) setSection(parseSection(params.tab))
  }, [params.tab])

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode !== "initial" || hasLoadedOnce.current) setRefreshing(true)
      else setInitialLoad(true)
      setError(null)
      try {
        const bundle = await fetchVendorWallet(auth, { force: mode === "refresh" })
        setSummary(bundle.summary)
        setDues(bundle.paymentRequired)
        setTransactions(bundle.transactions)
        setDocuments(bundle.invoices)
        setDisputes(bundle.disputes ?? [])
        hasLoadedOnce.current = true
        if (awaitingPaymentReturn.current && bundle.paymentRequired.length === 0) {
          setPaymentBanner("Payment received. Your wallet is up to date.")
          awaitingPaymentReturn.current = false
        }
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setInitialLoad(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "focus" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  useEffect(() => {
    function onAppStateChange(next: AppStateStatus) {
      if (next === "active" && awaitingPaymentReturn.current) void load("refresh")
    }
    const sub = AppState.addEventListener("change", onAppStateChange)
    return () => sub.remove()
  }, [load])

  async function handlePay(request: VendorRequest) {
    if (payingRef.current) return
    if (!request.paymentId) {
      setPaymentBanner("This invoice isn't ready for payment yet. Open the booking and try again.")
      return
    }
    payingRef.current = true
    setPayingId(request.id)
    try {
      const result = await payBoothFee(request, auth)
      if (result.status === "cancelled") return
      if (result.status === "paid" || result.status === "browser") {
        // The booking only changes after WordPress confirms the payment.
        awaitingPaymentReturn.current = true
        setPaymentBanner(result.message || "Confirming your payment…")
        await load("refresh")
        return
      }
      setPaymentBanner(result.message || "Payment failed. Nothing was charged. Try again.")
    } catch (err) {
      setPaymentBanner(appErrorFromWp(err).message)
    } finally {
      payingRef.current = false
      setPayingId(null)
    }
  }

  async function openInvoice(doc: VendorInvoiceOrReceipt) {
    try {
      const url = await fetchDocumentUrl(doc, auth)
      if (!url) return
      await WebBrowser.openBrowserAsync(url)
    } catch (err) {
      showToast(`Couldn't open this invoice. ${appErrorFromWp(err).message}`, "error")
    }
  }

  const upcomingDues = useMemo(() => dues.filter((r) => !isPastEventInvoice(r)), [dues])
  const pastDues = useMemo(() => dues.filter((r) => isPastEventInvoice(r)), [dues])
  const history = useMemo(() => transactions.filter(isMoneyMovement), [transactions])
  const historyGroups = useMemo(() => groupTransactionsByDate(history), [history])
  const showFullLoader = initialLoad && !summary

  function renderDue(item: VendorRequest) {
    const isPaying = payingId === item.id
    const label = isPastEventInvoice(item) ? `Pay invoice${item.amountDue ? ` · ${formatMoney(item.amountDue, item.currency)}` : ""}` : payCtaLabel(item)
    return (
      <View key={item.id} style={styles.dueBlock}>
        <RequestCard item={item} onPress={() => router.push(`/booking/${item.id}` as Href)} />
        <Button
          label={label}
          loadingLabel="Opening checkout…"
          icon="card-outline"
          loading={isPaying}
          disabled={!item.paymentId || payingId != null}
          onPress={() => void handlePay(item)}
          accessibilityLabel={`${label} for ${item.eventName}`}
        />
      </View>
    )
  }

  return (
    <Screen title="Wallet" refreshing={refreshing} onRefresh={() => void load("refresh")}>
      {showFullLoader ? (
        <View style={styles.section} accessibilityLabel="Loading wallet">
          <SkeletonStats count={1} />
          <SkeletonList count={2} />
        </View>
      ) : null}

      {!showFullLoader && error && !summary ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} /> : null}

      {!showFullLoader && summary ? (
        <>
          {paymentBanner ? <InlineBanner tone={bannerTone(paymentBanner)} message={paymentBanner} onDismiss={() => setPaymentBanner(null)} /> : null}
          {error ? <InlineBanner tone="error" message={`Couldn't refresh. ${error.message}`} actionLabel="Retry" onAction={() => void load("refresh")} /> : null}

          <WalletSummary summary={summary} />

          <SegmentedControl
            options={[
              { value: "due", label: "Due", count: dues.length },
              { value: "history", label: "History", count: history.length },
              { value: "documents", label: "Documents", count: documents.length },
            ]}
            value={section}
            onChange={setSection}
          />

          {section === "due" ? (
            <View style={styles.section}>
              {dues.length === 0 ? (
                <EmptyPanel icon="checkmark-done-outline" title="Nothing due" message="When an organizer approves you, the booth fee to confirm your spot appears here." />
              ) : null}
              {upcomingDues.length > 0 ? (
                <View style={styles.list}>
                  <SectionHeader title="Confirm your booths" icon="card-outline" subtitle="Your spot is confirmed after the payment clears." />
                  {upcomingDues.map(renderDue)}
                </View>
              ) : null}
              {pastDues.length > 0 ? (
                <View style={styles.list}>
                  <SectionHeader
                    title="Outstanding invoices · past events"
                    icon="receipt-outline"
                    subtitle="These events already happened. The organizer's invoices are still open."
                  />
                  {pastDues.map(renderDue)}
                </View>
              ) : null}
            </View>
          ) : null}

          {section === "history" ? (
            <View style={styles.section}>
              {history.length === 0 ? (
                <EmptyPanel icon="receipt-outline" title="No payments yet" message="Booth fees you pay through LNKup, and any refunds, are listed here." />
              ) : (
                historyGroups.map((group) => (
                  <View key={group.title} style={styles.list}>
                    <Eyebrow>{group.title}</Eyebrow>
                    <View style={styles.ledger}>
                      {group.items.map((t, index) => (
                        <View key={t.id} style={index > 0 ? styles.divider : undefined}>
                          <TransactionRow item={t} onPress={() => router.push(`/wallet/transaction/${t.id}` as Href)} />
                        </View>
                      ))}
                    </View>
                  </View>
                ))
              )}
              {disputes.length > 0 ? (
                <ListGroup title="Your disputes">
                  {disputes.map((d) => (
                    <ListRow
                      key={d.id}
                      icon="flag-outline"
                      title={d.eventName ?? `Payment #${d.paymentId ?? d.id}`}
                      meta={[d.status.replace(/_/g, " "), formatMoney(d.amount, d.currency), "Manage on LNKup"].filter(Boolean).join(" · ")}
                      onPress={d.wordpressUrl ? () => void WebBrowser.openBrowserAsync(d.wordpressUrl!) : undefined}
                    />
                  ))}
                </ListGroup>
              ) : null}
            </View>
          ) : null}

          {section === "documents" ? (
            <View style={styles.section}>
              {documents.length === 0 ? (
                <EmptyPanel icon="document-text-outline" title="No invoices or receipts yet" message="Receipts appear here after each booth fee payment." />
              ) : (
                <ListGroup>
                  {documents.map((doc) => {
                    const typeLabel = doc.type === "receipt" ? "Receipt" : "Invoice"
                    const date = formatLedgerDate(doc.type === "receipt" ? doc.issuedAt : doc.dueAt ?? doc.issuedAt)
                    return (
                      <ListRow
                        key={`${doc.type}-${doc.id}`}
                        icon={doc.type === "receipt" ? "receipt-outline" : "document-text-outline"}
                        title={doc.eventName || `${typeLabel} ${doc.number ?? ""}`.trim()}
                        meta={[typeLabel, date, formatMoney(doc.amount, doc.currency), DOC_STATUS[doc.status ?? "unknown"]].filter(Boolean).join(" · ")}
                        onPress={
                          doc.type === "receipt" && /^\d+$/.test(doc.id)
                            ? () => router.push(`/wallet/receipt/${doc.id}` as Href)
                            : doc.pdfUrl || doc.wordpressUrl
                              ? () => void openInvoice(doc)
                              : undefined
                        }
                        accessibilityHint={doc.type === "receipt" ? "Opens the receipt" : "Opens the invoice"}
                      />
                    )
                  })}
                </ListGroup>
              )}
            </View>
          ) : null}
        </>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  section: { gap: spacing.lg },
  list: { gap: spacing.md },
  dueBlock: { gap: spacing.sm },
  ledger: {
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    backgroundColor: colors.white,
    overflow: "hidden",
  },
  divider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.gray200 },
})
