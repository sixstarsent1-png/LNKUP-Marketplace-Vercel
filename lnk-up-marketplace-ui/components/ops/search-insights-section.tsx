"use client"

import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { SearchInsight } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"

export function SearchInsightsSection({
  insights,
  state,
  onRetry,
}: {
  insights: SearchInsight[]
  state: PanelState
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-search-insights-section">
        <CardHeader>
          <CardTitle>Search insights</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={2} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-search-insights-section">
        <CardContent className="pt-6">
          <ErrorState title="Search insights unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-search-insights-section">
      <CardHeader>
        <CardTitle>Search insights</CardTitle>
      </CardHeader>
      <CardContent>
        {insights.length === 0 ? (
          <p className="text-sm text-muted-foreground">No search insights for this range.</p>
        ) : (
          <ul className="flex flex-col gap-3">
            {insights.map((item, i) => (
              <li
                key={`${item.query ?? item.city ?? i}`}
                className="rounded-2xl border p-4"
                data-testid="search-insight-row"
              >
                <div className="flex flex-wrap items-center gap-2">
                  {item.query && <span className="font-medium">{item.query}</span>}
                  {item.city && <Badge variant="outline">{item.city}</Badge>}
                  {item.category && <Badge variant="secondary">{item.category}</Badge>}
                  {item.zeroResults && <Badge variant="destructive">Zero results</Badge>}
                  <span className="text-sm text-muted-foreground">{item.count} searches</span>
                </div>
                {item.suggestedAction && (
                  <p className="mt-2 text-sm text-muted-foreground">{item.suggestedAction}</p>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}
