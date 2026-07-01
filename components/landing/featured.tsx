import Link from 'next/link'
import { ArrowRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { EventCard } from '@/components/event-card'
import { events } from '@/lib/mock-data'

export function Featured() {
  return (
    <section id="opportunities" className="border-y border-border bg-muted/40">
      <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 lg:py-24">
        <div className="flex flex-col items-start justify-between gap-4 sm:flex-row sm:items-end">
          <div className="max-w-2xl">
            <p className="text-sm font-semibold uppercase tracking-wider text-accent-foreground/70">
              Featured opportunities
            </p>
            <h2 className="mt-3 text-pretty font-display text-3xl font-extrabold tracking-tight sm:text-4xl">
              Events booking vendors right now
            </h2>
          </div>
          <Button
            variant="outline"
            render={<Link href="/dashboard/find-events" />}
            nativeButton={false}
          >
            View all events
            <ArrowRight data-icon="inline-end" />
          </Button>
        </div>

        <div className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
          {events.slice(0, 6).map((event) => (
            <EventCard key={event.id} event={event} showCategories />
          ))}
        </div>
      </div>
    </section>
  )
}
