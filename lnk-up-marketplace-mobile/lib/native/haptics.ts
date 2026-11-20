import { Platform } from "react-native"

type HapticsModule = {
  notificationAsync: (type: unknown) => Promise<void>
  impactAsync: (style: unknown) => Promise<void>
  NotificationFeedbackType: { Success: unknown; Warning: unknown; Error: unknown }
  ImpactFeedbackStyle: { Light: unknown }
}

let cached: HapticsModule | null | undefined

/**
 * expo-haptics ships in the 1.1.0 binary. Required lazily so an update that reaches an older
 * binary without the module does nothing instead of crashing at launch.
 */
function haptics(): HapticsModule | null {
  if (cached !== undefined) return cached
  if (Platform.OS === "web") {
    cached = null
    return cached
  }
  try {
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    cached = require("expo-haptics") as HapticsModule
  } catch {
    cached = null
  }
  return cached
}

export function hapticFeedback(kind: "success" | "warning" | "error" | "tap"): void {
  const mod = haptics()
  if (!mod) return
  const run =
    kind === "tap"
      ? mod.impactAsync(mod.ImpactFeedbackStyle.Light)
      : mod.notificationAsync(
          kind === "success"
            ? mod.NotificationFeedbackType.Success
            : kind === "warning"
              ? mod.NotificationFeedbackType.Warning
              : mod.NotificationFeedbackType.Error,
        )
  void run.catch(() => undefined)
}
