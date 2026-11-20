import { Platform } from "react-native"
import Constants from "expo-constants"

/**
 * Fetch and apply the latest EAS Update when running a store/dev-client binary.
 * No-ops in Expo Go / web / when updates are disabled.
 */
export async function checkAndApplyOtaUpdate(): Promise<boolean> {
  if (Platform.OS === "web") return false

  try {
    // Lazy require so Expo Go / missing native module never crashes startup.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Updates = require("expo-updates") as typeof import("expo-updates")
    if (!Updates.isEnabled) return false

    const result = await Updates.checkForUpdateAsync()
    if (!result.isAvailable) return false

    await Updates.fetchUpdateAsync()
    await Updates.reloadAsync()
    return true
  } catch {
    return false
  }
}

export function otaChannelHint(): string {
  const channel =
    (Constants.expoConfig?.extra as { eas?: { channel?: string } } | undefined)?.eas?.channel ||
    "production"
  return String(channel)
}
