import Image from "next/image"
import Link from "next/link"
import { Calendar, MapPin, Users, ArrowRight } from "lucide-react"
import type { EventItem } from "@/lib/data"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/marketplace/badges"
import { ProgressRing } from "@/components/marketplace/progress-ring"

export function EventCard({ event }: { event: EventItem }) {
  return (
    <Card className="gap-0 overflow-hidden rounded-2xl p-0">
      <div className="relative h-32 w-full">
        <Image
          src={event.image || "/placeholder.svg"}
          alt={event.name}
          fill
          className="object-cover"
        />
        <div className="absolute top-3 left-3">
          <StatusBadge variant={event.status}>
            {event.status === "live" ? "Live" : event.status === "closed" ? "Completed" : "Draft"}
          </StatusBadge>
        </div>
      </div>

      <div className="flex flex-col gap-3 p-4">
        <div className="flex items-start justify-between gap-3">
          <div className="flex flex-col gap-1">
            <h3 className="text-pretty text-base font-semibold leading-tight">
              {event.name}
            </h3>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
              <span className="inline-flex items-center gap-1">
                <Calendar className="size-3.5" />
                {event.date}
              </span>
              <span className="inline-flex items-center gap-1">
                <MapPin className="size-3.5" />
                {event.city}, {event.state}
              </span>
            </div>
          </div>
          <ProgressRing value={event.readiness} />
        </div>

        <div className="grid grid-cols-3 gap-2 rounded-xl bg-muted p-3 text-center">
          <div>
            <div className="font-heading text-lg font-bold">{event.vendorRequests}</div>
            <div className="text-xs text-muted-foreground">Requests</div>
          </div>
          <div>
            <div className="font-heading text-lg font-bold">{event.paidVendors}</div>
            <div className="text-xs text-muted-foreground">Paid</div>
          </div>
          <div>
            <div className="font-heading text-lg font-bold">${(event.revenue / 1000).toFixed(1)}k</div>
            <div className="text-xs text-muted-foreground">Revenue</div>
          </div>
        </div>

        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span className="inline-flex items-center gap-1">
            <Users className="size-3.5" />
            {event.expectedAttendance.toLocaleString()} expected
          </span>
          <span>{event.approvedVendors} approved</span>
        </div>

        <Button className="w-full" nativeButton={false} render={<Link href={`/organizer/events/${event.id}`} />}>
          Manage event
          <ArrowRight data-icon="inline-end" />
        </Button>
      </div>
    </Card>
  )
}
