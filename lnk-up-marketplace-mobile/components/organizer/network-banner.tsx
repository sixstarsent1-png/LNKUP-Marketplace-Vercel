import { StyleSheet, View } from "react-native"

import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import type { NetworkStatus } from "@/lib/network/use-network-status"
import { colors, radii, spacing, typography } from "@/lib/theme"

const COPY: Record<Exclude<NetworkStatus, "online">, { title: string; body: string }> = {
  offline: {
    title: "You're offline",
    body: "Showing the roster from your last refresh. Check-in is paused until you reconnect — nothing is saved offline.",
  },
  reconnecting: {
    title: "Back online",
    body: "Refreshing the roster before check-in resumes.",
  },
}

/**
 * Honest connectivity state for event-day screens. Online renders nothing; offline explains that
 * the data is read-only and that no check-ins are queued.
 */
export function NetworkBanner({ status, lastUpdated }: { status: NetworkStatus; lastUpdated?: string }) {
  if (status === "online") return null
  const copy = COPY[status]
  const offline = status === "offline"
  return (
    <View
      style={[styles.banner, offline ? styles.offline : styles.reconnecting]}
      accessibilityRole="alert"
      accessibilityLiveRegion="polite"
      testID={`network-banner-${status}`}
    >
      <AppIcon name={offline ? "cloud-offline-outline" : "sync-outline"} size={18} color={offline ? colors.amber700 : colors.gray700} />
      <View style={styles.text}>
        <Text style={[styles.title, offline && styles.titleOffline]}>{copy.title}</Text>
        <Text style={styles.body}>
          {copy.body}
          {offline && lastUpdated ? ` Last updated ${lastUpdated}.` : ""}
        </Text>
      </View>
    </View>
  )
}

const styles = StyleSheet.create({
  banner: {
    flexDirection: "row",
    gap: spacing.sm,
    padding: spacing.md,
    borderRadius: radii.md,
    borderWidth: 1,
  },
  offline: {
    backgroundColor: colors.amber50,
    borderColor: colors.amber200,
  },
  reconnecting: {
    backgroundColor: colors.gray50,
    borderColor: colors.gray200,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  title: {
    ...typography.label,
  },
  titleOffline: {
    color: colors.amber700,
  },
  body: {
    ...typography.meta,
  },
})
