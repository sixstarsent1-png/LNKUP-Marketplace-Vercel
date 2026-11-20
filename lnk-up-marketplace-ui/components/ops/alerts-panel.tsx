"use client"

import Link from "next/link"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { LaunchOptimizationAlert } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

function severityVariant(severity: LaunchOptimizationAlert["severity"]) {
  if (severity === "urgent") return "destructive" as const
  if (severity === "warning") return "secondary" as const
  return "outline" as const
}

export function AlertsPanel({
  alerts,
  state,
  onRetry,
}: {
  alerts: LaunchOptimizationAlert[]
  state: PanelState
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-alerts-section">
        <CardHeader>
          <CardTitle>Launch alerts</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={2} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-alerts-section">
        <CardContent className="pt-6">
          <ErrorState title="Alerts unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-alerts-section">
      <CardHeader>
        <CardTitle>Launch alerts</CardTitle>
      </CardHeader>
      <CardContent>
        {alerts.length === 0 ? (
          <p className="text-sm text-muted-foreground">No alerts — marketplace looks healthy.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {alerts.map((alert) => (
              <li
                key={alert.id}
                className="rounded-2xl border p-4"
                data-testid={`alert-${alert.id}`}
              >
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={severityVariant(alert.severity)}>{alert.severity}</Badge>
                  <span className="font-medium">{alert.title}</span>
                </div>
                <p className="mt-2 text-sm text-muted-foreground">{alert.description}</p>
                {alert.suggestedAction && (
                  <p className="mt-1 text-sm font-medium">{alert.suggestedAction}</p>
                )}
                {alert.href && (
                  <Link href={alert.href} className="mt-2 inline-block text-sm underline">
                    Take action
                  </Link>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
