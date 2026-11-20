import { Linking } from "react-native"

import { showToast } from "@/lib/feedback/feedback"

/**
 * Open an external URL after a canOpenURL check; explains failures with a toast.
 */
export async function openExternalUrl(url: string, label = "this link"): Promise<boolean> {
  const trimmed = (url || "").trim()
  if (!trimmed) {
    showToast(`${label.charAt(0).toUpperCase()}${label.slice(1)} isn't available right now.`, "info")
    return false
  }
  try {
    const can = await Linking.canOpenURL(trimmed)
    if (!can) {
      showToast(`Couldn't open ${label}.`, "error")
      return false
    }
    await Linking.openURL(trimmed)
    return true
  } catch {
    showToast(`Couldn't open ${label}.`, "error")
    return false
  }
}
