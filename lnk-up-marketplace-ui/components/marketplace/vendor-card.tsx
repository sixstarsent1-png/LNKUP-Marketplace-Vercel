import { Star, MapPin, CheckCircle2, Plus } from "lucide-react"
import type { Vendor } from "@/lib/data"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { VerificationBadge } from "@/components/marketplace/badges"

export function VendorCard({ vendor }: { vendor: Vendor }) {
  return (
    <Card className="gap-3 rounded-2xl p-4">
      <div className="flex items-start gap-3">
        <Avatar className="size-12 rounded-xl">
          <AvatarFallback className="rounded-xl bg-primary font-heading font-semibold text-primary-foreground">
            {vendor.initials}
          </AvatarFallback>
        </Avatar>
        <div className="flex flex-1 flex-col gap-1">
          <div className="flex items-center gap-1.5">
            <h3 className="text-base font-semibold leading-tight">{vendor.name}</h3>
            {vendor.verified && <CheckCircle2 className="size-4 text-accent-foreground" />}
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <Star className="size-3.5 fill-accent text-accent" />
              {vendor.rating} ({vendor.reviews})
            </span>
            <span className="inline-flex items-center gap-1">
              <MapPin className="size-3.5" />
              {vendor.city}, {vendor.state}
            </span>
          </div>
          <span className="text-sm text-muted-foreground">
            {vendor.completedEvents} events completed
          </span>
        </div>
      </div>

      <div className="flex flex-wrap gap-1.5">
        <Badge variant="secondary">{vendor.category}</Badge>
        {vendor.insured && <VerificationBadge label="Insured" type="shield" />}
        {vendor.documentsVerified && <VerificationBadge label="Docs" type="doc" />}
      </div>

      <div className="flex gap-2">
        <Button variant="outline" className="flex-1">
          View profile
        </Button>
        <Button className="flex-1">
          <Plus data-icon="inline-start" />
          Invite
        </Button>
      </div>
    </Card>
  )
}
