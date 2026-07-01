import Link from 'next/link'
import {
  ArrowRight,
  Building2,
  CalendarRange,
  DollarSign,
  Store,
  TrendingUp,
} from 'lucide-react'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { StatCard } from '@/components/dashboard/stat-card'
import {
  adminStats,
  pendingVendors,
  pendingOrganizers,
  topCities,
  currency,
} from '@/lib/mock-data'

export default function AdminOverview() {
  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Platform overview
        </h1>
        <p className="text-muted-foreground">
          The health of the LNKup marketplace at a glance
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Gross marketplace value"
          value={currency(adminStats.gmv)}
          icon={DollarSign}
          change="+18.2% MoM"
          trend="up"
        />
        <StatCard
          label="Platform revenue"
          value={currency(adminStats.revenue)}
          icon={TrendingUp}
          change="+16.4% MoM"
          trend="up"
        />
        <StatCard
          label="Active vendors"
          value={adminStats.totalVendors.toLocaleString()}
          icon={Store}
          change="+312 this month"
          trend="up"
        />
        <StatCard
          label="Live events"
          value={adminStats.totalEvents.toLocaleString()}
          icon={CalendarRange}
          change="+64 this month"
          trend="up"
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="flex-row items-center justify-between">
            <div className="flex flex-col gap-1">
              <CardTitle>Vendors awaiting verification</CardTitle>
              <CardDescription>
                Review new vendors before they go live
              </CardDescription>
            </div>
            <Button
              variant="ghost"
              size="sm"
              render={<Link href="/admin/vendors" />}
              nativeButton={false}
            >
              View all
              <ArrowRight data-icon="inline-end" />
            </Button>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {pendingVendors.map((v) => (
              <div
                key={v.id}
                className="flex items-center justify-between gap-4 rounded-xl border border-border p-3"
              >
                <div className="flex flex-col gap-0.5">
                  <p className="font-medium leading-tight">{v.name}</p>
                  <p className="text-sm text-muted-foreground">
                    {v.category} &middot; {v.city}
                  </p>
                </div>
                <div className="flex items-center gap-3">
                  <span className="hidden text-xs text-muted-foreground sm:block">
                    {v.submitted}
                  </span>
                  <Button size="sm" variant="outline">
                    Review
                  </Button>
                </div>
              </div>
            ))}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Top markets</CardTitle>
            <CardDescription>By gross marketplace value</CardDescription>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {topCities.map((c, i) => (
              <div key={c.city} className="flex items-center gap-3">
                <span className="grid size-7 shrink-0 place-items-center rounded-lg bg-muted text-sm font-bold">
                  {i + 1}
                </span>
                <div className="flex flex-1 flex-col">
                  <span className="text-sm font-medium">{c.city}</span>
                  <span className="text-xs text-muted-foreground">
                    {c.events} events
                  </span>
                </div>
                <span className="text-sm font-semibold">
                  {currency(c.gmv)}
                </span>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <div className="flex flex-col gap-1">
            <CardTitle>Organizers to review</CardTitle>
            <CardDescription>
              Flagged accounts require extra attention
            </CardDescription>
          </div>
          <Button
            variant="ghost"
            size="sm"
            render={<Link href="/admin/organizers" />}
            nativeButton={false}
          >
            View all
            <ArrowRight data-icon="inline-end" />
          </Button>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          {pendingOrganizers.map((o) => (
            <div
              key={o.id}
              className="flex items-center justify-between gap-4 rounded-xl border border-border p-3"
            >
              <div className="flex items-center gap-3">
                <span className="grid size-9 place-items-center rounded-lg bg-muted">
                  <Building2 className="size-4 text-muted-foreground" />
                </span>
                <div className="flex flex-col gap-0.5">
                  <div className="flex items-center gap-2">
                    <p className="font-medium leading-tight">{o.name}</p>
                    {o.flag && (
                      <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                        Flagged
                      </Badge>
                    )}
                  </div>
                  <p className="text-sm text-muted-foreground">
                    {o.events} events &middot; {o.city}
                  </p>
                </div>
              </div>
              <Button size="sm" variant="outline">
                Review
              </Button>
            </div>
          ))}
        </CardContent>
      </Card>
    </div>
  )
}
