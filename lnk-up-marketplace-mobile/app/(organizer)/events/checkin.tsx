import { useCallback, useMemo, useRef, useState } from "react"
import { StyleSheet, View } from "react-native"
import { useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { hasPassScanner, PassScanner } from "@/components/pass-scanner"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChipRow } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SkeletonList, SkeletonStats } from "@/components/ui/skeleton"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  CHECK_IN_FILTERS,
  assignBooth,
  canCheckIn,
  canOverride,
  checkInApplication,
  checkInByCode,
  checkInCounts,
  eventDayEntries,
  fetchEventBooths,
  matchesCheckInFilter,
  matchesCheckInSearch,
  needsAttention,
  overrideCheckIn,
  type CheckInFilter,
  type CheckInOutcome,
  type EventBooth,
} from "@/lib/api/organizer-checkin"
import { fetchEventRoster, type RosterEntry } from "@/lib/api/organizer-roster"
import { useAuth } from "@/lib/auth/auth-context"
import { promptText, showToast } from "@/lib/feedback/feedback"
import { useKeyedSingleFlight, useSingleFlight } from "@/lib/hooks/use-single-flight"
import { spacing } from "@/lib/theme"

const OVERRIDE_MIN_REASON = 5

export default function OrganizerCheckInScreen() {
  const { auth } = useAuth()
  const { eventId: eventParam } = useLocalSearchParams<{ eventId?: string }>()
  const eventId = String(eventParam ?? "")
  const [entries, setEntries] = useState<RosterEntry[] | null>(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [filter, setFilter] = useState<CheckInFilter>("waiting")
  const [query, setQuery] = useState("")
  const [code, setCode] = useState("")
  const [scannerOpen, setScannerOpen] = useState(false)
  const [codeResult, setCodeResult] = useState<CheckInOutcome | null>(null)
  const [boothFor, setBoothFor] = useState<RosterEntry | null>(null)
  const [booths, setBooths] = useState<EventBooth[] | null>(null)
  const [boothsError, setBoothsError] = useState<string | null>(null)
  const entriesRef = useRef<RosterEntry[] | null>(null)
  entriesRef.current = entries

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (!eventId) return
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      setRefreshError(null)
      try {
        const roster = await fetchEventRoster(eventId, auth, { force: mode === "refresh" })
        setEntries(eventDayEntries(roster))
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (entriesRef.current) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth, eventId],
  )

  useFocusEffect(
    useCallback(() => {
      void load(entriesRef.current ? "refresh" : "initial")
    }, [load]),
  )

  const reportOutcome = useCallback(
    (outcome: CheckInOutcome, name: string) => {
      switch (outcome.kind) {
        case "checked_in":
          showToast(`${name}: ${outcome.message}`, "success")
          break
        case "already":
          showToast(`${name} is already checked in.`, "info")
          break
        case "blocked":
        case "denied":
        case "offline":
        case "error":
          showToast(`${name}: ${outcome.message}`, "error")
          break
        case "unconfirmed":
          showToast(outcome.message, "info")
          break
        default: {
          const exhaustive: never = outcome
          return exhaustive
        }
      }
      if (outcome.kind !== "offline") void load("refresh")
    },
    [load],
  )

  const [checkIn, isCheckingIn] = useKeyedSingleFlight(async (applicationId: string) => {
    const entry = entries?.find((item) => item.applicationId === applicationId)
    if (!entry) return
    reportOutcome(await checkInApplication(auth, eventId, applicationId), entry.businessName)
  })

  const [override, isOverriding] = useKeyedSingleFlight(async (applicationId: string) => {
    const entry = entries?.find((item) => item.applicationId === applicationId)
    if (!entry) return
    const reason = await promptText({
      title: `Check in ${entry.businessName} anyway?`,
      message: `${entry.blockMessage ?? "This vendor isn't cleared yet."} Your reason is saved to the event log.`,
      placeholder: "Reason (for example: paid cash at the gate)",
      confirmLabel: "Check in",
      minLength: OVERRIDE_MIN_REASON,
      multiline: true,
    })
    if (!reason) return
    reportOutcome(await overrideCheckIn(auth, eventId, applicationId, reason.trim()), entry.businessName)
  })

  const [submitCode, codeBusy] = useSingleFlight(async (scanned?: string) => {
    const trimmed = (scanned ?? code).trim()
    if (!trimmed) {
      setCodeResult({ kind: "error", message: "Enter the code from the vendor's pass." })
      return
    }
    const outcome = await checkInByCode(auth, eventId, trimmed)
    setCodeResult(outcome)
    if (outcome.kind === "checked_in" || outcome.kind === "already") setCode("")
    if (outcome.kind !== "offline") void load("refresh")
  })

  const [openBooths, boothsLoading] = useSingleFlight(async (entry: RosterEntry) => {
    setBoothFor(entry)
    setBoothsError(null)
    try {
      setBooths(await fetchEventBooths(auth, eventId))
    } catch (err) {
      setBoothsError(appErrorFromWp(err).message)
    }
  })

  const [chooseBooth, isAssigning] = useKeyedSingleFlight(async (boothId: string) => {
    const entry = boothFor
    const booth = booths?.find((item) => item.id === boothId)
    if (!entry || !booth) return
    try {
      await assignBooth(auth, eventId, boothId, entry.applicationId)
      showToast(`${entry.businessName} is now at booth ${booth.number}.`, "success")
      setBoothFor(null)
      void load("refresh")
    } catch (err) {
      setBoothsError(appErrorFromWp(err).message)
    }
  })

  const counts = useMemo(() => checkInCounts(entries ?? []), [entries])
  const visible = useMemo(
    () => (entries ?? []).filter((entry) => matchesCheckInFilter(entry, filter) && matchesCheckInSearch(entry, query)),
    [entries, filter, query],
  )
  const filterOptions = useMemo(
    () =>
      CHECK_IN_FILTERS.map((item) => ({
        value: item.key,
        label: item.label,
        count: (entries ?? []).filter((entry) => matchesCheckInFilter(entry, item.key)).length,
      })),
    [entries],
  )

  const back = <BackLink label="Event" fallbackHref={(eventId ? `/(organizer)/events/${eventId}?tab=dayof` : "/(organizer)/events") as Href} />

  if (!eventId) {
    return (
      <Screen title="Check-in" header={back}>
        <EmptyPanel icon="calendar-outline" title="Pick an event" message="Open check-in from an event's Day-of tab." />
      </Screen>
    )
  }

  return (
    <Screen
      title="Check-in"
      subtitle="Check vendors in as they arrive"
      header={back}
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      {loading && !entries ? (
        <View style={styles.stack} accessibilityLabel="Loading check-in">
          <SkeletonStats count={3} />
          <SkeletonList count={4} />
        </View>
      ) : null}

      {!loading && error && !entries ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {entries ? (
        <View style={styles.stack}>
          {refreshError ? (
            <InlineBanner
              tone="error"
              message={`Couldn’t refresh the list: ${refreshError}`}
              actionLabel="Retry"
              onAction={() => void load("refresh")}
            />
          ) : null}

          <StatGrid>
            <StatTile icon="checkmark-circle-outline" label="Checked in" value={`${counts.checkedIn} of ${counts.expected}`} tone={counts.checkedIn > 0 ? "accent" : "default"} />
            <StatTile icon="alert-circle-outline" label="Needs attention" value={String(counts.attention)} />
          </StatGrid>

          <Surface style={styles.card} testID="checkin-code-card">
            <SectionTitle>Pass code</SectionTitle>
            <Meta>Type the code printed under the vendor’s QR pass.</Meta>
            <FormField
              label="Pass code"
              value={code}
              onChangeText={(value) => {
                setCode(value)
                setCodeResult(null)
              }}
              autoCapitalize="none"
              autoCorrect={false}
              returnKeyType="go"
              onSubmitEditing={() => void submitCode()}
              tone="muted"
            />
            <Button label="Check in with code" loadingLabel="Checking…" icon="qr-code-outline" loading={codeBusy} onPress={() => void submitCode()} />
            {hasPassScanner() ? (
              <Button
                label="Scan pass QR"
                icon="scan-outline"
                variant="secondary"
                disabled={codeBusy}
                onPress={() => setScannerOpen(true)}
              />
            ) : null}
            <PassScanner
              visible={scannerOpen}
              onClose={() => setScannerOpen(false)}
              onCode={(scanned) => {
                setScannerOpen(false)
                setCode(scanned)
                void submitCode(scanned)
              }}
            />
            {codeResult ? <CodeResultBanner outcome={codeResult} /> : null}
          </Surface>

          <FormField
            label="Find a vendor"
            value={query}
            onChangeText={setQuery}
            placeholder="Business name or booth number"
            autoCorrect={false}
          />
          <FilterChipRow options={filterOptions} value={filter} onChange={setFilter} />

          {entries.length === 0 ? (
            <EmptyPanel
              icon="people-outline"
              title="No booked vendors yet"
              message="Approved and paid vendors appear here on event day."
            />
          ) : visible.length === 0 ? (
            <EmptyPanel icon="search-outline" title="No vendors match" message="Try another name or filter." />
          ) : (
            <ListGroup>
              {visible.map((entry) => (
                <CheckInRow
                  key={entry.applicationId}
                  entry={entry}
                  checkingIn={isCheckingIn(entry.applicationId) || isOverriding(entry.applicationId)}
                  onCheckIn={() => void checkIn(entry.applicationId)}
                  onOverride={() => void override(entry.applicationId)}
                  onBooth={() => void openBooths(entry)}
                />
              ))}
            </ListGroup>
          )}
        </View>
      ) : null}

      <BottomSheet
        visible={boothFor != null}
        title={boothFor ? `Booth for ${boothFor.businessName}` : "Booth"}
        onClose={() => setBoothFor(null)}
      >
        {boothsError ? <InlineBanner tone="error" message={boothsError} /> : null}
        {boothsLoading && !booths ? <SkeletonList count={3} /> : null}
        {booths && booths.length === 0 ? (
          <EmptyPanel icon="grid-outline" title="No booths set up" message="Add booths for this event on the web dashboard." />
        ) : null}
        {booths && booths.length > 0 && boothFor ? (
          <ListGroup>
            {booths.map((booth) => {
              const mine = booth.applicationId === boothFor.applicationId
              const taken = Boolean(booth.applicationId) && !mine
              return (
                <ListRow
                  key={booth.id}
                  icon="grid-outline"
                  title={`Booth ${booth.number}`}
                  meta={taken ? `Taken by ${booth.vendorName || "another vendor"}` : booth.typeLabel || undefined}
                  rightSlot={
                    <StatusBadge
                      label={mine ? "Current" : taken ? "Taken" : isAssigning(booth.id) ? "Assigning…" : "Open"}
                      tone={mine ? "approved" : taken ? "neutral" : "paid"}
                    />
                  }
                  onPress={taken || mine || isAssigning(booth.id) ? undefined : () => void chooseBooth(booth.id)}
                />
              )
            })}
          </ListGroup>
        ) : null}
      </BottomSheet>
    </Screen>
  )
}

function CodeResultBanner({ outcome }: { outcome: CheckInOutcome }) {
  switch (outcome.kind) {
    case "checked_in":
      return <InlineBanner tone="success" message={outcome.message} />
    case "already":
      return <InlineBanner tone="info" message={outcome.message} />
    case "unconfirmed":
      return <InlineBanner tone="info" message={outcome.message} />
    case "blocked":
      return (
        <InlineBanner
          tone="attention"
          message={outcome.canOverride ? `${outcome.message} Find the vendor below to check them in with a reason.` : outcome.message}
        />
      )
    case "offline":
    case "denied":
    case "error":
      return <InlineBanner tone="error" message={outcome.message} />
    default: {
      const exhaustive: never = outcome
      return exhaustive
    }
  }
}

function CheckInRow({
  entry,
  checkingIn,
  onCheckIn,
  onOverride,
  onBooth,
}: {
  entry: RosterEntry
  checkingIn: boolean
  onCheckIn: () => void
  onOverride: () => void
  onBooth: () => void
}) {
  const label = entry.opsLabel ?? (entry.checkedIn ? "Checked in" : "Ready to check in")
  const tone = entry.checkedIn ? "paid" : needsAttention(entry) ? "due" : "neutral"
  const meta = [entry.booth ? `Booth ${entry.booth}` : "No booth yet", needsAttention(entry) ? entry.blockMessage : null]
    .filter(Boolean)
    .join(" · ")
  return (
    <View style={styles.row} testID={`checkin-row-${entry.applicationId}`}>
      <ListRow title={entry.businessName} meta={meta} rightSlot={<StatusBadge label={label} tone={tone} />} />
      <View style={styles.rowActions}>
        {canCheckIn(entry) ? (
          <Button
            label="Check in"
            loadingLabel="Saving…"
            size="md"
            style={styles.flex}
            loading={checkingIn}
            onPress={onCheckIn}
            accessibilityLabel={`Check in ${entry.businessName}`}
          />
        ) : null}
        {canOverride(entry) ? (
          <Button
            label="Check in anyway"
            loadingLabel="Saving…"
            size="md"
            variant="secondary"
            style={styles.flex}
            loading={checkingIn}
            onPress={onOverride}
            accessibilityLabel={`Check in ${entry.businessName} with an override`}
          />
        ) : null}
        <Button
          label={entry.booth ? "Move booth" : "Assign booth"}
          size="md"
          variant="secondary"
          style={styles.flex}
          onPress={onBooth}
          accessibilityLabel={`${entry.booth ? "Move" : "Assign"} booth for ${entry.businessName}`}
        />
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  stack: {
    gap: spacing.lg,
  },
  card: {
    gap: spacing.sm,
  },
  row: {
    paddingBottom: spacing.sm,
  },
  rowActions: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
    paddingHorizontal: spacing.lg,
  },
  flex: {
    flexGrow: 1,
  },
})
