"use client"

import { useState } from "react"
import { Star, MapPin, CheckCircle2, Check, X, Clock } from "lucide-react"
import { type IncomingRequest, statusLabel, statusVariant } from "@/lib/data"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { StatusBadge } from "@/components/marketplace/badges"

export function IncomingRequestCard({ request }: { request: IncomingRequest }) {
  const [decision, setDecision] = useState<"approved" | "waitlisted" | "rejected" | null>(
    request.status === "under-review" ? null : (request.status as "approved"),
  )

  return (
    <Card className="gap-3 rounded-2xl p-4">
      <div className="flex items-start gap-3">
        <Avatar className="size-12 rounded-xl">
          <AvatarFallback className="rounded-xl bg-primary font-heading font-semibold text-primary-foreground">
            {request.initials}
          </AvatarFallback>
        </Avatar>
        <div className="flex flex-1 flex-col gap-1">
          <div className="flex items-start justify-between gap-2">
            <div className="flex items-center gap-1.5">
              <h3 className="text-base font-semibold leading-tight">{request.vendorName}</h3>
              {request.verified && <CheckCircle2 className="size-4 text-accent-foreground" />}
            </div>
            {decision && (
              <StatusBadge variant={statusVariant(decision)}>{statusLabel(decision)}</StatusBadge>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-1">
              <Star className="size-3.5 fill-accent text-accent" />
              {request.rating}
            </span>
            <span className="inline-flex items-center gap-1">
              <MapPin className="size-3.5" />
              {request.city}
            </span>
            <span>{request.completedEvents} events</span>
          </div>
        </div>
      </div>

      <Badge variant="secondary" className="w-fit">
        {request.category}
      </Badge>

      <p className="rounded-xl bg-muted p-3 text-sm text-muted-foreground">
        &ldquo;{request.message}&rdquo;
      </p>

      {decision === null ? (
        <div className="flex gap-2">
          <Button variant="outline" className="flex-1" onClick={() => setDecision("rejected")}>
            <X data-icon="inline-start" />
            Decline
          </Button>
          <Button variant="outline" className="flex-1" onClick={() => setDecision("waitlisted")}>
            <Clock data-icon="inline-start" />
            Waitlist
          </Button>
          <Button className="flex-1" onClick={() => setDecision("approved")}>
            <Check data-icon="inline-start" />
            Approve
          </Button>
        </div>
      ) : (
        <Button variant="ghost" size="sm" className="w-fit" onClick={() => setDecision(null)}>
          Undo decision
        </Button>
      )}
    </Card>
  )
}
