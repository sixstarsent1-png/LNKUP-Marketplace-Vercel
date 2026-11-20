"use client"

import { Suspense } from "react"
import Link from "next/link"
import { TrendingUp, Repeat, CheckCircle2, ArrowRight, Shield } from "lucide-react"
import { usePanelState } from "@/lib/use-panel-state"
import { useVendorReputation } from "@/lib/hooks/use-vendor-reputation"
import { useVendorGrowth } from "@/lib/hooks/use-vendor-growth"
import { PageHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { ProgressRing } from "@/components/marketplace/progress-ring"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { ReputationTierLabel } from "@/components/reputation/reputation-tier-label"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import { DemoModeNotice } from "@/components/marketplace/demo-mode-notice"
import { isLiveApiEnabled } from "@/lib/api/config"
import { emptyVendorGrowthSnapshot } from "@/lib/api/vendor-growth"

function GrowthContent() {
  const { state, retry } = usePanelState()
  const { summary, loading: reputationLoading } = useVendorReputation()
  const { growth, loading: growthLoading, retry: retryGrowth } = useVendorGrowth()
  const live = isLiveApiEnabled()
  const snapshot = growth ?? emptyVendorGrowthSnapshot()

  const score = summary?.score ?? snapshot.vendorScore
  const tier = summary?.tier ?? "building"

  return (
    <PanelContent
      state={state}
      retry={() => {
        retry()
        retryGrowth()
      }}
    >
      <div className="flex flex-col gap-6">
        <PageHeader title="Growth" subtitle="Your marketplace reputation and momentum." />

        {!live ? (
          <DemoModeNotice testId="vendor-growth-demo-notice">
            Demo mode — growth metrics use sample data. Enable live API for WordPress growth KPIs.
          </DemoModeNotice>
        ) : null}

        <Card className="rounded-2xl" data-testid="vendor-growth-reputation-strip">
          <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
            <div className="flex items-center gap-4">
              <ProgressRing value={score} size={72} />
              <div className="flex flex-col gap-1">
                <span className="font-heading text-xl font-bold">Vendor score {score}</span>
                <ReputationTierLabel tier={tier} />
                {!reputationLoading && summary && (
                  <span className="text-sm text-muted-foreground">
                    {summary.reviewCount} reviews · {summary.completedEvents} events completed
                  </span>
                )}
              </div>
            </div>
            <Button
              size="sm"
              variant="outline"
              nativeButton={false}
              render={<Link href="/vendor/reviews" />}
            >
              View reputation
              <ArrowRight data-icon="inline-end" className="size-4" />
            </Button>
          </CardContent>
        </Card>

        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid="vendor-growth-kpi-grid">
          <StatCard
            label="Acceptance rate"
            value={growthLoading ? "—" : `${Math.round(snapshot.applicationSuccessRate)}%`}
            icon={CheckCircle2}
          />
          <StatCard
            label="Events completed"
            value={growthLoading ? "—" : snapshot.completedEvents}
            icon={TrendingUp}
          />
          <StatCard
            label="Repeat organizers"
            value={growthLoading ? "—" : `${Math.round(snapshot.repeatOrganizerPct || snapshot.rebookingRate)}%`}
            icon={Repeat}
          />
          <StatCard
            label="Profile strength"
            value={growthLoading ? "—" : `${Math.round(snapshot.profileStrength)}%`}
            icon={Shield}
          />
        </div>

        <Card className="rounded-2xl">
          <CardContent className="flex flex-col gap-3 pt-6">
            <h2 className="font-heading text-base font-semibold">Milestones</h2>
            {snapshot.milestones.map((m) => (
              <div key={m.id} className="flex items-center gap-3 text-sm">
                <CheckCircle2
                  className={cn("size-4 shrink-0", m.done ? "text-accent-foreground" : "text-muted-foreground")}
                />
                <span className={m.done ? "text-foreground" : "text-muted-foreground"}>{m.label}</span>
              </div>
            ))}
          </CardContent>
        </Card>
      </div>
    </PanelContent>
  )
}

export default function VendorGrowthPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <GrowthContent />
    </Suspense>
  )
}
