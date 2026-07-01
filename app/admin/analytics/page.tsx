'use client'

import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  XAxis,
  YAxis,
} from 'recharts'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
  type ChartConfig,
} from '@/components/ui/chart'
import { StatCard } from '@/components/dashboard/stat-card'
import {
  revenueTrend,
  topCategories,
  adminStats,
  currency,
} from '@/lib/mock-data'
import { DollarSign, Layers, ShoppingCart, Users } from 'lucide-react'

const revenueConfig = {
  gmv: { label: 'GMV ($K)', color: 'var(--chart-2)' },
  revenue: { label: 'Revenue ($K)', color: 'var(--chart-1)' },
} satisfies ChartConfig

const categoryConfig = {
  share: { label: 'Share %', color: 'var(--chart-1)' },
} satisfies ChartConfig

export default function AdminAnalyticsPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Analytics
        </h1>
        <p className="text-muted-foreground">
          Marketplace performance over the last 6 months
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Total applications" value={adminStats.applications.toLocaleString()} icon={Layers} change="+22% MoM" trend="up" />
        <StatCard label="Bookings" value={adminStats.bookings.toLocaleString()} icon={ShoppingCart} change="+19% MoM" trend="up" />
        <StatCard label="Organizers" value={adminStats.totalOrganizers.toLocaleString()} icon={Users} change="+14 MoM" trend="up" />
        <StatCard label="Take rate" value="6.4%" icon={DollarSign} change="Stable" trend="neutral" />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader>
            <CardTitle>GMV &amp; revenue</CardTitle>
            <CardDescription>Monthly, in thousands of USD</CardDescription>
          </CardHeader>
          <CardContent>
            <ChartContainer config={revenueConfig} className="h-[300px] w-full">
              <AreaChart data={revenueTrend} margin={{ left: 4, right: 8, top: 8 }}>
                <CartesianGrid vertical={false} />
                <XAxis
                  dataKey="month"
                  tickLine={false}
                  axisLine={false}
                  tickMargin={8}
                />
                <YAxis tickLine={false} axisLine={false} width={36} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <defs>
                  <linearGradient id="fillGmv" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="var(--color-gmv)" stopOpacity={0.6} />
                    <stop offset="95%" stopColor="var(--color-gmv)" stopOpacity={0.05} />
                  </linearGradient>
                  <linearGradient id="fillRev" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="var(--color-revenue)" stopOpacity={0.6} />
                    <stop offset="95%" stopColor="var(--color-revenue)" stopOpacity={0.05} />
                  </linearGradient>
                </defs>
                <Area
                  dataKey="gmv"
                  type="monotone"
                  stroke="var(--color-gmv)"
                  fill="url(#fillGmv)"
                  strokeWidth={2}
                />
                <Area
                  dataKey="revenue"
                  type="monotone"
                  stroke="var(--color-revenue)"
                  fill="url(#fillRev)"
                  strokeWidth={2}
                />
              </AreaChart>
            </ChartContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Category mix</CardTitle>
            <CardDescription>Share of bookings</CardDescription>
          </CardHeader>
          <CardContent>
            <ChartContainer config={categoryConfig} className="h-[300px] w-full">
              <BarChart
                data={topCategories}
                layout="vertical"
                margin={{ left: 8, right: 12 }}
              >
                <CartesianGrid horizontal={false} />
                <XAxis type="number" hide />
                <YAxis
                  dataKey="category"
                  type="category"
                  tickLine={false}
                  axisLine={false}
                  width={90}
                  tickMargin={4}
                />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Bar
                  dataKey="share"
                  fill="var(--color-share)"
                  radius={[0, 6, 6, 0]}
                />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
