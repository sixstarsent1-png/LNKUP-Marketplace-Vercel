import { useCallback, useEffect, useRef, useState } from "react"
import {
  AppState,
  StyleSheet,
  View,
  type AppStateStatus,
} from "react-native"
import { router, useFocusEffect, type Href } from "expo-router"
import * as WebBrowser from "expo-web-browser"

import { ActivityRow } from "@/components/activity-row"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { RequestCard } from "@/components/request-card"
import { Screen } from "@/components/screen"
import { WalletSummary } from "@/components/wallet-summary"
import { InlineBanner, type BannerTone } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { SegmentedControl } from "@/components/ui/chip"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import {
  fetchDocumentUrl,
  fetchVendorWallet,
  formatMoney,
  openVendorDispute,
  type VendorDispute,
  type VendorFinancialActivityItem,
  type VendorInvoiceOrReceipt,
  type VendorWalletSummary,
} from "@/lib/api/vendor-wallet"
import { useAuth } from "@/lib/auth/auth-context"
import { promptText, showToast } from "@/lib/feedback/feedback"
import { payBoothFee } from "@/lib/payments/stripe-checkout"
import { spacing } from "@/lib/theme"

function payLabel(item: VendorRequest): string {
  if (item.amountDue != null && item.amountDue > 0) {
    return `Pay ${formatMoney(item.amountDue, item.currency ?? "USD")}`
  }
  return "Pay now"
}

const SUCCESS_PREFIXES = ["Payment received", "Dispute opened"]

function bannerTone(message: string): BannerTone {
  return SUCCESS_PREFIXES.some((prefix) => message.startsWith(prefix)) ? "success" : "attention"
}

export default function VendorWalletScreen() {
  const { auth } = useAuth()
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [payingId, setPayingId] = useState<string | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [summary, setSummary] = useState<VendorWalletSummary | null>(null)
  const [dues, setDues] = useState<VendorRequest[]>([])
  const [activity, setActivity] = useState<VendorFinancialActivityItem[]>([])
  const [documents, setDocuments] = useState<VendorInvoiceOrReceipt[]>([])
  const [disputes, setDisputes] = useState<VendorDispute[]>([])
  const [paymentBanner, setPaymentBanner] = useState<string | null>(null)
  const [section, setSection] = useState<"due" | "history" | "receipts">("due")
  const hasLoadedOnce = useRef(false)
  const awaitingPaymentReturn = useRef(false)
  const didFocusLoad = useRef(false)
  const payingRef = useRef(false)
  const disputingRef = useRef(false)

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode !== "initial" || hasLoadedOnce.current) setRefreshing(true)
      else setInitialLoad(true)
      setError(null)
      try {
        const bundle = await fetchVendorWallet(auth, { force: mode === "refresh" })
        setSummary(bundle.summary)
        setDues(bundle.paymentRequired)
        setActivity(bundle.activity)
        setDocuments(bundle.invoices)
        setDisputes(bundle.disputes ?? [])
        hasLoadedOnce.current = true
        if (awaitingPaymentReturn.current && bundle.paymentRequired.length === 0) {
          setPaymentBanner("Payment received — your wallet is up to date")
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
      if (next === "active" && awaitingPaymentReturn.current) {
        void load("refresh")
      }
    }
    const sub = AppState.addEventListener("change", onAppStateChange)
    return () => sub.remove()
  }, [load])

  async function handlePay(request: VendorRequest) {
    if (payingRef.current) return
    if (!request.paymentId) {
      setPaymentBanner("Payment isn’t ready yet. Open the event pass and try again.")
      return
    }
    payingRef.current = true
    setPayingId(request.id)
    try {
      const result = await payBoothFee(request, auth)
      if (result.status === "cancelled") {
        return
      }
      if (result.status === "paid" || result.status === "browser") {
        awaitingPaymentReturn.current = true
        setPaymentBanner(result.message || "Confirming your payment…")
        await load("refresh")
        return
      }
      setPaymentBanner(result.message || "Payment failed. Try again.")
    } catch (err) {
      const shaped = appErrorFromWp(err)
      setPaymentBanner(shaped.message)
    } finally {
      payingRef.current = false
      setPayingId(null)
    }
  }

  async function openDocument(doc: VendorInvoiceOrReceipt) {
    try {
      const url = await fetchDocumentUrl(doc, auth)
      if (!url) return
      await WebBrowser.openBrowserAsync(url)
    } catch (err) {
      showToast(`Couldn't open this ${doc.type}. ${appErrorFromWp(err).message}`, "error")
    }
  }

  async function promptDispute(paymentId: string, eventName?: string) {
    if (disputingRef.current) return
    disputingRef.current = true
    try {
      const reason = await promptText({
        title: "Dispute this charge",
        message: `Tell us what's wrong${eventName ? ` with the ${eventName} charge` : ""}. The organizer and LNKup will review it — you can add evidence later on the web.`,
        placeholder: "e.g. I was charged twice for the same booth",
        confirmLabel: "Open dispute",
        minLength: 10,
      })
      if (!reason) return
      await openVendorDispute(paymentId, reason, auth ?? undefined)
      setPaymentBanner("Dispute opened. Manage evidence in LNKup Payments.")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't open the dispute. ${appErrorFromWp(err).message}`, "error")
    } finally {
      disputingRef.current = false
    }
  }

  const showFullLoader = initialLoad && !summary

  return (
    <Screen
      title="Wallet"
      subtitle="Booth fees & receipts"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      {showFullLoader ? (
        <View style={styles.section} accessibilityLabel="Loading wallet">
          <SkeletonStats count={3} />
          <SkeletonList count={2} />
        </View>
      ) : null}

      {!showFullLoader && error && !summary ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {!showFullLoader && summary ? (
        <>
          {paymentBanner ? (
            <InlineBanner
              tone={bannerTone(paymentBanner)}
              message={paymentBanner}
              onDismiss={() => setPaymentBanner(null)}
            />
          ) : null}

          {error ? (
            <InlineBanner
              tone="error"
              message={`Couldn't refresh. ${error.message}`}
              actionLabel="Retry"
              onAction={() => void load("refresh")}
            />
          ) : null}

          <WalletSummary summary={summary} />

          <SegmentedControl
            options={[
              { value: "due", label: "Due", count: dues.length },
              { value: "history", label: "History", count: activity.length + disputes.length },
              { value: "receipts", label: "Receipts", count: documents.length },
            ]}
            value={section}
            onChange={setSection}
          />

          {section === "due" ? (
          <View style={styles.section}>
            <SectionHeader
              title="Amount due"
              icon="card-outline"
              subtitle={dues.length > 0 ? "Pay to unlock your event pass" : undefined}
            />
            {dues.length === 0 ? (
              <EmptyPanel
                icon="checkmark-done-outline"
                title="Nothing due"
                message="When you’re accepted, booth fees to unlock your pass appear here."
              />
            ) : (
              <View style={styles.list}>
                {dues.map((item) => {
                  const canPay = Boolean(item.paymentId)
                  const isPaying = payingId === item.id
                  return (
                    <View key={item.id} style={styles.dueBlock}>
                      <RequestCard
                        item={item}
                        onPress={() => router.push(`/pass/${item.id}` as Href)}
                      />
                      <View style={styles.dueActions}>
                        <Button
                          label={payLabel(item)}
                          loadingLabel="Paying…"
                          icon="card-outline"
                          loading={isPaying}
                          disabled={!canPay || payingId != null}
                          onPress={() => void handlePay(item)}
                          accessibilityLabel={`${payLabel(item)} for ${item.eventName}`}
                          style={styles.payButton}
                        />
                        <Button
                          label="View pass"
                          variant="secondary"
                          onPress={() => router.push(`/pass/${item.id}` as Href)}
                          accessibilityLabel={`View pass for ${item.eventName}`}
                        />
                      </View>
                    </View>
                  )
                })}
              </View>
            )}
          </View>
          ) : null}

          {section === "history" ? (
          <View style={styles.section}>
            <SectionHeader title="Payments" icon="receipt-outline" />
            {activity.length === 0 ? (
              <EmptyPanel
                icon="receipt-outline"
                title="No payments yet"
                message="Booth fees you pay through LNKup will be listed here."
              />
            ) : (
              <View style={styles.list}>
                {activity.map((item) => (
                  <View key={item.id} style={styles.activityBlock}>
                    <ActivityRow
                      item={item}
                      onPress={
                        item.requestId
                          ? () => router.push(`/pass/${item.requestId}` as Href)
                          : undefined
                      }
                    />
                    {item.status === "paid" && item.paymentId ? (
                      <Button
                        label="Dispute charge"
                        icon="flag-outline"
                        variant="ghost"
                        size="sm"
                        style={styles.disputeLink}
                        onPress={() => void promptDispute(item.paymentId!, item.eventName)}
                        accessibilityLabel={`Dispute charge${item.eventName ? ` for ${item.eventName}` : ""}`}
                      />
                    ) : null}
                  </View>
                ))}
              </View>
            )}
          </View>

          ) : null}

          {section === "history" && disputes.length > 0 ? (
            <View style={styles.section}>
              <SectionHeader title="Your disputes" icon="flag-outline" />
              <ListGroup>
                {disputes.map((d) => (
                  <ListRow
                    key={d.id}
                    icon="flag-outline"
                    title={d.eventName ?? `Payment #${d.paymentId ?? d.id}`}
                    meta={[d.status.replace(/_/g, " "), formatMoney(d.amount, d.currency), "Manage on LNKup"]
                      .filter(Boolean)
                      .join(" · ")}
                    onPress={
                      d.wordpressUrl
                        ? () => void WebBrowser.openBrowserAsync(d.wordpressUrl!)
                        : undefined
                    }
                  />
                ))}
              </ListGroup>
            </View>
          ) : null}

          {section === "receipts" ? (
          <View style={styles.section}>
            <SectionHeader title="Invoices & receipts" icon="document-text-outline" />
            {documents.length === 0 ? (
              <EmptyPanel
                icon="document-text-outline"
                title="No invoices or receipts yet"
                message="Receipts appear here after each booth fee payment."
              />
            ) : (
              <ListGroup>
                {documents.map((doc) => {
                  const canOpen = Boolean(doc.pdfUrl || doc.wordpressUrl)
                  const typeLabel = doc.type === "receipt" ? "Receipt" : "Invoice"
                  return (
                    <ListRow
                      key={`${doc.type}-${doc.id}`}
                      icon={doc.type === "receipt" ? "receipt-outline" : "document-text-outline"}
                      title={`${doc.number ?? doc.id} · ${doc.eventName ?? typeLabel}`}
                      meta={[typeLabel, doc.status, formatMoney(doc.amount, doc.currency)]
                        .filter(Boolean)
                        .join(" · ")}
                      onPress={canOpen ? () => void openDocument(doc) : undefined}
                      accessibilityHint={canOpen ? "Opens the document" : undefined}
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
  section: {
    gap: spacing.md,
  },
  list: {
    gap: spacing.md,
  },
  dueBlock: {
    gap: spacing.sm,
  },
  dueActions: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  payButton: {
    flex: 1,
  },
  activityBlock: {
    gap: spacing.xs,
  },
  disputeLink: {
    alignSelf: "flex-start",
  },
})
