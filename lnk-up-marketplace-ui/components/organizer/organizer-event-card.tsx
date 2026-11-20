"use client"

import Link from "next/link"
import { Calendar, MapPin, Users, ExternalLink } from "lucide-react"
import type { OrganizerEventLite } from "@/lib/api/organizer-events"
import { getOrganizerDashboardUrl } from "@/lib/api/config"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/marketplace/badges"

export function OrganizerEventCard({ event }: { event: OrganizerEventLite }) {
  const location = [event.city, event.state].filter(Boolean).join(", ")
  const wpBase = getOrganizerDashboardUrl()
  const operationsUrl =
    event.wordpressUrl ?? (wpBase ? `${wpBase}?view=operations&event=${event.id}` : undefined)

  return (
    <Card className="rounded-2xl" data-testid="organizer-event-card">
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 flex-col gap-0.5">
            <h3 className="truncate font-semibold">{event.title}</h3>
            {event.venue && <p className="text-xs text-muted-foreground">{event.venue}</p>}
          </div>
          {event.status && (
            <StatusBadge variant={event.status === "live" ? "live" : event.status === "closed" ? "closed" : "draft"}>
              {event.status === "live" ? "Live" : event.status === "closed" ? "Completed" : "Draft"}
            </StatusBadge>
          )}
        </div>

        <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
          {event.date && (
            <span className="inline-flex items-center gap-1">
              <Calendar className="size-3" />
              {event.date}
            </span>
          )}
          {location && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="size-3" />
              {location}
            </span>
          )}
          <span className="inline-flex items-center gap-1">
            <Users className="size-3" />
            {event.requestsCount ?? 0} requests · {event.paidCount ?? 0} booked
          </span>
        </div>

        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            nativeButton={false}
            render={<Link href={`/organizer/events/${event.id}`} />}
          >
            Manage event
          </Button>
          <Button
            size="sm"
            variant="outline"
            nativeButton={false}
            render={<Link href={`/organizer/vendors?event=${event.id}`} />}
          >
            View requests
          </Button>
          {operationsUrl && (
            <Button
              size="sm"
              variant="ghost"
              nativeButton={false}
              render={<Link href={operationsUrl} target="_blank" rel="noopener noreferrer" />}
            >
              Operations
              <ExternalLink data-icon="inline-end" className="size-3.5" />
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
