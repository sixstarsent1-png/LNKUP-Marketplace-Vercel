"use client"

import { useEffect } from "react"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

export function VendorsCtaTracker() {
  useEffect(() => {
    const join = document.querySelector('[data-testid="join-vendor-cta"]')
    const browse = document.querySelector('[data-testid="browse-opportunities-cta"]')

    function onJoin() {
      trackLnkupEvent({ event: "landing_cta_clicked", source: "vendors", metadata: { cta: "join" } })
    }
    function onBrowse() {
      trackLnkupEvent({ event: "browse_opportunities_clicked", source: "vendors" })
    }

    join?.addEventListener("click", onJoin)
    browse?.addEventListener("click", onBrowse)
    return () => {
      join?.removeEventListener("click", onJoin)
      browse?.removeEventListener("click", onBrowse)
    }
  }, [])

  return null
}
