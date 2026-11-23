import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react"
import { router } from "expo-router"
import { AppState, type AppStateStatus } from "react-native"

import {
  clearSession,
  isSessionExpired,
  loadSession,
  loginWithSitePassword,
  registerWithSitePassword,
  saveSession,
  sessionToAuth,
  type MobileSession,
  type RegisterPayload,
} from "@/lib/api/auth"
import { clearRequestCache } from "@/lib/api/request-cache"
import { setUnauthorizedHandler, wpFetchJson, type WpAuth } from "@/lib/api/wp-client"
import { loginErrorFromWp, registerErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  clearHomePreference,
  resolveHomeHref,
  saveHomePreference,
  sessionHasOrganizer,
  sessionHasPartner,
  sessionHasVendor,
  type AppHomeRole,
  type HomeHref,
} from "@/lib/auth/roles"
import { resetPendingDestinationForSignOut } from "@/lib/navigation/pending-destination"
import { clearPushRegistration } from "@/lib/push/notifications"

type AuthContextValue = {
  session: MobileSession | null
  auth: WpAuth | undefined
  isLoading: boolean
  isAuthenticated: boolean
  isOrganizer: boolean
  isVendor: boolean
  isPartner: boolean
  login: (username: string, password: string) => Promise<void>
  register: (payload: RegisterPayload) => Promise<void>
  enterDemoSession: (opts?: { displayName?: string; role?: AppHomeRole }) => Promise<void>
  setHomeRole: (role: AppHomeRole) => Promise<void>
  resolveHome: () => Promise<HomeHref>
  logout: () => Promise<void>
  lastError: AppErrorShape | null
  clearError: () => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [session, setSession] = useState<MobileSession | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [lastError, setLastError] = useState<AppErrorShape | null>(null)
  const sessionRef = useRef<MobileSession | null>(null)

  useEffect(() => {
    sessionRef.current = session
  }, [session])

  useEffect(() => {
    let active = true
    ;(async () => {
      const stored = await loadSession()
      if (active) {
        setSession(stored)
        setIsLoading(false)
      }
    })()
    return () => {
      active = false
    }
  }, [])

  const endSession = useCallback((message: string) => {
    const current = sessionRef.current
    if (!current) return
    sessionRef.current = null
    void (async () => {
      await clearPushRegistration(sessionToAuth(current)).catch(() => undefined)
      await clearSession()
      await clearHomePreference()
      clearRequestCache()
      setSession(null)
      setLastError({
        code: "auth_required",
        title: "Signed out",
        message,
        retryable: false,
        status: 401,
      })
      router.replace("/login")
    })()
  }, [])

  useEffect(() => {
    // Failed login also returns 401 with auth credentials — endSession ignores it (no session yet).
    setUnauthorizedHandler(() => endSession("Your session ended. Sign in again to continue."))
    return () => setUnauthorizedHandler(null)
  }, [endSession])

  useEffect(() => {
    const sub = AppState.addEventListener("change", (next: AppStateStatus) => {
      const current = sessionRef.current
      if (next === "active" && current && isSessionExpired(current)) {
        endSession("Your session expired. Sign in again to continue.")
      }
    })
    return () => sub.remove()
  }, [endSession])

  const login = useCallback(async (username: string, password: string) => {
    setLastError(null)
    try {
      const next = await loginWithSitePassword(username, password)
      setSession(next)
    } catch (error) {
      setLastError(loginErrorFromWp(error))
      throw error
    }
  }, [])

  const register = useCallback(async (payload: RegisterPayload) => {
    setLastError(null)
    try {
      const next = await registerWithSitePassword(payload)
      await saveHomePreference(payload.role)
      setSession(next)
    } catch (error) {
      setLastError(registerErrorFromWp(error))
      throw error
    }
  }, [])

  const enterDemoSession = useCallback(
    async (opts?: { displayName?: string; role?: AppHomeRole }) => {
      const role = opts?.role ?? "vendor"
      const isOrg = role === "organizer"
      const isPartnerDemo = role === "partner"
      const next: MobileSession = {
        username: isOrg ? "organizer_demo" : isPartnerDemo ? "partner_demo" : "vendor_demo",
        appPassword: "demo",
        token: undefined,
        user: {
          id: isOrg ? 1 : isPartnerDemo ? 2 : 0,
          name: opts?.displayName?.trim() || (isOrg ? "Organizer Demo" : isPartnerDemo ? "Partner Demo" : "Vendor Demo"),
          slug: isOrg ? "organizer-demo" : isPartnerDemo ? "partner-demo" : "vendor-demo",
          roles: isOrg ? ["lnkup_organizer"] : isPartnerDemo ? ["lnkup_connector"] : ["lnkup_vendor"],
        },
      }
      await saveSession(next)
      await saveHomePreference(role)
      setSession(next)
      setLastError(null)
    },
    [],
  )

  const setHomeRole = useCallback(async (role: AppHomeRole) => {
    await saveHomePreference(role)
  }, [])

  const resolveHome = useCallback(async (): Promise<HomeHref> => {
    if (!session) return "/(vendor)"
    return resolveHomeHref(session)
  }, [session])

  const logout = useCallback(async () => {
    const authSnapshot = session ? sessionToAuth(session) : undefined
    sessionRef.current = null
    await resetPendingDestinationForSignOut()
    if (authSnapshot?.token) {
      await wpFetchJson("/auth/logout", { method: "POST", auth: authSnapshot }).catch(() => undefined)
    }
    await clearPushRegistration(authSnapshot).catch(() => undefined)
    await clearSession()
    await clearHomePreference()
    clearRequestCache()
    setSession(null)
    setLastError(null)
  }, [session])

  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      auth: session ? sessionToAuth(session) : undefined,
      isLoading,
      isAuthenticated: Boolean(session),
      isOrganizer: sessionHasOrganizer(session),
      isVendor: sessionHasVendor(session),
      isPartner: sessionHasPartner(session),
      login,
      register,
      enterDemoSession,
      setHomeRole,
      resolveHome,
      logout,
      lastError,
      clearError: () => setLastError(null),
    }),
    [session, isLoading, login, register, enterDemoSession, setHomeRole, resolveHome, logout, lastError],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) {
    throw new Error("useAuth must be used within AuthProvider")
  }
  return ctx
}
