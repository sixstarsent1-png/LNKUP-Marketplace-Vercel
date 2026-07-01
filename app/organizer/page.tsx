import Link from 'next/link'
import Image from 'next/image'
import {
  ArrowRight,
  CalendarDays,
  DollarSign,
  Inbox,
  Users,
} from 'lucide-react'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Progress } from '@/components/ui/progress'
import { StatCard } from '@/components/dashboard/stat-card'
import { StatusBadge } from '@/components/dashboard/status-badge'
import { organizerEvents, currency } from '@/lib/mock-data'

const statusMap = {
  Open: 'live',
  Filling: 'pending',
  Full: 'approved',
  Past: 'closed',
} as const

export default function OrganizerOverview() {
  const totalRevenue = organizerEvents.reduce((s, e) => s + e.revenue, 0)
  const newApps = organizerEvents.reduce((s, e) => s + e.newApplications, 0)
  const filled = organizerEvents.reduce((s, e) => s + e.spotsFilled, 0)

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div className="flex flex-col gap-1">
          <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
            Good morning, Jasmine
          </h1>
          <p className="text-muted-foreground">
            Here&apos;s how your events are performing
          </p>
        </div>
        <Button render={<Link href="/organizer/create" />} nativeButton={false}>
          Create opportunity
          <ArrowRight data-icon="inline-end" />
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Active events"
          value={organizerEvents.length.toString()}
          icon={CalendarDays}
          change="2 filling fast"
          trend="up"
        />
        <StatCard
          label="New applications"
          value={newApps.toString()}
          icon={Inbox}
          change="Needs review"
          trend="up"
        />
        <StatCard
          label="Vendors booked"
          value={filled.toString()}
          icon={Users}
          change="+12 this week"
          trend="up"
        />
        <StatCard
          label="Revenue"
          value={currency(totalRevenue)}
          icon={DollarSign}
          change="+24% vs last"
          trend="up"
        />
      </div>

      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <h2 className="font-display text-xl font-bold tracking-tight">
            Your events
          </h2>
          <Button
            variant="ghost"
            size="sm"
            render={<Link href="/organizer/applications" />}
            nativeButton={false}
          >
            Review applications
            <ArrowRight data-icon="inline-end" />
          </Button>
        </div>

        <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
          {organizerEvents.map((event) => {
            const pct = Math.round((event.spotsFilled / event.totalSpots) * 100)
            return (
              <Card key={event.id} className="overflow-hidden pt-0">
                <div className="relative h-32 w-full">
                  <Image
                    src={event.image || '/placeholder.svg'}
                    alt={event.name}
                    fill
                    className="object-cover"
                  />
                  <div className="absolute right-3 top-3">
                    <StatusBadge status={statusMap[event.status]} />
                  </div>
                </div>
                <CardHeader>
                  <CardTitle className="text-base">{event.name}</CardTitle>
                  <CardDescription>
                    {event.city} &middot; {event.date}
                  </CardDescription>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                  <div className="flex flex-col gap-2">
                    <div className="flex items-center justify-between text-sm">
                      <span className="text-muted-foreground">
                        {event.spotsFilled}/{event.totalSpots} booked
                      </span>
                      <span className="font-medium">{pct}%</span>
                    </div>
                    <Progress value={pct} />
                  </div>
                  <div className="flex items-center justify-between border-t border-border pt-3">
                    <div className="flex items-center gap-1.5 text-sm">
                      <span className="grid size-6 place-items-center rounded-full bg-accent text-xs font-bold text-accent-foreground">
                        {event.newApplications}
                      </span>
                      <span className="text-muted-foreground">new apps</span>
                    </div>
                    <Button
                      size="sm"
                      variant="outline"
                      render={<Link href="/organizer/applications" />}
                      nativeButton={false}
                    >
                      Review
                    </Button>
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      </div>
    </div>
  )
}
