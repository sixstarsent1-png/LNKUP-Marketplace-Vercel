'use client'

import { Building2, ShieldAlert } from 'lucide-react'
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
import { Badge } from '@/components/ui/badge'
import { StatusBadge } from '@/components/dashboard/status-badge'
import { toast } from 'sonner'
import { pendingOrganizers, currency } from '@/lib/mock-data'

const organizers = [
  { id: 'o1', name: 'Southern Live Co.', events: 24, gmv: 184000, status: 'active' as const, flag: false },
  { id: 'o2', name: 'District Culture', events: 12, gmv: 96000, status: 'active' as const, flag: false },
  { id: 'o3', name: 'Sunset Collective', events: 6, gmv: 142000, status: 'active' as const, flag: false },
  { id: 'o4', name: 'Greek Picnic LLC', events: 9, gmv: 78000, status: 'active' as const, flag: false },
  { id: 'o5', name: 'Fest Kings Promotions', events: 2, gmv: 12000, status: 'suspended' as const, flag: true },
]

export default function AdminOrganizersPage() {
  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Organizer management
        </h1>
        <p className="text-muted-foreground">
          Monitor event organizers and resolve flagged accounts
        </p>
      </div>

      {pendingOrganizers.some((o) => o.flag) && (
        <Card className="border-red-200 bg-red-50/50">
          <CardContent className="flex items-center gap-3 pt-6">
            <span className="grid size-10 place-items-center rounded-xl bg-red-100 text-red-600">
              <ShieldAlert className="size-5" />
            </span>
            <div className="flex-1">
              <p className="font-medium">1 organizer needs review</p>
              <p className="text-sm text-muted-foreground">
                Fest Kings Promotions was flagged for multiple vendor disputes.
              </p>
            </div>
            <Button size="sm" variant="outline">
              Review now
            </Button>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader>
          <CardTitle>{organizers.length} organizers</CardTitle>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Organizer</TableHead>
                  <TableHead>Events</TableHead>
                  <TableHead>GMV</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {organizers.map((o) => (
                  <TableRow key={o.id}>
                    <TableCell>
                      <div className="flex items-center gap-3">
                        <span className="grid size-8 place-items-center rounded-lg bg-muted">
                          <Building2 className="size-4 text-muted-foreground" />
                        </span>
                        <div className="flex items-center gap-2">
                          <span className="font-medium">{o.name}</span>
                          {o.flag && (
                            <Badge variant="outline" className="border-red-200 bg-red-50 text-red-700">
                              Flagged
                            </Badge>
                          )}
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="text-muted-foreground">
                      {o.events}
                    </TableCell>
                    <TableCell>{currency(o.gmv)}</TableCell>
                    <TableCell>
                      <StatusBadge status={o.status} />
                    </TableCell>
                    <TableCell className="text-right">
                      {o.status === 'suspended' ? (
                        <Button
                          size="sm"
                          onClick={() => toast.success(`${o.name} reinstated`)}
                        >
                          Reinstate
                        </Button>
                      ) : (
                        <Button
                          size="sm"
                          variant="outline"
                          onClick={() => toast(`${o.name} suspended`)}
                        >
                          Suspend
                        </Button>
                      )}
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
