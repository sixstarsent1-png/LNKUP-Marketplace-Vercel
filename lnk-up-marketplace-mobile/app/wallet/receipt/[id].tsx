import { useCallback, useEffect, useState } from "react"
import { Share, StyleSheet, View } from "react-native"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"
import * as WebBrowser from "expo-web-browser"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { formatMoney } from "@/lib/api/format-money"
import { fetchReceiptDetail, formatLedgerDate, type VendorReceiptDetail } from "@/lib/api/vendor-transactions"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

const PAYMENT_LABEL: Record<string, string> = {
  paid: "Paid",
  refunded: "Refunded",
  partially_refunded: "Partially refunded",
  disputed: "Disputed",
  chargeback_lost: "Charge reversed",
}

const METHOD_LABEL: Record<string, string> = {
  stripe: "Card, processed by Stripe",
  manual: "Recorded by LNKup",
  free: "No payment required",
}

/**
 * A receipt as recorded when the payment completed. The full document (and PDF, when the server
 * can render one) opens from a short-lived signed link, never a guessable public URL.
 */
export default function ReceiptDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>()
  const { auth, isAuthenticated } = useAuth()
  const [receipt, setReceipt] = useState<VendorReceiptDetail | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)
  const [opening, setOpening] = useState(false)

  const load = useCallback(async () => {
    if (!auth || !id) return
    setLoading(true)
    setError(null)
    try {
      setReceipt(await fetchReceiptDetail(String(id), auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
  }, [load])

  /** Refetch so the signed link is fresh, then open or share it. */
  async function withFreshLink(action: (url: string) => Promise<unknown>) {
    if (!auth || opening) return
    setOpening(true)
    try {
      const fresh = await fetchReceiptDetail(String(id), auth)
      if (!fresh.viewUrl) {
        showToast("This receipt can't be opened right now.", "error")
        return
      }
      await action(fresh.viewUrl)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setOpening(false)
    }
  }

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/wallet/receipt/${id}` } }} />
  }

  return (
    <Screen title="Receipt" hideTitle header={<BackLink label="Wallet" fallbackHref={"/(vendor)/wallet?tab=documents" as Href} />}>
      {loading ? <LoadingPanel label="Loading receipt…" variant="skeleton" count={2} /> : null}
      {!loading && error ? (
        error.code === "not_found" ? (
          <EmptyPanel icon="receipt-outline" title="Receipt not found" message="It may belong to another account." />
        ) : (
          <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
        )
      ) : null}
      {!loading && receipt ? (
        <View style={styles.wrap} testID="receipt-detail">
          <View style={styles.sheet}>
            <View style={styles.bar}>
              <Text style={styles.brand}>
                LNK<Text style={styles.brandAccent}>up</Text>
              </Text>
              <Text style={styles.paid}>{PAYMENT_LABEL[receipt.paymentStatus] ?? "Recorded"}</Text>
            </View>
            <View style={styles.body}>
              <Meta>Payment receipt</Meta>
              <Text style={styles.title}>{receipt.eventTitle || "Payment receipt"}</Text>
              {receipt.eventDateIso || receipt.place ? <Meta>{[formatLedgerDate(receipt.eventDateIso), receipt.place].filter(Boolean).join(" · ")}</Meta> : null}
              <Text style={styles.total}>{formatMoney(receipt.amount, receipt.currency)}</Text>
              <Meta>Total paid ({receipt.currency})</Meta>
            </View>
          </View>

          <ListGroup title="Details">
            <ListRow title="Receipt number" meta={receipt.number} />
            {receipt.issuedAt ? <ListRow title="Date paid" meta={formatLedgerDate(receipt.issuedAt)} /> : null}
            <ListRow title="Paid to" meta={receipt.organizerName ? `${receipt.organizerName} (collected through LNKup)` : "LNKup"} />
            {receipt.paymentMethod ? <ListRow title="Payment method" meta={METHOD_LABEL[receipt.paymentMethod] ?? receipt.paymentMethod} /> : null}
          </ListGroup>

          <Meta style={styles.note}>This receipt shows the amount recorded when the payment was completed. It is not recalculated with today's fees.</Meta>

          <View style={styles.actions}>
            <Button
              label="View full receipt"
              icon="open-outline"
              loading={opening}
              loadingLabel="Opening…"
              style={styles.flex}
              onPress={() => void withFreshLink((url) => WebBrowser.openBrowserAsync(url))}
            />
            <Button
              label="Share"
              icon="share-outline"
              variant="secondary"
              onPress={() => void withFreshLink((url) => Share.share({ message: `Receipt ${receipt.number}: ${url}`, url }))}
            />
          </View>
          {receipt.pdfAvailable ? <Meta style={styles.note}>Download or print the PDF from the full receipt.</Meta> : null}
          {receipt.applicationId ? (
            <Button label="View booking" variant="ghost" onPress={() => router.push(`/booking/${receipt.applicationId}` as Href)} />
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.lg },
  sheet: { borderRadius: radii.lg, overflow: "hidden", borderWidth: 1, borderColor: colors.line, backgroundColor: colors.white },
  bar: { flexDirection: "row", justifyContent: "space-between", alignItems: "center", backgroundColor: colors.shell, paddingHorizontal: spacing.lg, paddingVertical: spacing.md },
  brand: { fontFamily: fonts.display, fontSize: typeScale.headline, color: colors.white },
  brandAccent: { color: colors.lime },
  paid: { fontFamily: fonts.bodyBold, fontSize: typeScale.caption, color: colors.black, backgroundColor: colors.lime, borderRadius: radii.pill, overflow: "hidden", paddingHorizontal: spacing.md, paddingVertical: 4 },
  body: { padding: spacing.lg, gap: 4 },
  title: { fontFamily: fonts.displaySemi, fontSize: typeScale.title, lineHeight: 26, color: colors.ink },
  total: { fontFamily: fonts.display, fontSize: typeScale.large, color: colors.ink, marginTop: spacing.md },
  note: { color: colors.gray500 },
  actions: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
})
