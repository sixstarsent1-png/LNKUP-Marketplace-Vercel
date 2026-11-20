import { Calendar, MapPin, Star } from "lucide-react"
import type { VendorCompletedEvent } from "@/lib/api/vendor-reputation"
import { Card, CardContent } from "@/components/ui/card"
import { StatusBadge } from "@/components/marketplace/badges"

function statusLabel(status: VendorCompletedEvent["status"]): string {
  switch (status) {
    case "reviewed":
      return "Review received"
    case "review_pending":
      return "Review pending"
    case "completed":
      return "Completed"
    default:
      return "Event"
  }
}

function statusVariant(
  status: VendorCompletedEvent["status"],
): "paid" | "review" | "due" {
  if (status === "reviewed") return "paid"
  if (status === "review_pending") return "due"
  return "review"
}

export function CompletedEventCard({ event }: { event: VendorCompletedEvent }) {
  const location = [event.city, event.state].filter(Boolean).join(", ")

  return (
    <Card className="rounded-2xl" data-testid="vendor-completed-event-card">
      <CardContent className="flex flex-col gap-2 p-4">
        <div className="flex items-start justify-between gap-2">
          <h3 className="font-medium">{event.title}</h3>
          <StatusBadge variant={statusVariant(event.status)}>
            {statusLabel(event.status)}
          </StatusBadge>
        </div>
        <div className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
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
        </div>
        {event.organizerName && (
          <p className="text-xs text-muted-foreground">Organizer · {event.organizerName}</p>
        )}
        {typeof event.rating === "number" && (
          <p className="inline-flex items-center gap-1 text-xs">
            <Star className="size-3 fill-accent text-accent" />
            {event.rating} organizer rating
          </p>
        )}
        {event.badgeEarned && (
          <p className="text-xs text-muted-foreground">Badge earned · {event.badgeEarned}</p>
        )}
      </CardContent>
    </Card>
  )
}
