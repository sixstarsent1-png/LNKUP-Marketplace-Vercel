import { useEffect, useState } from "react"
import { Redirect } from "expo-router"

import { useAuth } from "@/lib/auth/auth-context"
import type { HomeHref } from "@/lib/auth/roles"

export default function Index() {
  const { isAuthenticated, isLoading, resolveHome } = useAuth()
  const [href, setHref] = useState<HomeHref | "/login" | null>(null)

  useEffect(() => {
    let active = true
    ;(async () => {
      if (isLoading) return
      if (!isAuthenticated) {
        if (active) setHref("/login")
        return
      }
      const home = await resolveHome()
      if (active) setHref(home)
    })()
    return () => {
      active = false
    }
  }, [isAuthenticated, isLoading, resolveHome])

  if (!href) return null
  return <Redirect href={href} />
}
