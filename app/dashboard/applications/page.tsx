'use client'

import { useState } from 'react'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Button } from '@/components/ui/button'
import {
  Tabs,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs'
import { AppStatusBadge } from '@/components/dashboard/app-status-badge'
import { applications, currency, type ApplicationStatus } from '@/lib/mock-data'

const filters: { label: string; value: 'all' | 'active' | 'action' | 'past' }[] = [
  { label: 'All', value: 'all' },
  { label: 'Active', value: 'active' },
  { label: 'Action needed', value: 'action' },
  { label: 'Past', value: 'past' },
]

function actionFor(status: ApplicationStatus) {
  switch (status) {
    case 'Payment Required':
      return <Button size="sm">Pay now</Button>
    case 'Approved':
      return (
        <Button size="sm" variant="outline">
          Confirm spot
        </Button>
      )
    default:
      return (
        <Button size="sm" variant="ghost">
          View
        </Button>
      )
  }
}

export default function ApplicationsPage() {
  const [tab, setTab] = useState<'all' | 'active' | 'action' | 'past'>('all')

  const filtered = applications.filter((a) => {
    if (tab === 'all') return true
    if (tab === 'active')
      return !['Completed', 'Rejected'].includes(a.status)
    if (tab === 'action')
      return ['Payment Required', 'Approved'].includes(a.status)
    if (tab === 'past') return ['Completed', 'Rejected'].includes(a.status)
    return true
  })

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          My applications
        </h1>
        <p className="text-muted-foreground">
          Track every event you&apos;ve applied to in one place
        </p>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as typeof tab)}>
        <TabsList>
          {filters.map((f) => (
            <TabsTrigger key={f.value} value={f.value}>
              {f.label}
            </TabsTrigger>
          ))}
        </TabsList>
      </Tabs>

      <Card>
        <CardHeader>
          <CardTitle>{filtered.length} applications</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Event</TableHead>
                  <TableHead>Location</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Fee</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Action</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filtered.map((app) => (
                  <TableRow key={app.id}>
                    <TableCell className="font-medium">
                      {app.eventName}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {app.city}
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {app.date}
                    </TableCell>
                    <TableCell>{currency(app.vendorFee)}</TableCell>
                    <TableCell>
                      <AppStatusBadge status={app.status} />
                    </TableCell>
                    <TableCell className="text-right">
                      {actionFor(app.status)}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </div>
  )
}
