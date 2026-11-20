"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { MarketDemandItem } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

function demandBadge(item: MarketDemandItem) {
  if (item.supplyGap) return <Badge variant="destructive">Low supply</Badge>
  if ((item.demandScore ?? 0) >= 80) return <Badge>High demand</Badge>
  if ((item.searches ?? 0) >= 50) return <Badge variant="secondary">Growing</Badge>
  if (item.supplyGap) return <Badge variant="outline">Needs events</Badge>
  return null
}

export function MarketDemandSection({
  markets,
  state,
  onRetry,
}: {
  markets: MarketDemandItem[]
  state: PanelState
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-market-demand-section">
        <CardHeader>
          <CardTitle>Markets</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={2} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-market-demand-section">
        <CardContent className="pt-6">
          <ErrorState title="Market demand unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-market-demand-section">
      <CardHeader>
        <CardTitle>Markets</CardTitle>
      </CardHeader>
      <CardContent>
        {markets.length === 0 ? (
          <p className="text-sm text-muted-foreground">No market demand data.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="pb-2 pr-4">Market</th>
                  <th className="pb-2 pr-4">Searches</th>
                  <th className="pb-2 pr-4">Opps</th>
                  <th className="pb-2 pr-4">Apps</th>
                  <th className="pb-2">Status</th>
                </tr>
              </thead>
              <tbody>
                {markets.map((m) => (
                  <tr key={`${m.type}-${m.label}`} className="border-b border-border/50">
                    <td className="py-3 pr-4">
                      <span className="font-medium">{m.label}</span>
                      <span className="ml-2 text-xs text-muted-foreground">{m.type}</span>
                    </td>
                    <td className="py-3 pr-4">{m.searches ?? "—"}</td>
                    <td className="py-3 pr-4">{m.opportunities ?? "—"}</td>
                    <td className="py-3 pr-4">{m.applications ?? "—"}</td>
                    <td className="py-3">{demandBadge(m)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
