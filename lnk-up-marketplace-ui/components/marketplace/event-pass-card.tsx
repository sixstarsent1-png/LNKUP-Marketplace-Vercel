import {
  Lock,
  QrCode,
  Clock,
  MapPin,
  Car,
  Store,
  Phone,
  CheckCircle2,
} from "lucide-react"
import type { EventItem } from "@/lib/data"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"

export function EventPassCard({
  event,
  locked,
}: {
  event: EventItem
  locked: boolean
}) {
  if (locked) {
    return (
      <Card className="items-center gap-4 rounded-3xl py-10 text-center">
        <span className="flex size-16 items-center justify-center rounded-full bg-muted">
          <Lock className="size-7 text-muted-foreground" />
        </span>
        <div className="flex flex-col gap-1 px-6">
          <h3 className="text-lg font-semibold">Your vendor pass unlocks after payment</h3>
          <p className="text-pretty text-sm text-muted-foreground">
            Complete payment to unlock load-in details, booth assignment, maps, parking, and your
            check-in pass.
          </p>
        </div>
        <Button size="lg">Pay ${event.vendorFee} to unlock</Button>
      </Card>
    )
  }

  return (
    <div className="flex flex-col gap-4">
      <Card className="gap-0 overflow-hidden rounded-3xl bg-primary p-0 text-primary-foreground">
        <div className="flex items-center gap-2 px-5 pt-5 text-sm font-medium text-accent">
          <CheckCircle2 className="size-4" />
          You&apos;re booked — your event pass is ready.
        </div>
        <div className="flex flex-col items-center gap-4 p-5">
          <div className="flex size-44 items-center justify-center rounded-2xl bg-primary-foreground p-3">
            <QrCode className="size-full text-primary" strokeWidth={1} />
          </div>
          <div className="text-center">
            <h3 className="font-heading text-lg font-bold">{event.name}</h3>
            <p className="text-sm text-primary-foreground/70">
              {event.date} · {event.venue}
            </p>
          </div>
          <span className="rounded-full bg-accent px-3 py-1 text-xs font-semibold text-accent-foreground">
            Booth {event.city.slice(0, 1)}-14 · Vendor Pass
          </span>
        </div>
      </Card>

      <Card className="rounded-2xl">
        <CardHeader>
          <CardTitle className="text-base">Load-in details</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <PassRow icon={Clock} label="Load-in time" value="2:00 PM – 3:30 PM" />
          <Separator />
          <PassRow icon={Store} label="Booth assignment" value={`Booth ${event.city.slice(0, 1)}-14 (10x10)`} />
          <Separator />
          <PassRow icon={Car} label="Parking" value="Vendor Lot C · Pass on dashboard" />
          <Separator />
          <PassRow icon={MapPin} label="Venue address" value={`${event.venue}, ${event.city}, ${event.state}`} />
          <Separator />
          <PassRow icon={Phone} label="Organizer contact" value="ops@culturewave.co · (704) 555-0142" />
        </CardContent>
      </Card>

      <Card className="rounded-2xl">
        <CardHeader>
          <CardTitle className="text-base">Vendor rules</CardTitle>
        </CardHeader>
        <CardContent className="flex flex-col gap-2 text-sm text-muted-foreground">
          <p>· Arrive during your assigned load-in window. Late arrivals may forfeit their booth.</p>
          <p>· Bring your own tent, tables, and weights. Generators must be quiet-rated.</p>
          <p>· Breakdown begins at close — do not pack up early.</p>
        </CardContent>
      </Card>

      <div className="overflow-hidden rounded-2xl border">
        <div className="flex h-40 items-center justify-center bg-muted text-sm text-muted-foreground">
          <MapPin className="mr-2 size-4" />
          Venue map preview
        </div>
      </div>
    </div>
  )
}

function PassRow({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Clock
  label: string
  value: string
}) {
  return (
    <div className="flex items-start gap-3">
      <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-accent">
        <Icon className="size-4 text-accent-foreground" />
      </span>
      <div className="flex flex-col">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="text-sm font-medium">{value}</span>
      </div>
    </div>
  )
}
