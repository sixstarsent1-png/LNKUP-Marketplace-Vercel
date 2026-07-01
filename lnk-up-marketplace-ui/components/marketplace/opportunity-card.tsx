"use client"

import { useState } from "react"
import Image from "next/image"
import {
  Calendar,
  MapPin,
  Users,
  Bookmark,
  Ticket,
  Clock,
  Store,
} from "lucide-react"
import type { EventItem } from "@/lib/data"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { VerificationBadge } from "@/components/marketplace/badges"
import { ApplyModal } from "@/components/marketplace/apply-modal"
import { cn } from "@/lib/utils"

export function OpportunityCard({ event }: { event: EventItem }) {
  const [saved, setSaved] = useState(false)
  const almostFull = event.spotsRemaining > 0 && event.spotsRemaining <= 5
  const full = event.spotsRemaining === 0

  return (
    <Card className="gap-0 overflow-hidden rounded-2xl p-0">
      <div className="relative h-40 w-full">
        <Image
          src={event.image || "/placeholder.svg"}
          alt={event.name}
          fill
          className="object-cover"
        />
        <div className="absolute top-3 left-3 flex gap-2">
          {event.trending && (
            <Badge className="bg-accent text-accent-foreground">Trending</Badge>
          )}
          {event.organizerVerified && <VerificationBadge label="Verified" />}
        </div>
        <button
          type="button"
          onClick={() => setSaved((s) => !s)}
          aria-label={saved ? "Remove from saved" : "Save event"}
          className="absolute top-3 right-3 flex size-9 items-center justify-center rounded-full bg-background/90 text-foreground backdrop-blur transition-colors hover:bg-background"
        >
          <Bookmark className={cn("size-4", saved && "fill-foreground")} />
        </button>
      </div>

      <div className="flex flex-col gap-3 p-4">
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
          <div className="flex items-center gap-1 text-sm text-muted-foreground">
            <Store className="size-3.5" />
            {event.venue} · {event.organizer}
          </div>
        </div>

        <div className="flex items-center justify-between rounded-xl bg-muted px-3 py-2 text-sm">
          <span className="inline-flex items-center gap-1.5 text-muted-foreground">
            <Users className="size-4" />
            {event.expectedAttendance.toLocaleString()} expected
          </span>
          <span className="font-heading font-bold">${event.vendorFee} fee</span>
        </div>

        <div className="flex flex-wrap gap-1.5">
          {event.categoriesNeeded.slice(0, 3).map((c) => (
            <Badge key={c} variant="secondary">
              {c}
            </Badge>
          ))}
          {event.categoriesNeeded.length > 3 && (
            <Badge variant="outline">+{event.categoriesNeeded.length - 3}</Badge>
          )}
        </div>

        <div className="flex items-center justify-between text-xs">
          <span
            className={cn(
              "inline-flex items-center gap-1 font-medium",
              full ? "text-destructive" : almostFull ? "text-warning" : "text-muted-foreground",
            )}
          >
            <Ticket className="size-3.5" />
            {full ? "Fully booked" : `${event.spotsRemaining} of ${event.totalSpots} spots left`}
          </span>
          <span className="inline-flex items-center gap-1 text-muted-foreground">
            <Clock className="size-3.5" />
            Apply by {event.deadline}
          </span>
        </div>

        <div className="flex gap-2">
          <ApplyModal event={event}>
            <Button className="flex-1" disabled={full}>
              {full ? "Waitlist full" : "Apply"}
            </Button>
          </ApplyModal>
          <Button
            variant="outline"
            size="icon"
            aria-label="Save"
            onClick={() => setSaved((s) => !s)}
          >
            <Bookmark className={cn(saved && "fill-foreground")} />
          </Button>
        </div>
      </div>
    </Card>
  )
}
