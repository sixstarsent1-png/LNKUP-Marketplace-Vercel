"use client"

import Link from "next/link"
import { CalendarPlus, MapPin, Map, ExternalLink } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import type { VendorEventPass } from "@/lib/api/vendor-packet"
import { downloadCalendarIcs } from "@/lib/vendor/calendar-ics"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"

function buildDirectionsUrl(address: string): string {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`
}

export function VendorPassActions({ packet }: { packet: VendorEventPass }) {
  const locked = packet.status === "locked"
  const canCalendar = !locked && Boolean(packet.eventDate)
  const canDirections = !locked && Boolean(packet.venueAddress)
  const live = isLiveApiEnabled()

  const handleCalendar = () => {
    if (!canCalendar) return

    if (live) {
      window.location.href = `/api/vendor/pass/${packet.requestId}/calendar.ics`
    } else {
      downloadCalendarIcs(packet)
    }

    trackLnkupEvent({
      event: "vendor_calendar_added",
      source: "vendor_pass",
      requestId: packet.requestId,
    })
  }

  const handleDirections = () => {
    if (!canDirections || !packet.venueAddress) return
    trackLnkupEvent({
      event: "vendor_directions_clicked",
      source: "vendor_pass",
      requestId: packet.requestId,
    })
    window.open(buildDirectionsUrl(packet.venueAddress), "_blank", "noopener,noreferrer")
  }

  if (locked) return null

  return (
    <div className="flex flex-wrap gap-2" data-testid="vendor-pass-actions">
      <Button
        size="sm"
        variant="outline"
        onClick={handleCalendar}
        disabled={!canCalendar}
        data-testid="vendor-pass-calendar"
      >
        <CalendarPlus data-icon="inline-start" />
        Add to calendar
      </Button>

      <Button
        size="sm"
        variant="outline"
        onClick={handleDirections}
        disabled={!canDirections}
        data-testid="vendor-pass-directions"
      >
        <MapPin data-icon="inline-start" />
        Directions
      </Button>

      {packet.mapUrl && (
        <Button
          size="sm"
          variant="outline"
          nativeButton={false}
          render={
            <Link href={packet.mapUrl} target="_blank" rel="noopener noreferrer" data-testid="vendor-pass-map" />
          }
        >
          <Map data-icon="inline-start" />
          Event map
        </Button>
      )}

      {packet.wordpressUrl && (
        <Button
          size="sm"
          variant="ghost"
          nativeButton={false}
          render={<Link href={packet.wordpressUrl} target="_blank" rel="noopener noreferrer" />}
          onClick={() =>
            trackLnkupEvent({
              event: "wordpress_operations_handoff_clicked",
              source: "vendor_pass_actions",
              requestId: packet.requestId,
            })
          }
        >
          <ExternalLink data-icon="inline-start" />
          Open in LNKup
        </Button>
      )}
    </div>
  )
}
