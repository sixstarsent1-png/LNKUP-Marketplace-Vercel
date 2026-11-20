"use client"

import { useEffect } from "react"
import { Download, X } from "lucide-react"
import { usePwaInstallPrompt } from "@/lib/hooks/use-pwa-install-prompt"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Button } from "@/components/ui/button"

export function PwaInstallNudge({ source = "pwa_nudge" }: { source?: string }) {
  const { canShow, isStandalone, dismiss, promptInstall, hasNativePrompt } = usePwaInstallPrompt()

  useEffect(() => {
    if (canShow && !isStandalone) {
      trackLnkupEvent({ event: "pwa_install_prompt_shown", source })
    }
  }, [canShow, isStandalone, source])

  if (isStandalone || !canShow) return null

  async function handleInstall() {
    trackLnkupEvent({ event: "pwa_install_clicked", source })
    await promptInstall()
  }

  function handleDismiss() {
    trackLnkupEvent({ event: "pwa_install_dismissed", source })
    dismiss()
  }

  return (
    <Alert className="rounded-2xl border-accent/30 bg-accent/5" data-testid="pwa-install-nudge">
      <Download />
      <AlertTitle>Install LNKup for faster access</AlertTitle>
      <AlertDescription className="flex flex-wrap items-center justify-between gap-3">
        <span>Get quick access to your events, passes, and notifications.</span>
        <div className="flex gap-2">
          <Button size="sm" onClick={handleInstall} disabled={!hasNativePrompt && process.env.NODE_ENV === "production"}>
            Install app
          </Button>
          <Button size="sm" variant="ghost" onClick={handleDismiss} data-testid="pwa-install-dismiss">
            <X className="size-4" />
            Dismiss
          </Button>
        </div>
      </AlertDescription>
    </Alert>
  )
}
