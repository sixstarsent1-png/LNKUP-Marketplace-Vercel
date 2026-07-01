import Image from 'next/image'
import { MapPin, Users } from 'lucide-react'
import {
  Card,
  CardContent,
} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { StatusBadge } from '@/components/dashboard/status-badge'
import { events, currency } from '@/lib/mock-data'

export default function AdminEventsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          All events
        </h1>
        <p className="text-muted-foreground">
          Every opportunity listed across the platform
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {events.map((e) => (
          <Card key={e.id} className="overflow-hidden pt-0">
            <div className="relative h-36 w-full">
              <Image
                src={e.image || '/placeholder.svg'}
                alt={e.name}
                fill
                className="object-cover"
              />
              <div className="absolute right-3 top-3">
                <StatusBadge status="live" />
              </div>
            </div>
            <CardContent className="flex flex-col gap-3">
              <div className="flex flex-col gap-1">
                <h3 className="font-semibold leading-tight">{e.name}</h3>
                <p className="flex items-center gap-1 text-sm text-muted-foreground">
                  <MapPin className="size-3.5" />
                  {e.city}, {e.state} &middot; {e.date}
                </p>
              </div>
              <div className="flex items-center justify-between border-t border-border pt-3 text-sm">
                <span className="flex items-center gap-1 text-muted-foreground">
                  <Users className="size-4" />
                  {e.attendance.toLocaleString()}
                </span>
                <Badge variant="secondary">{e.organizer}</Badge>
                <span className="font-semibold">{currency(e.vendorFee)}</span>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  )
}
