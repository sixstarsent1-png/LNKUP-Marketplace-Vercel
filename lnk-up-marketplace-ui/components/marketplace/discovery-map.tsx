"use client"

import { useEffect, useState } from "react"
import type { MarketplaceMapPin } from "@/lib/api/marketplace-discovery"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { MapPinPreview } from "@/components/marketplace/map-pin-preview"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"

type DiscoveryMapInnerProps = {
  city?: string
  state?: string
  onPinClick?: (pin: MarketplaceMapPin) => void
  className?: string
}

export function DiscoveryMapInner({ city, state, onPinClick, className }: DiscoveryMapInnerProps) {
  const [pins, setPins] = useState<MarketplaceMapPin[]>([])
  const [loading, setLoading] = useState(true)
  const [unavailable, setUnavailable] = useState(false)
  const [selected, setSelected] = useState<MarketplaceMapPin | null>(null)
  const [MapComponent, setMapComponent] = useState<React.ComponentType<{
    pins: MarketplaceMapPin[]
    onSelect: (pin: MarketplaceMapPin) => void
    className?: string
  }> | null>(null)

  useEffect(() => {
    void import("@/components/marketplace/discovery-map-leaflet").then((mod) => {
      setMapComponent(() => mod.DiscoveryMapLeaflet)
    })
  }, [])

  useEffect(() => {
    setLoading(true)
    const params = new URLSearchParams()
    if (city) params.set("city", city)
    if (state) params.set("state", state)

    fetch(`/api/marketplace/map-pins?${params}`)
      .then((r) => r.json())
      .then((data) => {
        const list = data.pins ?? []
        setPins(list)
        setUnavailable(Boolean(data.unavailable) && list.length === 0)
      })
      .catch(() => setUnavailable(true))
      .finally(() => setLoading(false))
  }, [city, state])

  function handleSelect(pin: MarketplaceMapPin) {
    trackLnkupEvent({
      event: "map_pin_clicked",
      source: "vendor_find",
      opportunityId: pin.opportunityId,
    })
    setSelected(pin)
    onPinClick?.(pin)
  }

  if (loading) {
    return (
      <div className={className} data-testid="discovery-map-loading">
        <SkeletonList count={1} />
      </div>
    )
  }

  if (unavailable || pins.length === 0) {
    return (
      <div
        className={`flex min-h-[280px] items-center justify-center rounded-2xl border border-dashed bg-muted/30 p-6 text-center text-sm text-muted-foreground ${className ?? ""}`}
        data-testid="discovery-map-unavailable"
      >
        Map view is not available yet. List view still works.
      </div>
    )
  }

  if (!MapComponent) {
    return <SkeletonList count={1} />
  }

  return (
    <div className={`relative ${className ?? ""}`} data-testid="discovery-map">
      <MapComponent pins={pins} onSelect={handleSelect} className="h-[320px] w-full rounded-2xl overflow-hidden" />
      {selected && (
        <div className="absolute bottom-4 left-4 right-4 z-[1000] md:left-auto md:right-4 md:w-72">
          <MapPinPreview
            pin={selected}
            onClose={() => setSelected(null)}
            onApply={() => onPinClick?.(selected)}
          />
        </div>
      )}
    </div>
  )
}

export function DiscoveryMap(props: DiscoveryMapInnerProps) {
  return <DiscoveryMapInner {...props} />
}
