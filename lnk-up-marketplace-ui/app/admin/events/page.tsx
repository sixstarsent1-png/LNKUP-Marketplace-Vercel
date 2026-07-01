"use client"

import Image from "next/image"
import { CalendarDays, MapPin } from "lucide-react"
import { events } from "@/lib/data"
import { PageHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { StatusBadge } from "@/components/marketplace/badges"
import { Card } from "@/components/ui/card"
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table"

export default function AdminEventsPage() {
  const live = events.filter((e) => e.status === "live").length
  const totalVendors = events.reduce((s, e) => s + e.paidVendors, 0)

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Events" subtitle="Every event listing across the marketplace." />

      <div className="grid grid-cols-3 gap-3">
        <StatCard label="Total events" value={events.length} icon={CalendarDays} accent />
        <StatCard label="Live now" value={live} hint="Accepting vendors" />
        <StatCard label="Booked vendors" value={totalVendors} hint="Across all events" />
      </div>

      <Card className="overflow-hidden p-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Event</TableHead>
              <TableHead className="hidden md:table-cell">Organizer</TableHead>
              <TableHead className="hidden sm:table-cell">Fill</TableHead>
              <TableHead>Revenue</TableHead>
              <TableHead>Status</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {events.map((e) => (
              <TableRow key={e.id}>
                <TableCell>
                  <div className="flex items-center gap-3">
                    <div className="relative size-10 shrink-0 overflow-hidden rounded-lg">
                      <Image src={e.image || "/placeholder.svg"} alt={e.name} fill className="object-cover" />
                    </div>
                    <div className="flex flex-col">
                      <span className="text-sm font-medium">{e.name}</span>
                      <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <MapPin className="size-3" />
                        {e.city}, {e.state}
                      </span>
                    </div>
                  </div>
                </TableCell>
                <TableCell className="hidden md:table-cell text-sm text-muted-foreground">
                  {e.organizer}
                </TableCell>
                <TableCell className="hidden sm:table-cell text-sm text-muted-foreground">
                  {e.approvedVendors}/{e.totalSpots}
                </TableCell>
                <TableCell className="font-heading text-sm font-bold">
                  ${e.revenue.toLocaleString()}
                </TableCell>
                <TableCell>
                  <StatusBadge variant={e.status}>
                    {e.status === "live" ? "Live" : e.status === "closed" ? "Completed" : "Draft"}
                  </StatusBadge>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </Card>
    </div>
  )
}
