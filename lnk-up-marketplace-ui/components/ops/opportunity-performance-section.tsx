"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { OpportunityPerformanceItem } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

const STATUS_LABELS: Record<string, string> = {
  healthy: "Healthy",
  needs_attention: "Needs attention",
  closing_soon: "Closing soon",
  low_supply: "Low supply",
  unknown: "Unknown",
}

function statusVariant(status?: string): "default" | "secondary" | "destructive" | "outline" {
  if (status === "needs_attention" || status === "closing_soon") return "destructive"
  if (status === "healthy") return "default"
  return "outline"
}

export function OpportunityPerformanceSection({
  items,
  state,
  cityFilter,
  categoryFilter,
  statusFilter,
  onCityChange,
  onCategoryChange,
  onStatusChange,
  onRetry,
}: {
  items: OpportunityPerformanceItem[]
  state: PanelState
  cityFilter: string
  categoryFilter: string
  statusFilter: string
  onCityChange: (v: string) => void
  onCategoryChange: (v: string) => void
  onStatusChange: (v: string) => void
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-opportunity-section">
        <CardHeader>
          <CardTitle>Opportunity performance</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={3} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-opportunity-section">
        <CardContent className="pt-6">
          <ErrorState title="Opportunity data unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-opportunity-section">
      <CardHeader>
        <CardTitle>Opportunity performance</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid gap-2 sm:grid-cols-3">
          <Input
            placeholder="Filter city"
            value={cityFilter}
            onChange={(e) => onCityChange(e.target.value)}
            data-testid="ops-opp-filter-city"
          />
          <Input
            placeholder="Filter category"
            value={categoryFilter}
            onChange={(e) => onCategoryChange(e.target.value)}
          />
          <Input
            placeholder="Status (healthy, closing_soon…)"
            value={statusFilter}
            onChange={(e) => onStatusChange(e.target.value)}
          />
        </div>

        {items.length === 0 ? (
          <p className="text-sm text-muted-foreground">No opportunities match filters.</p>
        ) : (
          <div className="flex flex-col gap-2">
            {items.map((item) => (
              <div
                key={item.opportunityId}
                className="flex flex-col gap-2 rounded-2xl border p-4 sm:flex-row sm:items-center sm:justify-between"
                data-testid={`opp-row-${item.opportunityId}`}
              >
                <div>
                  <p className="font-medium">{item.title}</p>
                  <p className="text-xs text-muted-foreground">
                    {[item.city, item.state, item.eventDate].filter(Boolean).join(" · ")}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2 text-xs">
                  {item.views !== undefined && <span>{item.views} views</span>}
                  {item.saves !== undefined && <span>{item.saves} saves</span>}
                  {item.applications !== undefined && <span>{item.applications} apps</span>}
                  {item.paid !== undefined && <span>{item.paid} paid</span>}
                  <Badge variant={statusVariant(item.status)}>
                    {STATUS_LABELS[item.status ?? "unknown"]}
                  </Badge>
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  )
}
