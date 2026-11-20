"use client"

import { useCallback, useEffect, useState } from "react"
import type { AppUserRole } from "@/lib/auth/app-user"

export type AuthUser = {
  id?: number
  name: string
  username: string
  email?: string
  roles: AppUserRole[]
  primaryRole: AppUserRole
  canAccessVendor?: boolean
  canAccessOrganizer?: boolean
  canAccessAdmin?: boolean
}

export function useAuth() {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = useCallback(async () => {
    setLoading(true)
    try {
      const response = await fetch("/api/auth/me")
      const data = await response.json()
      if (data.authenticated && data.user) {
        setUser(data.user as AuthUser)
      } else {
        setUser(null)
      }
    } catch {
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
  }, [refresh])

  const logout = useCallback(async () => {
    await fetch("/api/auth/logout", { method: "POST" })
    setUser(null)
  }, [])

  return {
    user,
    loading,
    refresh,
    logout,
    isAuthenticated: Boolean(user),
    primaryRole: user?.primaryRole,
    canAccessVendor: user?.canAccessVendor ?? false,
    canAccessOrganizer: user?.canAccessOrganizer ?? false,
    canAccessAdmin: user?.canAccessAdmin ?? false,
  }
}
