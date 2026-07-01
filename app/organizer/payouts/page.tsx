import { Banknote, Download, TrendingUp, Wallet } from 'lucide-react'
import {
  Card,
  CardContent,
  CardDescription,
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
import { StatCard } from '@/components/dashboard/stat-card'
import { StatusBadge } from '@/components/dashboard/status-badge'
import { organizerEvents, currency } from '@/lib/mock-data'

const payoutRows = [
  { id: 'p1', event: 'NC Greek Picnic', date: 'Jun 25, 2026', gross: 8200, status: 'paid' as const },
  { id: 'p2', event: 'Soul District Block Party', date: 'Jul 8, 2026', gross: 6840, status: 'unpaid' as const },
  { id: 'p3', event: 'Dirty South Rodeo', date: 'Aug 20, 2026', gross: 8500, status: 'unpaid' as const },
]

export default function OrganizerPayoutsPage() {
  const total = organizerEvents.reduce((s, e) => s + e.revenue, 0)
  const pending = payoutRows
    .filter((p) => p.status === 'unpaid')
    .reduce((s, p) => s + p.gross * 0.92, 0)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Payouts
        </h1>
        <p className="text-muted-foreground">
          Vendor fee revenue, minus the LNKup platform fee
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Lifetime revenue" value={currency(total)} icon={Wallet} change="+24% YoY" trend="up" />
        <StatCard label="Pending payout" value={currency(pending)} icon={Banknote} change="2 events" trend="neutral" />
        <StatCard label="Avg per event" value={currency(Math.round(total / organizerEvents.length))} icon={TrendingUp} change="Above market" trend="up" />
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <div className="flex flex-col gap-1">
            <CardTitle>Payout schedule</CardTitle>
            <CardDescription>Net of 8% platform fee</CardDescription>
          </div>
          <Button variant="outline" size="sm">
            <Download data-icon="inline-start" />
            Statement
          </Button>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Event</TableHead>
                  <TableHead>Payout date</TableHead>
                  <TableHead>Gross</TableHead>
                  <TableHead>Net</TableHead>
                  <TableHead className="text-right">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {payoutRows.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell className="font-medium">{p.event}</TableCell>
                    <TableCell className="text-muted-foreground">{p.date}</TableCell>
                    <TableCell>{currency(p.gross)}</TableCell>
                    <TableCell className="font-medium">
                      {currency(Math.round(p.gross * 0.92))}
                    </TableCell>
                    <TableCell className="text-right">
                      <StatusBadge status={p.status === 'paid' ? 'paid' : 'pending'} />
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
