"use client"

import Link from "next/link"
import { isLiveApiEnabled } from "@/lib/api/config"
import { PageHeader } from "@/components/marketplace/page-header"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import type { OpsAnalyticsRange } from "@/lib/ops/types"

const RANGES: OpsAnalyticsRange[] = ["24h", "7d", "30d", "all"]

export function OpsPageShell({
  range,
  onRangeChange,
  isMocked,
  isPartial,
  unavailableReasons,
  onRetry,
  children,
}: {
  range: OpsAnalyticsRange
  onRangeChange: (r: OpsAnalyticsRange) => void
  isMocked: boolean
  isPartial: boolean
  unavailableReasons: string[]
  onRetry: () => void
  children: React.ReactNode
}) {
  const live = isLiveApiEnabled()

  return (
    <div className="mx-auto max-w-6xl px-4 py-8" data-testid="ops-analytics-page">
      <PageHeader
        title="Launch analytics"
        subtitle="Internal command center for marketplace funnel, demand, and optimization."
        action={
          <div className="flex flex-wrap items-center gap-2">
            <Link href="/qa">
              <Button variant="outline" size="sm">
                QA dashboard
              </Button>
            </Link>
            <Button variant="outline" size="sm" onClick={onRetry}>
              Refresh
            </Button>
          </div>
        }
      />

      <div className="mb-6 flex flex-wrap items-center gap-2">
        {RANGES.map((r) => (
          <Button
            key={r}
            size="sm"
            variant={range === r ? "default" : "outline"}
            onClick={() => onRangeChange(r)}
            data-testid={`ops-range-${r}`}
          >
            {r}
          </Button>
        ))}
        {!live && (
          <Badge variant="secondary" data-testid="ops-mock-banner">
            Demo / mock data
          </Badge>
        )}
        {isMocked && live && (
          <Badge variant="secondary">Mock fallback</Badge>
        )}
        {isPartial && (
          <Badge variant="outline" data-testid="ops-partial-banner">
            Partial live data
          </Badge>
        )}
      </div>

      {unavailableReasons.length > 0 && (
        <div
          className="mb-6 rounded-2xl border border-dashed border-muted-foreground/30 bg-muted/30 p-4 text-sm text-muted-foreground"
          data-testid="ops-unavailable-notice"
        >
          <p className="font-medium text-foreground">Some metrics unavailable</p>
          <ul className="mt-2 list-inside list-disc">
            {unavailableReasons.map((r) => (
              <li key={r}>{r}</li>
            ))}
          </ul>
        </div>
      )}

      {children}
    </div>
  )
}
