"use client"

import { useCallback, useEffect, useState } from "react"

const DISMISS_KEY = "lnkup_pwa_install_dismissed"

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: "accepted" | "dismissed" }>
}

export function usePwaInstallPrompt() {
  const [deferredPrompt, setDeferredPrompt] = useState<BeforeInstallPromptEvent | null>(null)
  const [dismissed, setDismissed] = useState(true)
  const [isStandalone, setIsStandalone] = useState(false)

  useEffect(() => {
    if (typeof window === "undefined") return

    const standalone =
      window.matchMedia("(display-mode: standalone)").matches ||
      (window.navigator as Navigator & { standalone?: boolean }).standalone === true
    setIsStandalone(standalone)

    const wasDismissed = localStorage.getItem(DISMISS_KEY) === "1"
    setDismissed(wasDismissed)

    function handleBeforeInstall(e: Event) {
      e.preventDefault()
      setDeferredPrompt(e as BeforeInstallPromptEvent)
    }

    window.addEventListener("beforeinstallprompt", handleBeforeInstall)
    return () => window.removeEventListener("beforeinstallprompt", handleBeforeInstall)
  }, [])

  const canShow = !isStandalone && !dismissed && (deferredPrompt !== null || process.env.NODE_ENV === "development")

  const dismiss = useCallback(() => {
    localStorage.setItem(DISMISS_KEY, "1")
    setDismissed(true)
  }, [])

  const promptInstall = useCallback(async () => {
    if (!deferredPrompt) return false
    await deferredPrompt.prompt()
    const choice = await deferredPrompt.userChoice
    setDeferredPrompt(null)
    return choice.outcome === "accepted"
  }, [deferredPrompt])

  const resetDismiss = useCallback(() => {
    localStorage.removeItem(DISMISS_KEY)
    setDismissed(false)
  }, [])

  return {
    canShow,
    isStandalone,
    dismissed,
    dismiss,
    promptInstall,
    resetDismiss,
    hasNativePrompt: deferredPrompt !== null,
  }
}
