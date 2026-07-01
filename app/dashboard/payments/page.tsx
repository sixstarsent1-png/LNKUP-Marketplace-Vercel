import { CreditCard, Download } from 'lucide-react'
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
import { payments, currency, LNKUP_FEE } from '@/lib/mock-data'

export default function PaymentsPage() {
  const paid = payments
    .filter((p) => p.status === 'Paid')
    .reduce((s, p) => s + p.vendorFee, 0)
  const due = payments
    .filter((p) => p.status === 'Due')
    .reduce((s, p) => s + p.vendorFee, 0)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Payments
        </h1>
        <p className="text-muted-foreground">
          Vendor fees, receipts, and your LNKup membership
        </p>
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <StatCard label="Total paid" value={currency(paid)} icon={CreditCard} />
        <StatCard
          label="Outstanding"
          value={currency(due)}
          icon={CreditCard}
          trend={due > 0 ? 'down' : 'neutral'}
          change={due > 0 ? 'Payment due' : 'All clear'}
        />
        <StatCard
          label="Membership"
          value={`${currency(LNKUP_FEE)}/mo`}
          icon={CreditCard}
          change="Renews Jul 1"
        />
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <div className="flex flex-col gap-1">
            <CardTitle>Transaction history</CardTitle>
            <CardDescription>All vendor fee payments</CardDescription>
          </div>
          <Button variant="outline" size="sm">
            <Download data-icon="inline-start" />
            Export
          </Button>
        </CardHeader>
        <CardContent>
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Event</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Amount</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead className="text-right">Receipt</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {payments.map((p) => (
                  <TableRow key={p.id}>
                    <TableCell className="font-medium">{p.eventName}</TableCell>
                    <TableCell className="text-muted-foreground">
                      {p.date}
                    </TableCell>
                    <TableCell>{currency(p.vendorFee)}</TableCell>
                    <TableCell>
                      <StatusBadge
                        status={
                          p.status === 'Due'
                            ? 'unpaid'
                            : p.status === 'Refunded'
                              ? 'refunded'
                              : 'paid'
                        }
                      />
                    </TableCell>
                    <TableCell className="text-right">
                      {p.status === 'Due' ? (
                        <Button size="sm">Pay now</Button>
                      ) : (
                        <Button size="sm" variant="ghost">
                          <Download data-icon="inline-start" />
                          PDF
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
