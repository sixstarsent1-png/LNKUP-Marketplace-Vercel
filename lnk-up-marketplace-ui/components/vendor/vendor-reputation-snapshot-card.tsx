"use client"

import Link from "next/link"
import { ArrowRight } from "lucide-react"
import type { VendorReputationSummary } from "@/lib/api/vendor-reputation"
import { ReputationSummaryCard } from "@/components/reputation/reputation-summary-card"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export function VendorReputationSnapshotCard({
  summary,
  loading,
}: {
  summary: VendorReputationSummary | null
  loading?: boolean
}) {
  if (loading) {
    return (
      <Card className="rounded-2xl animate-pulse" data-testid="vendor-reputation-snapshot">
        <CardContent className="h-28 p-6" />
      </Card>
    )
  }

  if (!summary) return null

  const isNew = summary.tier === "new" || summary.reviewCount === 0

  return (
    <div className="flex flex-col gap-3" data-testid="vendor-reputation-snapshot">
      {isNew ? (
        <Card className="rounded-2xl border-dashed">
          <CardContent className="flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-medium">Build your reputation with every completed event.</p>
              <p className="text-sm text-muted-foreground">
                Your reviews will appear here after organizers rate completed events.
              </p>
            </div>
            <Button size="sm" variant="outline" nativeButton={false} render={<Link href="/vendor/find" />}>
              Find events
            </Button>
          </CardContent>
        </Card>
      ) : (
        <ReputationSummaryCard summary={summary} compact />
      )}
      <Button
        variant="ghost"
        size="sm"
        className="w-fit"
        nativeButton={false}
        render={<Link href="/vendor/reviews" />}
      >
        View reputation
        <ArrowRight data-icon="inline-end" className="size-4" />
      </Button>
    </div>
  )
}
