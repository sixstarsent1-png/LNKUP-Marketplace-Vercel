import { Share } from "react-native"

/**
 * Copy text without a top-level expo-clipboard import (OTA-safe).
 * Falls back to the native share sheet if the module is missing.
 */
export async function copyToClipboard(text: string): Promise<boolean> {
  if (!text) return false
  try {
    // Lazy require — do not import expo-clipboard at module top (OTA-safe).
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    const Clipboard = require("expo-clipboard") as {
      setStringAsync: (value: string) => Promise<void>
    }
    await Clipboard.setStringAsync(text)
    return true
  } catch {
    try {
      await Share.share({ message: text })
      return true
    } catch {
      return false
    }
  }
}
