import {
  CheckCircle2,
  Clock,
  FileText,
  Upload,
  AlertTriangle,
  XCircle,
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
import { Progress } from '@/components/ui/progress'
import { documents, type VendorDocument } from '@/lib/mock-data'

const statusMeta: Record<
  VendorDocument['status'],
  { icon: typeof CheckCircle2; tone: string; label: string }
> = {
  Verified: { icon: CheckCircle2, tone: 'text-emerald-600', label: 'Verified' },
  Pending: { icon: Clock, tone: 'text-amber-600', label: 'Pending review' },
  'Expiring Soon': {
    icon: AlertTriangle,
    tone: 'text-amber-600',
    label: 'Expiring soon',
  },
  Missing: { icon: XCircle, tone: 'text-red-600', label: 'Missing' },
}

export default function DocumentsPage() {
  const verified = documents.filter((d) => d.status === 'Verified').length
  const completion = Math.round((verified / documents.length) * 100)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Documents &amp; compliance
        </h1>
        <p className="text-muted-foreground">
          Verified documents get you approved faster
        </p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Compliance score</CardTitle>
          <CardDescription>
            {verified} of {documents.length} documents verified
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-3">
          <Progress value={completion} />
          <p className="text-sm text-muted-foreground">
            Complete your profile to unlock premium events that require full
            compliance.
          </p>
        </CardContent>
      </Card>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        {documents.map((doc) => {
          const meta = statusMeta[doc.status]
          const Icon = meta.icon
          return (
            <Card key={doc.id}>
              <CardContent className="flex items-center justify-between gap-4 pt-6">
                <div className="flex items-center gap-3">
                  <div className="flex size-10 items-center justify-center rounded-xl bg-muted">
                    <FileText className="size-5 text-muted-foreground" />
                  </div>
                  <div className="flex flex-col gap-0.5">
                    <p className="font-medium leading-tight">{doc.name}</p>
                    <span
                      className={`flex items-center gap-1 text-xs font-medium ${meta.tone}`}
                    >
                      <Icon className="size-3.5" />
                      {meta.label}
                      {doc.expires ? ` · Exp ${doc.expires}` : ''}
                    </span>
                  </div>
                </div>
                <Button
                  size="sm"
                  variant={doc.status === 'Missing' ? 'default' : 'outline'}
                >
                  <Upload data-icon="inline-start" />
                  {doc.status === 'Missing' ? 'Upload' : 'Replace'}
                </Button>
              </CardContent>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
