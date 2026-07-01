import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'
import { applicationStatusMeta, type ApplicationStatus } from '@/lib/mock-data'

const toneStyles: Record<string, string> = {
  neutral: 'bg-muted text-muted-foreground border-border',
  accent: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  warn: 'bg-amber-100 text-amber-800 border-amber-200',
  success: 'bg-emerald-100 text-emerald-800 border-emerald-200',
  danger: 'bg-red-100 text-red-800 border-red-200',
}

export function AppStatusBadge({
  status,
  className,
}: {
  status: ApplicationStatus
  className?: string
}) {
  const meta = applicationStatusMeta[status]
  return (
    <Badge
      variant="outline"
      className={cn('font-medium', toneStyles[meta.tone], className)}
    >
      {meta.label}
    </Badge>
  )
}
