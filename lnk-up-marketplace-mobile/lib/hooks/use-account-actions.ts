import { router } from "expo-router"

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

/** Double-tap-safe switch between the vendor and organizer shells for dual-role accounts. */
export function useSwitchRole(): [(role: AppHomeRole) => Promise<void>, boolean] {
  const { setHomeRole } = useAuth()
  const [run, busy] = useSingleFlight(async (role: AppHomeRole) => {
    try {
      await setHomeRole(role)
      router.replace(role === "organizer" ? "/(organizer)" : "/(vendor)")
    } catch {
      showToast("Couldn't switch workspace. Try again.", "error")
    }
  })
  return [async (role) => void (await run(role)), busy]
}
