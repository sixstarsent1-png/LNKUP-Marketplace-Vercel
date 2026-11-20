import { useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { colors, spacing } from "@/lib/theme"

type NetworkModule = {
  getNetworkStateAsync: () => Promise<{
    isConnected?: boolean | null
    isInternetReachable?: boolean | null
  }>
}

function loadNetworkModule(): NetworkModule | null {
  try {
    // Lazy require — missing native module must not crash OTA on older binaries.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    return require("expo-network") as NetworkModule
  } catch {
    return null
  }
}

/**
 * Soft banner when the device reports offline / no internet.
 * No-ops on binaries that do not include expo-network.
 */
export function OfflineBanner() {
  const [offline, setOffline] = useState(false)

  useEffect(() => {
    const Network = loadNetworkModule()
    if (!Network?.getNetworkStateAsync) return

    let active = true

    async function check() {
      try {
        const state = await Network!.getNetworkStateAsync()
        if (!active) return
        const isOffline = state.isConnected === false || state.isInternetReachable === false
        setOffline(isOffline)
      } catch {
        if (active) setOffline(false)
      }
    }

    void check()
    const id = setInterval(() => void check(), 8000)
    return () => {
      active = false
      clearInterval(id)
    }
  }, [])

  if (!offline) return null

  return (
    <View style={styles.banner} accessibilityRole="alert">
      <Text style={styles.text}>You're offline. Some actions may not work until you reconnect.</Text>
    </View>
  )
}

const styles = StyleSheet.create({
  banner: {
    backgroundColor: colors.amber50,
    borderBottomWidth: 1,
    borderBottomColor: colors.amber200,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
  },
  text: {
    fontSize: 13,
    fontWeight: "600",
    color: colors.amber700,
    textAlign: "center",
  },
})
