'use client'

import { useState } from 'react'
import {
  BadgeCheck,
  Check,
  Clock,
  FileCheck2,
  Star,
  X,
} from 'lucide-react'
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  Tabs,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs'
import { toast } from 'sonner'
import { vendorApplicants } from '@/lib/mock-data'

type Decision = 'approved' | 'rejected' | 'waitlisted'

export default function OrganizerApplicationsPage() {
  const [decisions, setDecisions] = useState<Record<string, Decision>>({})
  const [tab, setTab] = useState('all')

  function decide(id: string, name: string, decision: Decision) {
    setDecisions((prev) => ({ ...prev, [id]: decision }))
    toast.success(
      `${name} ${
        decision === 'approved'
          ? 'approved'
          : decision === 'rejected'
            ? 'declined'
            : 'added to waitlist'
      }`,
    )
  }

  const list = vendorApplicants.filter((v) => {
    if (tab === 'all') return true
    if (tab === 'pending') return !decisions[v.id]
    return decisions[v.id] === tab
  })

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-2xl font-bold tracking-tight md:text-3xl">
          Vendor applications
        </h1>
        <p className="text-muted-foreground">
          Review and approve vendors for your events
        </p>
      </div>

      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="all">All</TabsTrigger>
          <TabsTrigger value="pending">Pending</TabsTrigger>
          <TabsTrigger value="approved">Approved</TabsTrigger>
          <TabsTrigger value="waitlisted">Waitlisted</TabsTrigger>
        </TabsList>
      </Tabs>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        {list.map((v) => {
          const decision = decisions[v.id]
          return (
            <Card key={v.id}>
              <CardHeader className="flex-row items-start gap-4">
                <Avatar className="size-12">
                  <AvatarImage src={v.avatar || '/placeholder.svg'} alt={v.businessName} />
                  <AvatarFallback>
                    {v.businessName.slice(0, 2)}
                  </AvatarFallback>
                </Avatar>
                <div className="flex flex-1 flex-col gap-1">
                  <div className="flex items-center gap-1.5">
                    <CardTitle className="text-base">
                      {v.businessName}
                    </CardTitle>
                    {v.verified && (
                      <BadgeCheck className="size-4 text-accent" />
                    )}
                  </div>
                  <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <span>{v.category}</span>
                    <span>&middot;</span>
                    <span className="flex items-center gap-1">
                      <Star className="size-3.5 fill-amber-500 text-amber-500" />
                      {v.rating}
                    </span>
                    <span>&middot;</span>
                    <span>{v.completedEvents} events</span>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                <div className="flex flex-wrap gap-2">
                  <Badge variant="secondary" className="gap-1">
                    Applied: {v.appliedFor}
                  </Badge>
                  <Badge
                    variant="outline"
                    className={
                      v.documentsComplete
                        ? 'gap-1 border-emerald-200 bg-emerald-50 text-emerald-700'
                        : 'gap-1 border-amber-200 bg-amber-50 text-amber-700'
                    }
                  >
                    {v.documentsComplete ? (
                      <FileCheck2 className="size-3.5" />
                    ) : (
                      <Clock className="size-3.5" />
                    )}
                    {v.documentsComplete ? 'Docs verified' : 'Docs pending'}
                  </Badge>
                </div>

                {decision ? (
                  <div className="flex items-center justify-between rounded-xl bg-muted p-3">
                    <span className="text-sm font-medium capitalize">
                      {decision === 'approved'
                        ? 'Approved for event'
                        : decision === 'rejected'
                          ? 'Application declined'
                          : 'Added to waitlist'}
                    </span>
                    <Button
                      size="sm"
                      variant="ghost"
                      onClick={() =>
                        setDecisions((prev) => {
                          const next = { ...prev }
                          delete next[v.id]
                          return next
                        })
                      }
                    >
                      Undo
                    </Button>
                  </div>
                ) : (
                  <div className="flex gap-2">
                    <Button
                      size="sm"
                      className="flex-1"
                      onClick={() => decide(v.id, v.businessName, 'approved')}
                    >
                      <Check data-icon="inline-start" />
                      Approve
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="flex-1"
                      onClick={() => decide(v.id, v.businessName, 'waitlisted')}
                    >
                      Waitlist
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="text-destructive"
                      onClick={() => decide(v.id, v.businessName, 'rejected')}
                    >
                      <X data-icon="inline-start" />
                      Decline
                    </Button>
                  </div>
                )}
              </CardContent>
            </Card>
          )
        })}
      </div>
    </div>
  )
}
