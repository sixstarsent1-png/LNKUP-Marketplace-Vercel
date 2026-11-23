"use client"

import { useCallback, useState } from "react"
import { useRouter } from "next/navigation"
import { LogOut } from "lucide-react"
import { useAuth } from "@/lib/hooks/use-auth"

/** Ends the app session and returns to sign-in. */
export function SignOutButton({ className }: { className?: string }) {
  const router = useRouter()
  const { logout } = useAuth()
  const [isSigningOut, setIsSigningOut] = useState(false)

  const handleSignOut = useCallback(async () => {
    setIsSigningOut(true)
    try {
      await logout()
    } finally {
      router.replace("/login")
      router.refresh()
    }
  }, [logout, router])

  return (
    <button type="button" className={className} disabled={isSigningOut} onClick={handleSignOut}>
      <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-destructive/10">
        <LogOut className="size-4" />
      </span>
      <span className="flex-1 font-medium">{isSigningOut ? "Signing out…" : "Sign out"}</span>
    </button>
  )
}
