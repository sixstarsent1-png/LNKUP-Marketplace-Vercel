"use client"

import { useEffect } from "react"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

export function OrganizersCtaTracker() {
  useEffect(() => {
    trackLnkupEvent({ event: "organizer_landing_viewed", source: "organizers_landing" })
  }, [])
  return null
}
