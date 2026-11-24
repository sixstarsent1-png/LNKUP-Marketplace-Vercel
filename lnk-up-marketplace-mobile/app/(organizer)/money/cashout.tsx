import { useCallback, useEffect, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, type Href } from "expo-router"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { SegmentedControl } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StickyActionBar } from "@/components/ui/sticky-action-bar"
import { Surface } from "@/components/ui/surface"
import { Meta } from "@/components/ui/typography"
import { newClientToken } from "@/lib/api/client-token"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchOrganizerMoney,
  formatMoney,
  formatMoneyOrZero,
  requestOrganizerCashout,
  type OrganizerFinancialSnapshot,
} from "@/lib/api/organizer-financial"
import { fetchPayoutPreview, type PayoutPreview } from "@/lib/api/organizer-mobile"
import { useAuth } from "@/lib/auth/auth-context"
import { useSingleFlight } from "@/lib/hooks/use-single-flight"
import { colors, fonts, spacing, typeScale, typography } from "@/lib/theme"

type Method = "standard" | "instant"
type Step = "amount" | "review" | "done"

const PROBLEM_COPY: Record<string, string> = {
  insufficient_balance: "That's more than you can cash out right now.",
  amount_below_fee: "That amount is smaller than the instant cash-out fee.",
  connect_not_ready: "Finish payout setup before cashing out.",
  requirements_past_due: "Your payout account needs more details before you can cash out.",
}

/** Cash out: method, amount, server fee review, confirm. Never shows "paid" — only requested. */
export default function OrganizerCashoutScreen() {
  const { auth } = useAuth()
  const [summary, setSummary] = useState<OrganizerFinancialSnapshot | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [method, setMethod] = useState<Method>("standard")
  const [amount, setAmount] = useState("")
  const [step, setStep] = useState<Step>("amount")
  const [preview, setPreview] = useState<PayoutPreview | null>(null)
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [result, setResult] = useState<{ payoutId?: string; message?: string } | null>(null)
  // One token per cash-out intent: a retried request returns the same payout instead of a second one.
  const tokenRef = useRef(newClientToken())

  const load = useCallback(async () => {
    setError(null)
    try {
      const data = await fetchOrganizerMoney(auth, { force: true })
      setSummary(data.summary)
      if (!data.summary.availableBalance && data.summary.canInstantCashout) setMethod("instant")
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth])

  useFocusEffect(
    useCallback(() => {
      if (step !== "done") void load()
    }, [load, step]),
  )

  useEffect(() => {
    setPreview(null)
    setPreviewError(null)
  }, [method, amount])

  const currency = summary?.currency ?? "USD"
  const showInstant = summary?.canInstantCashout !== undefined
  const limit = method === "instant" ? summary?.instantAvailable : summary?.availableBalance
  const value = Number(amount)
  const amountValid = Number.isFinite(value) && value > 0 && (limit == null || value <= limit)

  const [review, reviewing] = useSingleFlight(async () => {
    if (!auth || !amountValid) return
    setPreviewError(null)
    try {
      const next = await fetchPayoutPreview(value, method, auth)
      if (!next) {
        setPreviewError("Fee details aren't available right now. Try again in a moment.")
        return
      }
      setPreview(next)
      if (next.canSubmit) setStep("review")
      else setPreviewError(PROBLEM_COPY[next.problem] ?? "This cash out can't be sent right now.")
    } catch (err) {
      setPreviewError(appErrorFromWp(err).message)
    }
  })

  const [submit, submitting] = useSingleFlight(async () => {
    if (!auth || !preview?.canSubmit) return
    setSubmitError(null)
    try {
      // Revalidate right before sending: balances can change between review and confirm.
      const fresh = await fetchPayoutPreview(value, method, auth)
      if (!fresh?.canSubmit) {
        setPreview(fresh)
        setStep("amount")
        setPreviewError(PROBLEM_COPY[fresh?.problem ?? ""] ?? "Your balance changed. Review the amount again.")
        return
      }
      const res = await requestOrganizerCashout(auth, value, method, tokenRef.current)
      setResult(res)
      setStep("done")
      tokenRef.current = newClientToken()
    } catch (err) {
      setSubmitError(appErrorFromWp(err).message)
    }
  })

  return (
    <Screen
      title="Cash out"
      refreshing={false}
      onRefresh={step === "done" ? undefined : () => void load()}
      header={<BackLink label="Money" fallbackHref={"/(organizer)/money" as Href} />}
      footer={
        step === "amount" ? (
          <StickyActionBar insideTabs>
            <Button label="Review cash out" loadingLabel="Checking…" style={styles.flex} loading={reviewing} disabled={!amountValid} onPress={() => void review()} testID="cashout-review" />
          </StickyActionBar>
        ) : step === "review" ? (
          <StickyActionBar insideTabs>
            <Button label="Back" variant="secondary" disabled={submitting} onPress={() => setStep("amount")} />
            <Button
              label={`Cash out ${formatMoney(value, currency)}`}
              loadingLabel="Requesting…"
              style={styles.flex}
              loading={submitting}
              onPress={() => void submit()}
              testID="cashout-confirm"
            />
          </StickyActionBar>
        ) : (
          <StickyActionBar insideTabs>
            <Button label="Back to Money" style={styles.flex} onPress={() => router.replace("/(organizer)/money?segment=payouts" as Href)} testID="cashout-done" />
          </StickyActionBar>
        )
      }
    >
      {loading && !summary ? <LoadingPanel label="Checking your balance…" variant="skeleton" count={2} /> : null}
      {!loading && error && !summary ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {summary && step === "amount" ? (
        <View style={styles.stack}>
          {showInstant ? (
            <SegmentedControl
              options={[
                { value: "standard", label: "Standard" },
                { value: "instant", label: "Instant" },
              ]}
              value={method}
              onChange={setMethod}
            />
          ) : null}
          <Surface style={styles.card}>
            <Text style={styles.big}>{formatMoneyOrZero(limit, currency)}</Text>
            <Meta>{method === "instant" ? "Available for instant cash out now" : "Available to cash out now"}</Meta>
          </Surface>
          <FormField
            label="Amount"
            value={amount}
            onChangeText={(raw) => setAmount(raw.replace(/[^0-9.]/g, ""))}
            placeholder="0.00"
            keyboardType="decimal-pad"
            error={amount && !amountValid ? (limit != null && value > limit ? `You can cash out up to ${formatMoneyOrZero(limit, currency)}.` : "Enter an amount greater than zero.") : undefined}
            testID="cashout-amount"
          />
          {limit != null && limit > 0 ? (
            <Button label={`Cash out everything (${formatMoneyOrZero(limit, currency)})`} variant="ghost" size="sm" onPress={() => setAmount(String(limit))} />
          ) : null}
          {previewError ? <InlineBanner tone="error" message={previewError} /> : null}
        </View>
      ) : null}

      {preview && step === "review" ? (
        <View style={styles.stack} testID="cashout-preview">
          <ListGroup title="Review">
            <ListRow title="Amount" rightSlot={<Text style={styles.value}>{formatMoney(preview.amount, currency)}</Text>} />
            <ListRow
              title={preview.feeAmount > 0 ? "LNKup instant fee" : "LNKup fee"}
              meta={preview.feeAmount > 0 ? `${Number((preview.feeRate * 100).toFixed(2))}% of the amount` : undefined}
              rightSlot={<Text style={styles.value}>{formatMoney(preview.feeAmount, currency)}</Text>}
            />
            <ListRow
              title={preview.netIsEstimate ? "You'll receive about" : "You'll receive"}
              rightSlot={<Text style={styles.valueStrong}>{formatMoney(preview.estimatedNet, currency)}</Text>}
            />
            <ListRow icon="business-outline" title="Sent to" meta={preview.destinationSummary} />
            <ListRow icon="time-outline" title={preview.type === "instant" ? "Instant" : "Standard"} meta={preview.timingNote} />
          </ListGroup>
          {preview.providerFeeNote ? <Meta>{preview.providerFeeNote}</Meta> : null}
          {submitError ? <InlineBanner tone="error" message={submitError} /> : null}
        </View>
      ) : null}

      {step === "done" ? (
        <View style={styles.stack} testID="cashout-result">
          <InlineBanner tone="success" title="Cash out requested" message={result?.message ?? "We'll update its status as it moves to your bank."} />
          <Meta>Requested doesn't mean paid yet. You'll see it move to “On the way” and then “Paid” in Payouts.</Meta>
          {result?.payoutId ? (
            <Button label="View this cash out" variant="secondary" onPress={() => router.replace(`/(organizer)/money/payout?id=${result.payoutId}` as Href)} />
          ) : null}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.lg },
  card: { gap: spacing.xs },
  flex: { flex: 1 },
  big: { fontFamily: fonts.display, fontSize: typeScale.display, color: colors.black },
  value: { ...typography.label },
  valueStrong: { fontFamily: fonts.bodyBold, fontSize: typeScale.bodyLarge, color: colors.black },
})
