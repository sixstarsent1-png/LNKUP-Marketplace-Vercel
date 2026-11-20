"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { PrelaunchStatus } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

export function PrelaunchStatusCard({
  status,
  panelState,
  onRetry,
}: {
  status: PrelaunchStatus | null
  panelState: PanelState
  onRetry: () => void
}) {
  if (panelState === "loading") {
    return (
      <Card data-testid="ops-prelaunch-section">
        <CardHeader>
          <CardTitle>Prelaunch status</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={1} />
        </CardContent>
      </Card>
    )
  }

  if (panelState === "error") {
    return (
      <Card data-testid="ops-prelaunch-section">
        <CardContent className="pt-6">
          <ErrorState title="Prelaunch status unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  if (!status) return null

  return (
    <Card data-testid="ops-prelaunch-section">
      <CardHeader>
        <CardTitle>Prelaunch status</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3 text-sm">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span>Launch date</span>
          <span className="font-medium">{status.launchLabel}</span>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span>Prelaunch mode</span>
          <Badge variant={status.isPrelaunchMode ? "secondary" : "default"}>
            {status.isPrelaunchMode ? "Active" : "Off"}
          </Badge>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span>Search</span>
          <Badge variant={status.canSearch ? "default" : "destructive"}>
            {status.canSearch ? "Live" : "Blocked"}
          </Badge>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span>Apply submit</span>
          <Badge variant={status.canApplySubmit ? "default" : "secondary"}>
            {status.canApplySubmit ? "Open" : "Gated"}
          </Badge>
        </div>
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span>Apply context</span>
          <Badge variant={status.isApplyContextGated ? "secondary" : "default"}>
            {status.isApplyContextGated ? "Gated" : "Open"}
          </Badge>
        </div>
        <p className="rounded-xl bg-muted/50 p-3 text-muted-foreground">
          {status.recommendedAction}
        </p>
      </CardContent>
    </Card>
  )
}
