"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { SkeletonList } from "@/components/marketplace/states"
import { ErrorState } from "@/components/marketplace/states"
import type { MarketplaceFunnel } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

export function FunnelGrid({
  funnel,
  state,
  onRetry,
}: {
  funnel: MarketplaceFunnel | null
  state: PanelState
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-funnel-section">
        <CardHeader>
          <CardTitle>Marketplace funnel</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={3} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-funnel-section">
        <CardContent className="pt-6">
          <ErrorState title="Funnel unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  if (!funnel?.steps?.length) {
    return (
      <Card data-testid="ops-funnel-section">
        <CardHeader>
          <CardTitle>Marketplace funnel</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">No funnel data for this range.</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-funnel-section">
      <CardHeader>
        <CardTitle>Marketplace funnel</CardTitle>
      </CardHeader>
      <CardContent>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
          {funnel.steps.map((step) => (
            <div
              key={step.id}
              className="rounded-2xl border bg-card p-4"
              data-testid={`funnel-step-${step.id}`}
            >
              <div className="flex items-start justify-between gap-2">
                <p className="text-xs font-medium text-muted-foreground">{step.label}</p>
                {step.conversionRate !== undefined && step.previousCount !== undefined && (
                  <Badge variant="outline" className="text-[10px]">
                    {step.conversionRate}% conv
                  </Badge>
                )}
              </div>
              <p className="mt-2 font-heading text-2xl font-bold">{step.count}</p>
              {step.dropoffRate !== undefined && step.dropoffRate > 0 && (
                <p className="mt-1 text-xs text-destructive">{step.dropoffRate}% drop-off</p>
              )}
            </div>
          ))}
        </div>
      </CardContent>
    </Card>
  )
}
