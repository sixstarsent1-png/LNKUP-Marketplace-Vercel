import Image from "next/image"
import Link from "next/link"
import { Calendar, MapPin, Info } from "lucide-react"
import { type VendorRequest, statusLabel, statusVariant } from "@/lib/data"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/marketplace/badges"

export function RequestCard({ request }: { request: VendorRequest }) {
  return (
    <Card className="flex-row items-center gap-3 rounded-2xl p-3">
      <div className="relative size-16 shrink-0 overflow-hidden rounded-xl">
        <Image
          src={request.eventImage || "/placeholder.svg"}
          alt={request.eventName}
          fill
          className="object-cover"
        />
      </div>

      <div className="flex min-w-0 flex-1 flex-col gap-1">
        <div className="flex items-start justify-between gap-2">
          <h3 className="truncate text-sm font-semibold">{request.eventName}</h3>
          <StatusBadge variant={statusVariant(request.status)}>
            {statusLabel(request.status)}
          </StatusBadge>
        </div>
        <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-muted-foreground">
          <span className="inline-flex items-center gap-1">
            <Calendar className="size-3" />
            {request.date}
          </span>
          <span className="inline-flex items-center gap-1">
            <MapPin className="size-3" />
            {request.city}
          </span>
        </div>
        {request.reason && (
          <p className="inline-flex items-start gap-1 text-xs text-muted-foreground">
            <Info className="mt-0.5 size-3 shrink-0" />
            {request.reason}
          </p>
        )}

        <div className="mt-1 flex items-center justify-between gap-2">
          <span className="text-sm font-heading font-bold">${request.vendorFee}</span>
          <RequestCta request={request} />
        </div>
      </div>
    </Card>
  )
}

function RequestCta({ request }: { request: VendorRequest }) {
  switch (request.status) {
    case "payment-due":
      return <Button size="sm">Pay now</Button>
    case "paid":
      return (
        <Button size="sm" variant="outline" nativeButton={false} render={<Link href={`/vendor/pass/${request.eventId}`} />}>
          View pass
        </Button>
      )
    case "approved":
      return <Button size="sm">Complete payment</Button>
    case "rejected":
    case "waitlisted":
      return (
        <Button size="sm" variant="ghost">
          See reason
        </Button>
      )
    default:
      return (
        <Button size="sm" variant="outline">
          View
        </Button>
      )
  }
}
