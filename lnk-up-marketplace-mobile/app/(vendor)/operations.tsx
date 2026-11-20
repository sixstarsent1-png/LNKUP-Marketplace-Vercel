import { useCallback, useEffect, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { router, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchVendorOperations,
  operationsStatusLabel,
  type VendorOperationsEvent,
} from "@/lib/api/vendor-operations"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, radii, spacing } from "@/lib/theme"

export default function VendorOperationsScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<VendorOperationsEvent[]>([])

  const load = useCallback(async (mode: "initial" | "refresh" = "initial") => {
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
  }, [auth])

  useEffect(() => {
    void load("initial")
  }, [load])

  return (
    <Screen
      title="Event passes"
      subtitle="Passes and day-of details"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <BackLink label="Profile" fallbackHref="/(vendor)/more" />

      {loading ? <LoadingPanel label="Loading passes…" /> : null}

      {!loading && error ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {!loading && !error && items.length === 0 ? (
        <EmptyPanel
          title="No event-day passes yet"
          message="Approved and paid bookings will show here with pass access."
        />
      ) : null}

      {!loading && !error && items.length > 0 ? (
        <View style={styles.list}>
          {items.map((item) => (
            <Pressable
              key={item.requestId}
              style={styles.card}
              onPress={() =>
                router.push(`/pass/${item.requestId}?from=operations` as Href)
              }
            >
              <Text style={styles.title}>{item.eventName}</Text>
              <Text style={styles.meta}>
                {[item.eventDate, item.city, item.state].filter(Boolean).join(" · ")}
              </Text>
              <Text style={styles.status}>{operationsStatusLabel(item.status)}</Text>
              {item.boothAssignment ? (
                <Text style={styles.meta}>Booth {item.boothAssignment}</Text>
              ) : null}
              {item.loadInTime ? <Text style={styles.meta}>Load-in {item.loadInTime}</Text> : null}
              {item.readinessPercent != null ? (
                <Text style={styles.meta}>Readiness {item.readinessPercent}%</Text>
              ) : null}
              <Text style={styles.cta}>
                {item.passStatus === "unlocked" ? "Open pass ›" : "View details ›"}
              </Text>
            </Pressable>
          ))}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  back: { marginBottom: spacing.sm },
  backText: { fontSize: 14, fontWeight: "600", color: colors.gray700 },
  list: { gap: spacing.md },
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.xs,
  },
  title: { fontSize: 16, fontWeight: "700", color: colors.black },
  meta: { fontSize: 13, color: colors.gray500 },
  status: { fontSize: 13, fontWeight: "600", color: colors.gray700, marginTop: 2 },
  cta: { marginTop: spacing.sm, fontSize: 14, fontWeight: "700", color: colors.black },
})
