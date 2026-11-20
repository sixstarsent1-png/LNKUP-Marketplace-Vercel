"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { BffHealthCheck } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

function statusBadge(status: BffHealthCheck["status"]) {
  const map = {
    pass: "default",
    warning: "secondary",
    fail: "destructive",
    not_configured: "outline",
    auth_required: "outline",
  } as const
  return map[status] ?? "outline"
}

export function BffHealthSection({
  checks,
  state,
  onRetry,
}: {
  checks: BffHealthCheck[]
  state: PanelState
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-health-section">
        <CardHeader>
          <CardTitle>BFF & API health</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={3} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-health-section">
        <CardContent className="pt-6">
          <ErrorState title="Health checks failed" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-health-section">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle>BFF & API health</CardTitle>
        <Button variant="outline" size="sm" onClick={onRetry}>
          Re-run checks
        </Button>
      </CardHeader>
      <CardContent>
        <ul className="flex flex-col gap-2">
          {checks.map((check) => (
            <li
              key={check.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-3 py-2 text-sm"
              data-testid={`health-${check.id}`}
            >
              <span>{check.label}</span>
              <div className="flex items-center gap-2">
                {check.latencyMs !== undefined && (
                  <span className="text-xs text-muted-foreground">{check.latencyMs}ms</span>
                )}
                <Badge variant={statusBadge(check.status)}>{check.status}</Badge>
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  )
}
