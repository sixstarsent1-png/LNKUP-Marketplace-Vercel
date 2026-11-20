"use client"

import type { MarketplaceMapPin } from "@/lib/api/marketplace-discovery"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { MapPin, Ticket } from "lucide-react"

export function MapPinPreview({
  pin,
  onApply,
  onClose,
}: {
  pin: MarketplaceMapPin
  onApply?: () => void
  onClose?: () => void
}) {
  return (
    <Card className="w-64 gap-2 rounded-2xl p-3 shadow-lg" data-testid="map-pin-preview">
      <p className="font-semibold leading-tight">{pin.title}</p>
      <p className="flex items-center gap-1 text-xs text-muted-foreground">
        <MapPin className="size-3" />
        {[pin.city, pin.state].filter(Boolean).join(", ")}
      </p>
      {pin.eventDate && <p className="text-xs text-muted-foreground">{pin.eventDate}</p>}
      <div className="flex items-center justify-between gap-2">
        {pin.vendorFee !== undefined && (
          <Badge variant="outline">${pin.vendorFee} fee</Badge>
        )}
        {pin.category && <Badge variant="secondary">{pin.category}</Badge>}
      </div>
      <div className="flex gap-2 pt-1">
        <Button size="sm" className="flex-1" onClick={onApply}>
          <Ticket data-icon="inline-start" />
          Apply
        </Button>
        {onClose && (
          <Button size="sm" variant="outline" onClick={onClose}>
            Close
          </Button>
        )}
      </div>
    </Card>
  )
}
