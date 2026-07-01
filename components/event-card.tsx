'use client'

import Image from 'next/image'
import { CalendarDays, MapPin, Users, Ticket } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { currency, type EventOpportunity } from '@/lib/mock-data'

export function EventCard({
  event,
  showCategories = false,
}: {
  event: EventOpportunity
  showCategories?: boolean
}) {
  const lowSpots = event.spotsRemaining <= 5

  return (
    <article className="group flex flex-col overflow-hidden rounded-2xl border border-border bg-card transition-all hover:-translate-y-1 hover:shadow-xl hover:shadow-foreground/5">
      <div className="relative aspect-[16/10] overflow-hidden">
        <Image
          src={event.image || '/placeholder.svg'}
          alt={event.name}
          fill
          sizes="(max-width: 768px) 100vw, 33vw"
          className="object-cover transition-transform duration-500 group-hover:scale-105"
        />
        <div className="absolute inset-x-0 top-0 flex items-start justify-between p-3">
          <Badge className="bg-background/90 text-foreground backdrop-blur">
            {currency(event.vendorFee)} vendor fee
          </Badge>
          {lowSpots && (
            <Badge className="bg-destructive text-white">
              {event.spotsRemaining} spots left
            </Badge>
          )}
        </div>
      </div>

      <div className="flex flex-1 flex-col p-5">
        <h3 className="font-display text-lg font-bold leading-tight">
          {event.name}
        </h3>

        <dl className="mt-3 grid gap-2 text-sm text-muted-foreground">
          <div className="flex items-center gap-2">
            <MapPin className="size-4 shrink-0 text-accent-foreground/60" />
            {event.city}, {event.state}
          </div>
          <div className="flex items-center gap-2">
            <CalendarDays className="size-4 shrink-0 text-accent-foreground/60" />
            {event.date}
          </div>
          <div className="flex items-center gap-2">
            <Users className="size-4 shrink-0 text-accent-foreground/60" />
            {event.attendance.toLocaleString()} expected attendance
          </div>
        </dl>

        {showCategories && (
          <div className="mt-3 flex flex-wrap gap-1.5">
            {event.categoriesNeeded.slice(0, 3).map((c) => (
              <Badge key={c} variant="secondary" className="font-normal">
                {c}
              </Badge>
            ))}
            {event.categoriesNeeded.length > 3 && (
              <Badge variant="secondary" className="font-normal">
                +{event.categoriesNeeded.length - 3}
              </Badge>
            )}
          </div>
        )}

        <div className="mt-auto flex items-center justify-between gap-3 pt-5">
          <span className="inline-flex items-center gap-1.5 text-sm text-muted-foreground">
            <Ticket className="size-4" />
            {event.spotsRemaining}/{event.totalSpots} spots
          </span>
          <Button
            size="sm"
            onClick={() =>
              toast.success('Application started', {
                description: `You're applying to ${event.name}. It's free to apply!`,
              })
            }
          >
            Apply
          </Button>
        </div>
      </div>
    </article>
  )
}
