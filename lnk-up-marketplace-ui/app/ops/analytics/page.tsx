"use client"

import { Suspense } from "react"
import { useOpsAnalytics } from "@/lib/hooks/use-ops-analytics"
import { OpsPageShell } from "@/components/ops/ops-page-shell"
import { FunnelGrid } from "@/components/ops/funnel-grid"
import { VendorActivationSection } from "@/components/ops/vendor-activation-section"
import { OpportunityPerformanceSection } from "@/components/ops/opportunity-performance-section"
import { SearchInsightsSection } from "@/components/ops/search-insights-section"
import { MarketDemandSection } from "@/components/ops/market-demand-section"
import { AlertsPanel } from "@/components/ops/alerts-panel"
import { BffHealthSection } from "@/components/ops/bff-health-section"
import { PrelaunchStatusCard } from "@/components/ops/prelaunch-status-card"
import { ExperimentInsightsSection } from "@/components/ops/experiment-insights-section"
import { SkeletonList } from "@/components/marketplace/states"

function OpsAnalyticsContent() {
  const ops = useOpsAnalytics()

  return (
    <OpsPageShell
      range={ops.range}
      onRangeChange={ops.setRange}
      isMocked={ops.isMocked}
      isPartial={ops.isPartial}
      unavailableReasons={ops.unavailableReasons}
      onRetry={ops.retry}
    >
      <div className="flex flex-col gap-6">
        <FunnelGrid funnel={ops.funnel} state={ops.funnelState} onRetry={ops.retry} />

        <div className="grid gap-6 lg:grid-cols-2">
          <PrelaunchStatusCard
            status={ops.prelaunch}
            panelState={ops.prelaunchState}
            onRetry={ops.retry}
          />
          <BffHealthSection
            checks={ops.healthChecks}
            state={ops.healthState}
            onRetry={ops.retry}
          />
        </div>

        <VendorActivationSection
          data={ops.summary?.vendorActivation ?? null}
          state={ops.summaryState}
          onRetry={ops.retry}
        />

        <OpportunityPerformanceSection
          items={ops.summary?.opportunities ?? []}
          state={ops.summaryState}
          cityFilter={ops.cityFilter}
          categoryFilter={ops.categoryFilter}
          statusFilter={ops.statusFilter}
          onCityChange={ops.setCityFilter}
          onCategoryChange={ops.setCategoryFilter}
          onStatusChange={ops.setStatusFilter}
          onRetry={ops.retry}
        />

        <div className="grid gap-6 lg:grid-cols-2">
          <SearchInsightsSection
            insights={ops.summary?.searchInsights ?? []}
            state={ops.summaryState}
            onRetry={ops.retry}
          />
          <MarketDemandSection
            markets={ops.summary?.markets ?? []}
            state={ops.summaryState}
            onRetry={ops.retry}
          />
        </div>

        <AlertsPanel
          alerts={ops.summary?.alerts ?? []}
          state={ops.summaryState}
          onRetry={ops.retry}
        />
        <ExperimentInsightsSection
          experiments={ops.summary?.experiments ?? []}
          diagnostics={ops.summary?.diagnostics ?? []}
          recommendations={ops.summary?.recommendations ?? []}
          state={ops.summaryState}
          onRetry={ops.retry}
        />
      </div>
    </OpsPageShell>
  )
}

export default function OpsAnalyticsPage() {
  return (
    <Suspense
      fallback={
        <div className="mx-auto max-w-6xl px-4 py-8">
          <SkeletonList count={4} />
        </div>
      }
    >
      <OpsAnalyticsContent />
    </Suspense>
  )
}
