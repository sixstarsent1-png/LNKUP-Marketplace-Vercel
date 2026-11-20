"use client"

import { useEffect, useMemo } from "react"
import { MapContainer, TileLayer, Marker, Popup, useMap } from "react-leaflet"
import L from "leaflet"
import type { MarketplaceMapPin } from "@/lib/api/marketplace-discovery"
import "leaflet/dist/leaflet.css"

const defaultIcon = L.icon({
  iconUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon.png",
  iconRetinaUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-icon-2x.png",
  shadowUrl: "https://unpkg.com/leaflet@1.9.4/dist/images/marker-shadow.png",
  iconSize: [25, 41],
  iconAnchor: [12, 41],
  popupAnchor: [1, -34],
  shadowSize: [41, 41],
})

function FitBounds({ pins }: { pins: MarketplaceMapPin[] }) {
  const map = useMap()
  useEffect(() => {
    if (pins.length === 0) return
    const bounds = L.latLngBounds(pins.map((p) => [p.lat, p.lng]))
    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 12 })
  }, [map, pins])
  return null
}

export function DiscoveryMapLeaflet({
  pins,
  onSelect,
  className,
}: {
  pins: MarketplaceMapPin[]
  onSelect: (pin: MarketplaceMapPin) => void
  className?: string
}) {
  const center = useMemo(() => {
    if (pins.length === 0) return { lat: 35.2271, lng: -80.8431 }
    const lat = pins.reduce((s, p) => s + p.lat, 0) / pins.length
    const lng = pins.reduce((s, p) => s + p.lng, 0) / pins.length
    return { lat, lng }
  }, [pins])

  return (
    <MapContainer
      center={[center.lat, center.lng]}
      zoom={10}
      className={className}
      scrollWheelZoom={false}
    >
      <TileLayer
        attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
      />
      <FitBounds pins={pins} />
      {pins.map((pin) => (
        <Marker
          key={pin.id}
          position={[pin.lat, pin.lng]}
          icon={defaultIcon}
          eventHandlers={{
            click: () => onSelect(pin),
          }}
        >
          <Popup>
            <button type="button" className="text-left text-sm font-medium" onClick={() => onSelect(pin)}>
              {pin.title}
            </button>
          </Popup>
        </Marker>
      ))}
    </MapContainer>
  )
}
