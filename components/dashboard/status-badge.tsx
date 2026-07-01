import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

type Status =
  | 'pending'
  | 'approved'
  | 'rejected'
  | 'waitlisted'
  | 'paid'
  | 'unpaid'
  | 'refunded'
  | 'active'
  | 'suspended'
  | 'draft'
  | 'live'
  | 'closed'
  | 'verified'

const styles: Record<string, string> = {
  pending: 'bg-amber-100 text-amber-800 border-amber-200',
  waitlisted: 'bg-amber-100 text-amber-800 border-amber-200',
  unpaid: 'bg-amber-100 text-amber-800 border-amber-200',
  draft: 'bg-muted text-muted-foreground border-border',
  closed: 'bg-muted text-muted-foreground border-border',
  approved: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  paid: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  active: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  live: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  verified: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  rejected: 'bg-red-100 text-red-800 border-red-200',
  suspended: 'bg-red-100 text-red-800 border-red-200',
  refunded: 'bg-red-100 text-red-800 border-red-200',
}

const labels: Record<string, string> = {
  pending: 'Pending',
  approved: 'Approved',
  rejected: 'Rejected',
  waitlisted: 'Waitlisted',
  paid: 'Paid',
  unpaid: 'Unpaid',
  refunded: 'Refunded',
  active: 'Active',
  suspended: 'Suspended',
  draft: 'Draft',
  live: 'Live',
  closed: 'Closed',
  verified: 'Verified',
}

export function StatusBadge({
  status,
  className,
}: {
  status: Status
  className?: string
}) {
  return (
    <Badge
      variant="outline"
      className={cn('font-medium capitalize', styles[status], className)}
    >
      {labels[status] ?? status}
    </Badge>
  )
}
