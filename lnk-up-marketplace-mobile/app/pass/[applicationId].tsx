import { useCallback, useEffect, useRef, useState } from "react"
import {
  AppState,
  Image,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleSheet,
  View,
  type AppStateStatus,
} from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"
import QRCode from "react-native-qrcode-svg"
import { SafeAreaView } from "react-native-safe-area-context"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { BackLink } from "@/components/ui/back-link"
import { Button } from "@/components/ui/button"
import { SectionTitle } from "@/components/ui/typography"
import { PassBulletList, PassDetailRow, PassSectionCard } from "@/components/pass-section-card"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorPass, type VendorEventPass } from "@/lib/api/vendor-pass"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { useAuth } from "@/lib/auth/auth-context"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { payBoothFee } from "@/lib/payments/stripe-checkout"
import { colors, layout, radii, spacing, typeScale } from "@/lib/theme"

const CONFIRMING_COPY = "Confirming your payment…"
const CONFIRM_RETRY_MS = 3000
const CONFIRM_MAX_ATTEMPTS = 5

function eventMeta(pass: VendorEventPass): string {
  return [pass.eventDate, pass.city, pass.state].filter(Boolean).join(" · ")
}

function extractPhone(raw?: string): string | undefined {
  if (!raw) return undefined
  const match = raw.match(/(\+?[\d][\d\s().-]{6,}\d)/)
  if (!match) return undefined
  const digits = match[1].replace(/[^\d+]/g, "")
  return digits.length >= 7 ? digits : undefined
}

export default function EventPassScreen() {
  const { applicationId, payment, from } = useLocalSearchParams<{
    applicationId: string
    payment?: string
    from?: string
  }>()
  const { auth, isAuthenticated } = useAuth()
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [pass, setPass] = useState<VendorEventPass | null>(null)
  const [paying, setPaying] = useState(false)
  const [showStickyCheckIn, setShowStickyCheckIn] = useState(false)
  const [paymentBanner, setPaymentBanner] = useState<string | null>(
    payment === "success" ? CONFIRMING_COPY : null,
  )
  const hasLoadedOnce = useRef(false)
  const awaitingPaymentReturn = useRef(payment === "success")
  const confirmAttempts = useRef(0)
  const confirmTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const payingRef = useRef(false)
  const heroHeightRef = useRef(280)
  const id = String(applicationId ?? "")
  const fromOps = String(from ?? "") === "operations"
  const backFallback: Href = fromOps ? "/(vendor)/operations" : "/(vendor)/requests"
  const loadRef = useRef<(mode?: "initial" | "refresh") => Promise<void>>(async () => {})

  /** Re-check the server a few times while the payment webhook lands. */
  const scheduleConfirmRetry = useCallback(() => {
    if (confirmTimer.current) return
    if (confirmAttempts.current >= CONFIRM_MAX_ATTEMPTS) {
      awaitingPaymentReturn.current = false
      confirmAttempts.current = 0
      setPaymentBanner(
        "We haven’t received confirmation yet. Pull to refresh in a minute — you won’t be charged twice.",
      )
      return
    }
    confirmAttempts.current += 1
    confirmTimer.current = setTimeout(() => {
      confirmTimer.current = null
      void loadRef.current("refresh")
    }, CONFIRM_RETRY_MS)
  }, [])

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (!id) return
      if (mode === "refresh" || hasLoadedOnce.current) setRefreshing(true)
      else setInitialLoad(true)
      setError(null)
      try {
        const data = await fetchVendorPass(id, auth)
        setPass(data)
        hasLoadedOnce.current = true
        if (data.status === "unlocked" && awaitingPaymentReturn.current) {
          setPaymentBanner("Payment received — your pass is ready")
          awaitingPaymentReturn.current = false
          confirmAttempts.current = 0
        } else if (awaitingPaymentReturn.current) {
          scheduleConfirmRetry()
        }
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setInitialLoad(false)
        setRefreshing(false)
      }
    },
    [auth, id, scheduleConfirmRetry],
  )
  loadRef.current = load

  useEffect(() => {
    return () => {
      if (confirmTimer.current) clearTimeout(confirmTimer.current)
    }
  }, [])

  useEffect(() => {
    void load("initial")
  }, [load])

  useEffect(() => {
    if (payment === "success") {
      awaitingPaymentReturn.current = true
      setPaymentBanner(CONFIRMING_COPY)
    } else if (payment === "cancelled") {
      setPaymentBanner("Payment cancelled — you can try again when ready.")
    }
  }, [payment])

  useEffect(() => {
    function onAppStateChange(next: AppStateStatus) {
      if (next === "active" && awaitingPaymentReturn.current) {
        void load("refresh")
      }
    }
    const sub = AppState.addEventListener("change", onAppStateChange)
    return () => sub.remove()
  }, [load])

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/pass/${id}` } }} />
  }

  async function handlePay() {
    if (!pass || payingRef.current) return
    if (!pass.paymentId) {
      setPaymentBanner("Payment isn’t ready yet. Pull to refresh and try again.")
      return
    }
    payingRef.current = true
    setPaying(true)
    try {
      const stubRequest: VendorRequest = {
        id: pass.applicationId,
        eventName: pass.eventName,
        status: "payment_required",
        paymentId: pass.paymentId,
      }
      const result = await payBoothFee(stubRequest, auth)
      if (result.status === "cancelled") {
        return
      }
      if (result.status === "paid" || result.status === "browser") {
        awaitingPaymentReturn.current = true
        confirmAttempts.current = 0
        setPaymentBanner(result.message || CONFIRMING_COPY)
        await load("refresh")
        return
      }
      setPaymentBanner(result.message || "Payment failed. Try again.")
    } catch (err) {
      const shaped = appErrorFromWp(err)
      setPaymentBanner(shaped.message)
    } finally {
      payingRef.current = false
      setPaying(false)
    }
  }

  const showFullLoader = initialLoad && !pass

  const checkInLabel = pass?.checkInCode || (pass?.qrCodeUrl ? "QR ready" : null)
  const stickyVisible = Boolean(pass && pass.status === "unlocked" && checkInLabel && showStickyCheckIn)

  return (
    <SafeAreaView style={styles.safe} edges={["top"]}>
      <View style={[styles.header, constrainedColumnStyle(layout.contentMaxWidth)]}>
        <BackLink label="Back" fallbackHref={backFallback} />
        <SectionTitle accessibilityRole="header">Event pass</SectionTitle>
      </View>

      {stickyVisible ? (
        <View style={styles.stickyCheckIn} accessibilityRole="summary">
          <Text style={styles.stickyCode} numberOfLines={1}>
            {pass?.checkInCode ?? "Check-in ready"}
          </Text>
          <Text style={styles.stickyHint}>Show at check-in</Text>
        </View>
      ) : null}

      <ScrollView
        style={styles.body}
        contentContainerStyle={[styles.bodyContent, constrainedColumnStyle(layout.contentMaxWidth)]}
        scrollEventThrottle={16}
        onScroll={(event) => {
          if (!pass || pass.status !== "unlocked" || !checkInLabel) {
            if (showStickyCheckIn) setShowStickyCheckIn(false)
            return
          }
          const y = event.nativeEvent.contentOffset.y
          const next = y > heroHeightRef.current - 40
          if (next !== showStickyCheckIn) setShowStickyCheckIn(next)
        }}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => void load("refresh")}
            tintColor={colors.black}
          />
        }
      >
        {showFullLoader ? <LoadingPanel label="Loading pass…" variant="skeleton" /> : null}

        {!showFullLoader && error && !pass ? (
          <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
        ) : null}

        {!showFullLoader && !error && !pass ? (
          <EmptyPanel title="Pass not found" message="We couldn't load this event pass." />
        ) : null}

        {!showFullLoader && pass ? (
          <View style={styles.content}>
            {paymentBanner ? (
              <View
                style={[
                  styles.attentionChip,
                  payment === "cancelled" ? styles.attentionChipWarn : styles.attentionChipOk,
                ]}
              >
                <Text style={styles.attentionText}>{paymentBanner}</Text>
                <Pressable onPress={() => setPaymentBanner(null)} hitSlop={8}>
                  <Text style={styles.dismiss}>Dismiss</Text>
                </Pressable>
              </View>
            ) : null}

            {error ? <Text style={styles.inlineError}>{error.message}</Text> : null}

            {pass.status === "locked" ? (
              <LockedPassBody pass={pass} paying={paying} onPay={() => void handlePay()} />
            ) : (
              <UnlockedPassBody
                pass={pass}
                onHeroLayout={(height) => {
                  heroHeightRef.current = Math.max(height, 160)
                }}
              />
            )}
          </View>
        ) : null}
      </ScrollView>
    </SafeAreaView>
  )
}

function PressableContactRow({
  label,
  value,
  phone,
  last,
}: {
  label: string
  value: string
  phone?: string
  last?: boolean
}) {
  const emailMatch = value.match(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i)
  const href = phone ? `tel:${phone}` : emailMatch ? `mailto:${emailMatch[0]}` : undefined

  if (!href) {
    return <PassDetailRow label={label} value={value} last={last} />
  }

  return (
    <Pressable onPress={() => void openExternalUrl(href, label)} accessibilityRole="link">
      <PassDetailRow label={label} value={value} last={last} />
    </Pressable>
  )
}

function LockedPassBody({
  pass,
  paying,
  onPay,
}: {
  pass: VendorEventPass
  paying: boolean
  onPay: () => void
}) {
  const meta = eventMeta(pass)
  const amount = pass.amountDueFormatted
  const canPay = Boolean(pass.paymentId)
  return (
    <>
      <View style={styles.lockedHero}>
        <Text style={styles.lockedEyebrow}>Locked</Text>
        <Text style={styles.lockedTitle} numberOfLines={3}>
          {pass.eventName}
        </Text>
        {meta ? <Text style={styles.lockedMeta}>{meta}</Text> : null}
        {pass.message ? <Text style={styles.lockedMessage}>{pass.message}</Text> : null}
        {amount ? <Text style={styles.amountDue}>{amount} due</Text> : null}
        <Button
          label={amount ? `Pay ${amount}` : "Pay now"}
          loadingLabel="Opening checkout…"
          icon="card-outline"
          fullWidth
          loading={paying}
          disabled={!canPay}
          onPress={onPay}
          style={styles.payButton}
        />
        {!canPay ? (
          <Text style={styles.payHint}>
            Payment isn’t ready yet. Pull to refresh, or check Wallet in a moment.
          </Text>
        ) : null}
        <Button
          label="View in Wallet"
          variant="ghost"
          size="sm"
          onPress={() => router.push("/(vendor)/wallet" as Href)}
        />
      </View>

      {pass.unlocks.length > 0 ? (
        <View style={styles.unlocksQuiet}>
          <Text style={styles.unlocksTitle}>Unlocks after payment</Text>
          <PassBulletList items={pass.unlocks} />
        </View>
      ) : null}
    </>
  )
}

function UnlockedPassBody({
  pass,
  onHeroLayout,
}: {
  pass: VendorEventPass
  onHeroLayout?: (height: number) => void
}) {
  const meta = eventMeta(pass)
  const timeRange = [pass.startTime, pass.endTime].filter(Boolean).join(" – ")
  const callPhone =
    extractPhone(pass.dayOfContact) ||
    extractPhone(pass.organizerPhone) ||
    extractPhone(pass.emergencyContact)

  const hasArrival =
    pass.venueName ||
    pass.venueAddress ||
    pass.loadInTime ||
    pass.loadOutTime ||
    pass.parkingInstructions ||
    pass.loadInGate

  const hasBooth =
    pass.boothAssignment || pass.boothZone || pass.boothDimensions || pass.powerDetails || pass.waterDetails

  const hasRules =
    pass.vendorRules.length > 0 ||
    pass.setupRestrictions.length > 0 ||
    pass.prohibitedItems.length > 0 ||
    pass.refundPolicy

  const hasWeather =
    pass.weatherNote ||
    pass.rainPlan ||
    pass.heatPlan ||
    pass.weatherPolicy ||
    pass.forecastStatus ||
    pass.contingency ||
    pass.weatherRecommendations.length > 0 ||
    pass.weatherRisks.length > 0

  const hasContacts =
    pass.organizerName ||
    pass.organizerEmail ||
    pass.organizerPhone ||
    pass.dayOfContact ||
    pass.emergencyContact

  const hasReadiness =
    pass.readinessPercent != null || pass.readinessLabel || pass.readinessMissing.length > 0

  const readinessIncomplete =
    (pass.readinessPercent != null && pass.readinessPercent < 100) || pass.readinessMissing.length > 0

  const canDirections = Boolean(pass.venueAddress || pass.mapUrl)

  const arrivalRows = [
    { label: "Venue", value: pass.venueName },
    { label: "Address", value: pass.venueAddress },
    { label: "Load-in", value: pass.loadInTime },
    { label: "Gate", value: pass.loadInGate },
    { label: "Load-out", value: pass.loadOutTime },
    { label: "Parking", value: pass.parkingInstructions },
  ].filter((r) => r.value)

  const boothRows = [
    { label: "Assignment", value: pass.boothAssignment },
    { label: "Zone", value: pass.boothZone },
    { label: "Size", value: pass.boothDimensions },
    { label: "Power", value: pass.powerDetails },
    { label: "Water", value: pass.waterDetails },
  ].filter((r) => r.value)

  const weatherRows = [
    { label: "Forecast", value: pass.forecastStatus },
    { label: "Note", value: pass.weatherNote },
    { label: "Rain plan", value: pass.rainPlan },
    { label: "Heat plan", value: pass.heatPlan },
    { label: "Contingency", value: pass.contingency },
    { label: "Policy", value: pass.weatherPolicy },
  ].filter((r) => r.value)

  return (
    <>
      <View style={styles.unlockedHero} onLayout={(event) => onHeroLayout?.(event.nativeEvent.layout.height)}>
        <View style={styles.readyBadge}>
          <Text style={styles.readyBadgeText}>Ready</Text>
        </View>
        <Text style={styles.unlockedTitle} numberOfLines={3}>
          {pass.eventName}
        </Text>
        {meta ? <Text style={styles.unlockedMeta}>{meta}</Text> : null}
        {timeRange ? <Text style={styles.unlockedMeta}>{timeRange}</Text> : null}

        {pass.checkInCode || pass.qrCodeUrl ? (
          <View style={styles.qrHero}>
            {pass.checkInCode ? (
              <View style={styles.qrPad}>
                <QRCode value={pass.checkInCode} size={168} backgroundColor={colors.white} />
              </View>
            ) : pass.qrCodeUrl ? (
              <Image
                source={{ uri: pass.qrCodeUrl }}
                style={styles.qrImage}
                accessibilityLabel="Check-in QR code"
              />
            ) : null}
            {pass.checkInCode ? <Text style={styles.checkInCode}>{pass.checkInCode}</Text> : null}
            <Text style={styles.checkInHint}>Show this at check-in</Text>
          </View>
        ) : null}
      </View>

      {canDirections || callPhone ? (
        <View style={styles.actionRow}>
          {canDirections ? (
            <Button
              label="Get directions"
              icon="navigate-outline"
              variant="accent"
              style={styles.actionBtnFlex}
              onPress={() => {
                const url =
                  pass.mapUrl || `https://maps.google.com/?q=${encodeURIComponent(pass.venueAddress || "")}`
                void openExternalUrl(url, "directions")
              }}
            />
          ) : null}
          {callPhone ? (
            <Button
              label="Call day-of"
              icon="call-outline"
              style={styles.actionBtnFlex}
              onPress={() => void openExternalUrl(`tel:${callPhone}`, "phone")}
            />
          ) : null}
        </View>
      ) : null}

      {pass.emptyMessage ? <Text style={styles.emptyHint}>{pass.emptyMessage}</Text> : null}

      {hasArrival ? (
        <PassSectionCard title="Arrival">
          {arrivalRows.map((row, i) => (
            <PassDetailRow
              key={row.label}
              label={row.label}
              value={row.value}
              last={i === arrivalRows.length - 1}
            />
          ))}
        </PassSectionCard>
      ) : null}

      {hasBooth ? (
        <PassSectionCard title="Booth">
          {boothRows.map((row, i) => (
            <PassDetailRow
              key={row.label}
              label={row.label}
              value={row.value}
              last={i === boothRows.length - 1}
            />
          ))}
        </PassSectionCard>
      ) : null}

      {pass.mapImageUrl ? (
        <PassSectionCard title="Venue map">
          <Image
            source={{ uri: pass.mapImageUrl }}
            style={styles.mapImage}
            resizeMode="contain"
            accessibilityLabel="Venue map"
          />
        </PassSectionCard>
      ) : null}

      {hasRules ? (
        <PassSectionCard title="Rules">
          <PassBulletList items={pass.vendorRules} />
          {pass.setupRestrictions.length > 0 ? (
            <View style={styles.ruleBlock}>
              <Text style={styles.subhead}>Setup</Text>
              <PassBulletList items={pass.setupRestrictions} />
            </View>
          ) : null}
          {pass.prohibitedItems.length > 0 ? (
            <View style={styles.ruleBlock}>
              <Text style={styles.subhead}>Prohibited</Text>
              <PassBulletList items={pass.prohibitedItems} />
            </View>
          ) : null}
          <PassDetailRow label="Refund policy" value={pass.refundPolicy} last />
        </PassSectionCard>
      ) : null}

      {hasWeather ? (
        <PassSectionCard title="Weather">
          {weatherRows.map((row, i) => (
            <PassDetailRow
              key={row.label}
              label={row.label}
              value={row.value}
              last={
                i === weatherRows.length - 1 &&
                pass.weatherRecommendations.length === 0 &&
                pass.weatherRisks.length === 0
              }
            />
          ))}
          {pass.weatherRecommendations.length > 0 ? (
            <View style={styles.ruleBlock}>
              <Text style={styles.subhead}>Recommendations</Text>
              <PassBulletList items={pass.weatherRecommendations} />
            </View>
          ) : null}
          {pass.weatherRisks.length > 0 ? (
            <View style={styles.ruleBlock}>
              <Text style={styles.subhead}>Risks</Text>
              <PassBulletList items={pass.weatherRisks} />
            </View>
          ) : null}
        </PassSectionCard>
      ) : null}

      {hasReadiness ? (
        <PassSectionCard title="Readiness" accent={readinessIncomplete ? "lime" : "default"}>
          <View style={styles.readinessRow}>
            {pass.readinessPercent != null ? (
              <Text style={styles.readinessPct}>{pass.readinessPercent}%</Text>
            ) : null}
            {pass.readinessLabel ? <Text style={styles.readinessLabel}>{pass.readinessLabel}</Text> : null}
            {pass.readinessRiskBucket ? (
              <Text style={styles.readinessBucket}>{pass.readinessRiskBucket.toUpperCase()}</Text>
            ) : null}
          </View>
          <PassBulletList items={pass.readinessMissing} />
          {readinessIncomplete ? (
            <Button
              label="Upload documents"
              icon="document-text-outline"
              variant="secondary"
              onPress={() => router.push("/(vendor)/documents" as Href)}
            />
          ) : null}
        </PassSectionCard>
      ) : null}

      {hasContacts ? (
        <PassSectionCard title="Contacts">
          <PassDetailRow label="Organizer" value={pass.organizerName} />
          {pass.organizerEmail ? (
            <Pressable onPress={() => void openExternalUrl(`mailto:${pass.organizerEmail}`, "email")}>
              <PassDetailRow label="Email" value={pass.organizerEmail} />
            </Pressable>
          ) : null}
          {pass.organizerPhone ? (
            <Pressable
              onPress={() =>
                void openExternalUrl(
                  `tel:${extractPhone(pass.organizerPhone) || pass.organizerPhone}`,
                  "phone",
                )
              }
            >
              <PassDetailRow label="Phone" value={pass.organizerPhone} />
            </Pressable>
          ) : null}
          {pass.dayOfContact ? (
            <PressableContactRow
              label="Day-of"
              value={pass.dayOfContact}
              phone={extractPhone(pass.dayOfContact)}
            />
          ) : null}
          {pass.emergencyContact ? (
            <PressableContactRow
              label="Emergency"
              value={pass.emergencyContact}
              phone={extractPhone(pass.emergencyContact)}
              last
            />
          ) : null}
        </PassSectionCard>
      ) : null}
    </>
  )
}

const styles = StyleSheet.create({
  safe: {
    flex: 1,
    backgroundColor: colors.white,
  },
  header: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
  },
  stickyCheckIn: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm + 2,
    backgroundColor: colors.black,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray900,
  },
  stickyCode: {
    flex: 1,
    fontSize: typeScale.callout,
    fontWeight: "800",
    letterSpacing: 1.2,
    color: colors.white,
  },
  stickyHint: {
    fontSize: typeScale.caption,
    fontWeight: "600",
    color: colors.gray400,
  },
  body: {
    flex: 1,
  },
  bodyContent: {
    padding: spacing.lg,
    paddingBottom: spacing.xxl,
  },
  content: {
    gap: spacing.lg,
  },
  attentionChip: {
    flexDirection: "row",
    alignItems: "center",
    justifyContent: "space-between",
    gap: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm + 2,
  },
  attentionChipOk: {
    backgroundColor: colors.green50,
    borderColor: colors.green200,
  },
  attentionChipWarn: {
    backgroundColor: colors.amber50,
    borderColor: colors.amber200,
  },
  attentionText: {
    flex: 1,
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.black,
  },
  dismiss: {
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.gray700,
  },
  inlineError: {
    fontSize: typeScale.footnote,
    color: colors.red600,
  },
  lockedHero: {
    backgroundColor: colors.amber50,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.amber200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  lockedEyebrow: {
    fontSize: typeScale.caption,
    fontWeight: "800",
    letterSpacing: 0.8,
    textTransform: "uppercase",
    color: colors.amber700,
  },
  lockedTitle: {
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.black,
  },
  lockedMeta: {
    fontSize: typeScale.body,
    color: colors.gray700,
  },
  lockedMessage: {
    fontSize: typeScale.body,
    color: colors.gray700,
    lineHeight: 20,
  },
  amountDue: {
    marginTop: spacing.xs,
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.amber700,
  },
  payHint: {
    marginTop: spacing.sm,
    fontSize: typeScale.footnote,
    lineHeight: 18,
    color: colors.gray700,
    textAlign: "center",
  },
  unlocksQuiet: {
    gap: spacing.sm,
    paddingHorizontal: spacing.xs,
  },
  unlocksTitle: {
    fontSize: typeScale.footnote,
    fontWeight: "700",
    color: colors.gray500,
    textTransform: "uppercase",
    letterSpacing: 0.4,
  },
  unlockedHero: {
    backgroundColor: colors.black,
    borderRadius: radii.lg,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  readyBadge: {
    alignSelf: "flex-start",
    backgroundColor: colors.lime,
    borderRadius: radii.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
  },
  readyBadgeText: {
    fontSize: typeScale.micro,
    fontWeight: "800",
    letterSpacing: 0.6,
    textTransform: "uppercase",
    color: colors.black,
  },
  unlockedTitle: {
    fontSize: typeScale.title,
    fontWeight: "800",
    color: colors.white,
  },
  unlockedMeta: {
    fontSize: typeScale.body,
    color: colors.gray400,
  },
  qrHero: {
    marginTop: spacing.md,
    alignItems: "center",
    gap: spacing.sm,
  },
  qrPad: {
    padding: spacing.md,
    backgroundColor: colors.white,
    borderRadius: radii.md,
  },
  qrImage: {
    width: 168,
    height: 168,
    borderRadius: radii.md,
    backgroundColor: colors.white,
  },
  checkInCode: {
    fontSize: typeScale.headline,
    fontWeight: "800",
    letterSpacing: 2,
    color: colors.white,
  },
  checkInHint: {
    fontSize: typeScale.caption,
    fontWeight: "600",
    color: colors.gray400,
  },
  actionRow: {
    flexDirection: "row",
    gap: spacing.sm,
  },
  payButton: {
    marginTop: spacing.sm,
  },
  actionBtnFlex: {
    flex: 1,
  },
  emptyHint: {
    fontSize: typeScale.body,
    color: colors.gray500,
    lineHeight: 20,
  },
  mapImage: {
    width: "100%",
    height: 220,
    borderRadius: radii.md,
    backgroundColor: colors.gray100,
  },
  ruleBlock: {
    gap: spacing.xs,
    paddingTop: spacing.sm,
  },
  subhead: {
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.gray700,
    textTransform: "uppercase",
    letterSpacing: 0.3,
  },
  readinessRow: {
    flexDirection: "row",
    alignItems: "baseline",
    gap: spacing.md,
    paddingBottom: spacing.sm,
  },
  readinessPct: {
    fontSize: typeScale.display,
    fontWeight: "800",
    color: colors.black,
  },
  readinessLabel: {
    fontSize: typeScale.callout,
    fontWeight: "600",
    color: colors.gray700,
  },
  readinessBucket: {
    fontSize: typeScale.caption,
    fontWeight: "800",
    color: colors.gray500,
    letterSpacing: 0.6,
  },
})
