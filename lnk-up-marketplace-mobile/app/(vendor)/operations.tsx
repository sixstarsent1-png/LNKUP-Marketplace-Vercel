import { useCallback, useEffect, useMemo, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { EventImage } from "@/components/ui/event-image"
import { SectionHeader } from "@/components/ui/section-header"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorOperations, operationsStatusLabel, type VendorOperationsEvent } from "@/lib/api/vendor-operations"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, fonts, radii, spacing, typeScale } from "@/lib/theme"

/**
 * Event preparation: one card per booking with what the vendor still needs to do and what the
 * organizer has not published yet (booth, load-in), so organizer gaps never read as vendor tasks.
 */
export default function VendorOperationsScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<VendorOperationsEvent[]>([])

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        const result = await fetchVendorOperations(auth)
        setItems(result.items)
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  const upcoming = useMemo(() => items.filter((i) => !i.isPast && i.status !== "cancelled"), [items])
  const past = useMemo(() => items.filter((i) => i.isPast || i.status === "cancelled"), [items])

  return (
    <Screen title="Event prep" subtitle="Passes, booths and arrival details" refreshing={refreshing} onRefresh={() => void load("refresh")} header={<BackLink label="Profile" fallbackHref="/(vendor)/more" />}>
      {loading ? <LoadingPanel label="Loading your events…" variant="skeleton" count={2} /> : null}
      {!loading && error ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} /> : null}
      {!loading && !error && items.length === 0 ? (
        <EmptyPanel
          icon="ticket-outline"
          title="No events to prepare for yet"
          message="Your event operations details will appear here once you're approved and paid for an event."
          actionLabel="Explore events"
          onAction={() => router.push("/(vendor)/find")}
        />
      ) : null}

      {!loading && !error && upcoming.length > 0 ? (
        <View style={styles.section}>
          <SectionHeader title="Upcoming" icon="calendar-outline" />
          {upcoming.map((item) => (
            <PrepCard key={item.requestId} item={item} />
          ))}
        </View>
      ) : null}

      {!loading && !error && past.length > 0 ? (
        <View style={styles.section}>
          <SectionHeader title="Past" icon="time-outline" />
          {past.map((item) => (
            <PrepCard key={item.requestId} item={item} />
          ))}
        </View>
      ) : null}
    </Screen>
  )
}

function PrepCard({ item }: { item: VendorOperationsEvent }) {
  const passActive = item.credentialState ? item.credentialState === "active" : item.passStatus === "unlocked" && !item.isPast
  const rows: Array<{ icon: AppIconName; text: string; muted?: boolean; owner: "vendor" | "organizer" | "done" }> = []
  if (!item.isPast) {
    if (item.status === "payment_required") rows.push({ icon: "card-outline", text: "Pay the booth fee to confirm your spot", owner: "vendor" })
    rows.push(
      item.boothAssignment
        ? { icon: "grid-outline", text: `Booth ${item.boothAssignment}`, owner: "done" }
        : { icon: "grid-outline", text: "Booth assignment: organizer hasn't published it yet", muted: true, owner: "organizer" },
    )
    rows.push(
      item.loadInTime
        ? { icon: "time-outline", text: `Load-in ${item.loadInTime}`, owner: "done" }
        : { icon: "time-outline", text: "Load-in window: organizer hasn't published it yet", muted: true, owner: "organizer" },
    )
    if (item.packetStatus) {
      rows.push({ icon: "document-text-outline", text: `Event packet: ${item.packetStatus}`, owner: item.packetStatus.toLowerCase() === "complete" ? "done" : "vendor" })
    }
    if (item.readinessPercent != null) {
      rows.push({ icon: "checkmark-done-outline", text: `Your checklist: ${item.readinessPercent}% done`, owner: item.readinessPercent >= 100 ? "done" : "vendor" })
    }
  }
  return (
    <Pressable
      style={({ pressed }) => [styles.card, pressed && styles.pressed]}
      onPress={() => router.push((passActive || item.status === "payment_required" ? `/pass/${item.requestId}?from=operations` : `/booking/${item.requestId}`) as Href)}
      accessibilityRole="button"
      accessibilityLabel={[item.eventName, item.eventDate, item.countdown, operationsStatusLabel(item.status)].filter(Boolean).join(", ")}
    >
      <View style={styles.head}>
        <EventImage uri={item.imageUrl} height={56} aspectRatio={1} radius={radii.md} style={styles.thumb} icon="calendar-outline" />
        <View style={styles.headText}>
          <Text style={styles.title} numberOfLines={2}>
            {item.eventName}
          </Text>
          <Meta numberOfLines={1}>{[item.eventDate, item.countdown, [item.city, item.state].filter(Boolean).join(", ")].filter(Boolean).join(" · ")}</Meta>
        </View>
      </View>
      {rows.map((row) => (
        <View key={row.text} style={styles.row}>
          <AppIcon name={row.owner === "done" ? "checkmark-circle" : row.icon} size={16} color={row.owner === "done" ? colors.green700 : row.muted ? colors.gray400 : colors.amber700} />
          <Text style={[styles.rowText, row.muted && styles.muted]}>{row.text}</Text>
        </View>
      ))}
      <Text style={styles.cta}>
        {passActive ? "Open event pass ›" : item.status === "payment_required" ? "Pay to unlock your pass ›" : item.isPast ? "View booking ›" : "View details ›"}
      </Text>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  section: { gap: spacing.md },
  card: { backgroundColor: colors.white, borderRadius: radii.lg, borderWidth: 1, borderColor: colors.line, padding: spacing.md, gap: spacing.sm },
  pressed: { opacity: 0.85 },
  head: { flexDirection: "row", gap: spacing.md, alignItems: "center" },
  thumb: { width: 56 },
  headText: { flex: 1, gap: 2 },
  title: { fontFamily: fonts.displaySemi, fontSize: typeScale.bodyLarge, color: colors.ink },
  row: { flexDirection: "row", alignItems: "center", gap: spacing.sm },
  rowText: { flex: 1, fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.gray700 },
  muted: { color: colors.gray500 },
  cta: { fontFamily: fonts.bodySemi, fontSize: typeScale.body, color: colors.ink, marginTop: spacing.xs },
})
