import { router } from "expo-router"

import { clearRequestCache } from "@/lib/api/request-cache"
import { useAuth } from "@/lib/auth/auth-context"
import type { AppHomeRole } from "@/lib/auth/roles"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { useSingleFlight } from "@/lib/hooks/use-single-flight"

/** Confirmed, double-tap-safe sign out shared by vendor and organizer screens. */
export function useSignOut(): [() => Promise<void>, boolean] {
  const { logout } = useAuth()
  const [run, busy] = useSingleFlight(async () => {
    const ok = await confirmAction({
      title: "Sign out?",
      message: "You'll need your LNKup password to sign back in on this device.",
      confirmLabel: "Sign out",
      destructive: true,
    })
    if (!ok) return
    await logout()
    router.replace("/login")
  })
  return [async () => void (await run()), busy]
}

const WORKSPACE_LABEL: Record<AppHomeRole, string> = { vendor: "vendor", organizer: "organizer", partner: "partner" }

const SHELL_FOR: Record<AppHomeRole, "/(vendor)" | "/(organizer)" | "/(partner)"> = {
  vendor: "/(vendor)",
  organizer: "/(organizer)",
  partner: "/(partner)",
}

/** Double-tap-safe switch between the vendor, organizer and partner shells for multi-role accounts. */
export function useSwitchRole(): [(role: AppHomeRole) => Promise<void>, boolean] {
  const { setHomeRole } = useAuth()
  const [run, busy] = useSingleFlight(async (role: AppHomeRole) => {
    try {
      await setHomeRole(role)
      // Each workspace loads its own records; never show one workspace's cached lists in another.
      clearRequestCache()
      router.replace(SHELL_FOR[role])
      showToast(`Switched to your ${WORKSPACE_LABEL[role]} workspace.`, "success")
    } catch {
      showToast("Couldn't switch workspace. Try again.", "error")
    }
  })
  return [async (role) => void (await run(role)), busy]
}
