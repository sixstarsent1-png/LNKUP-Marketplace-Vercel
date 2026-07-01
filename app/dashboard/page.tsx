import Link from 'next/link'
import { ArrowRight, CalendarCheck, DollarSign, FileWarning, Star } from 'lucide-react'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { StatCard } from '@/components/dashboard/stat-card'
import { AppStatusBadge } from '@/components/dashboard/app-status-badge'
import { EventCard } from '@/components/event-card'
import {
  applications,
  events,
  currency,
  payments,
} from '@/lib/mock-data'

export default function VendorHomePage() {
  const actionNeeded = applications.filter(
    (a) => a.status === 'Payment Required' || a.status === 'Approved',
  )
  const recommended = events.slice(0, 3)
  const earned = payments
    .filter((p) => p.status === 'Paid')
    .reduce((sum, p) => sum + p.vendorFee, 0)

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Welcome back, Marcus
        </h1>
        <p className="text-muted-foreground">
          Here&apos;s what&apos;s happening with Smoke &amp; Brisket Co.
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Active applications"
          value={applications.filter((a) => a.status !== 'Completed').length.toString()}
          icon={CalendarCheck}
          change="+2 this week"
          trend="up"
        />
        <StatCard
          label="Confirmed bookings"
          value="3"
          icon={Star}
          change="Next: Jun 21"
          trend="neutral"
        />
        <StatCard
          label="Earned this season"
          value={currency(earned)}
          icon={DollarSign}
          change="+18% vs last"
          trend="up"
        />
        <StatCard
          label="Documents"
          value="1 expiring"
          icon={FileWarning}
          change="Health permit"
          trend="down"
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex-row items-center justify-between">
            <div className="flex flex-col gap-1">
              <CardTitle>Needs your attention</CardTitle>
              <CardDescription>
                Applications waiting on a next step from you
              </CardDescription>
            </div>
            <Button
              variant="ghost"
              size="sm"
              render={<Link href="/dashboard/applications" />}
              nativeButton={false}
            >
              View all
              <ArrowRight data-icon="inline-end" />
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {actionNeeded.map((app) => (
              <div
                key={app.id}
                className="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4"
              >
                <div className="flex flex-col gap-1">
                  <p className="font-medium leading-tight">{app.eventName}</p>
                  <p className="text-sm text-muted-foreground">
                    {app.city} &middot; {app.date}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <AppStatusBadge status={app.status} />
                  {app.status === 'Payment Required' ? (
                    <Button size="sm">Pay {currency(app.vendorFee)}</Button>
                  ) : (
                    <Button size="sm" variant="outline">
                      Review
                    </Button>
                  )}
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Your reputation</CardTitle>
            <CardDescription>Across all completed events</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-5">
            <div className="flex items-end gap-2">
              <span className="font-display text-4xl font-bold">4.8</span>
              <div className="mb-1 flex items-center gap-0.5 text-amber-500">
                {Array.from({ length: 5 }).map((_, i) => (
                  <Star
                    key={i}
                    className="size-4 fill-current"
                    strokeWidth={0}
                  />
                ))}
              </div>
            </div>
            <div className="flex flex-col gap-3 text-sm">
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Completed events</span>
                <span className="font-medium">42</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">Repeat bookings</span>
                <span className="font-medium">11</span>
              </div>
              <div className="flex items-center justify-between">
                <span className="text-muted-foreground">On-time rate</span>
                <span className="font-medium">98%</span>
              </div>
            </div>
            <Button
              variant="outline"
              className="w-full"
              render={<Link href="/dashboard/reviews" />}
              nativeButton={false}
            >
              View reviews
            </Button>
          </CardContent>
        </Card>
      </div>

      <div className="flex flex-col gap-4">
        <div className="flex items-center justify-between">
          <div className="flex flex-col gap-1">
            <h2 className="font-display text-xl font-bold tracking-tight">
              Recommended for you
            </h2>
            <p className="text-sm text-muted-foreground">
              Events matching your category and location
            </p>
          </div>
          <Button
            variant="ghost"
            size="sm"
            render={<Link href="/dashboard/find-events" />}
            nativeButton={false}
          >
            Browse all
            <ArrowRight data-icon="inline-end" />
          </Button>
        </div>
        <div className="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
          {recommended.map((event) => (
            <EventCard key={event.id} event={event} />
          ))}
        </div>
      </div>
    </div>
  )
}
