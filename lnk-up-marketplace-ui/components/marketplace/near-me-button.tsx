"use client"

import { useState } from "react"
import { MapPin } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import type { MarketplaceSearchFilters } from "@/lib/api/marketplace-discovery"

const DEFAULT_RADIUS = 50

export function NearMeButton({
  onLocation,
  onCityFallback,
}: {
  onLocation: (lat: number, lng: number, radiusMiles: number) => void
  onCityFallback: (city: string) => void
}) {
  const [fallbackOpen, setFallbackOpen] = useState(false)
  const [cityInput, setCityInput] = useState("")
  const [loading, setLoading] = useState(false)

  function handleNearMe() {
    trackLnkupEvent({ event: "near_me_clicked", source: "vendor_find" })

    if (!navigator.geolocation) {
      trackLnkupEvent({ event: "near_me_denied", source: "vendor_find", metadata: { reason: "unsupported" } })
      setFallbackOpen(true)
      return
    }

    setLoading(true)
    navigator.geolocation.getCurrentPosition(
      (pos) => {
        setLoading(false)
        trackLnkupEvent({ event: "near_me_allowed", source: "vendor_find" })
        onLocation(pos.coords.latitude, pos.coords.longitude, DEFAULT_RADIUS)
      },
      () => {
        setLoading(false)
        trackLnkupEvent({ event: "near_me_denied", source: "vendor_find", metadata: { reason: "denied" } })
        setFallbackOpen(true)
      },
      { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 },
    )
  }

  function handleCitySubmit() {
    const city = cityInput.trim()
    if (!city) return
    onCityFallback(city)
    setFallbackOpen(false)
    setCityInput("")
  }

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={handleNearMe}
        disabled={loading}
        data-testid="near-me-button"
      >
        <MapPin data-icon="inline-start" />
        {loading ? "Locating…" : "Near me"}
      </Button>

      <Sheet open={fallbackOpen} onOpenChange={setFallbackOpen}>
        <SheetContent side="bottom" className="rounded-t-3xl" data-testid="near-me-fallback">
          <SheetHeader>
            <SheetTitle>Search by city</SheetTitle>
            <SheetDescription>
              Use your location to find nearby event opportunities. You can also search by city.
            </SheetDescription>
          </SheetHeader>
          <div className="flex flex-col gap-3 px-4 pb-6">
            <Input
              placeholder="Enter city name"
              value={cityInput}
              onChange={(e) => setCityInput(e.target.value)}
              data-testid="near-me-city-input"
            />
            <Button onClick={handleCitySubmit}>Search city</Button>
          </div>
        </SheetContent>
      </Sheet>
    </>
  )
}

export type { MarketplaceSearchFilters }
