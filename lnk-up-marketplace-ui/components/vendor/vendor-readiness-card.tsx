"use client"

import Link from "next/link"
import { ShieldCheck, FileWarning, ChevronRight } from "lucide-react"
import { useVendorReadiness } from "@/lib/hooks/use-vendor-readiness"
import { usePanelState } from "@/lib/use-panel-state"
import { ProgressRing } from "@/components/marketplace/progress-ring"
import { ErrorState } from "@/components/marketplace/states"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Progress } from "@/components/ui/progress"
import { Skeleton } from "@/components/ui/skeleton"
import type { PanelState } from "@/lib/use-panel-state"
import { useMemo } from "react"

export function VendorReadinessCard() {
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { readiness, loading, error, unauthenticated, retry, isLive } = useVendorReadiness()
  const qaOverride = qaState !== "content"

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (isLive && loading) return "loading"
    if (isLive && error) return "error"
    if (!readiness) return "empty"
    return "content"
  }, [qaState, isLive, loading, error, readiness])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
  }

  if (panelState === "loading") {
    return (
      <Card className="rounded-2xl" data-testid="vendor-readiness-card">
        <CardContent className="flex items-center gap-4 p-4">
          <Skeleton className="size-[72px] shrink-0 rounded-full" />
          <div className="flex flex-1 flex-col gap-2">
            <Skeleton className="h-4 w-32" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-2 w-full" />
          </div>
        </CardContent>
      </Card>
    )
  }

  if (panelState === "error") {
    return (
      <div data-testid="vendor-readiness-error">
        <ErrorState
          title="We couldn't load your readiness details."
          description="Please try again in a moment."
          onRetry={handleRetry}
        />
      </div>
    )
  }

  if (panelState === "empty" || !readiness) {
    return (
      <Card className="rounded-2xl" data-testid="vendor-readiness-card">
        <CardContent className="flex flex-col gap-3 p-4">
          <p className="text-sm text-muted-foreground" data-testid="vendor-readiness-empty">
            Complete your profile so organizers can review you faster.
          </p>
          <Button size="sm" className="w-fit" nativeButton={false} render={<Link href="/vendor/profile" />}>
            Complete profile
          </Button>
        </CardContent>
      </Card>
    )
  }

  const topMissing = readiness.missingItems.slice(0, 3)
  const docsAttention =
    readiness.documentsCompletion !== undefined && readiness.documentsCompletion < 100
  const riskLabel =
    readiness.riskBucket === "green"
      ? "Green"
      : readiness.riskBucket === "amber"
        ? "Amber"
        : "Red"

  return (
    <Card className="rounded-2xl" data-testid="vendor-readiness-card">
      <CardContent className="flex flex-col gap-4 p-4">
        <div className="flex items-start gap-4">
          <ProgressRing value={readiness.profileCompletion} size={72} />
          <div className="flex flex-1 flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
              <span className="font-heading font-semibold">Profile readiness</span>
              <Badge
                variant={readiness.riskBucket === "red" ? "destructive" : "secondary"}
                className="text-xs"
              >
                {riskLabel} risk
              </Badge>
              {readiness.trustLevel && (
                <Badge variant="secondary" className="text-xs">
                  {readiness.trustLevel}
                </Badge>
              )}
            </div>
            <p className="text-sm text-muted-foreground">{readiness.headline}</p>
            <Progress value={readiness.profileCompletion} className="h-2" />
            {readiness.documentsCompletion !== undefined && (
              <p className="text-xs text-muted-foreground">
                Documents {readiness.documentsCompletion}% ready
                {docsAttention && " · Upload once. Reuse across events."}
              </p>
            )}
          </div>
        </div>

        {readiness.verifiedBadges && readiness.verifiedBadges.length > 0 && (
          <div className="flex flex-wrap gap-1.5">
            {readiness.verifiedBadges.map((badge) => (
              <Badge key={badge} variant="outline" className="gap-1">
                <ShieldCheck className="size-3" />
                {badge}
              </Badge>
            ))}
          </div>
        )}

        {topMissing.length > 0 && (
          <ul className="flex flex-col gap-1.5">
            {topMissing.map((item) => (
              <li key={item.id} className="flex items-center justify-between gap-2 text-sm">
                <span className="inline-flex items-center gap-2 text-muted-foreground">
                  <FileWarning className="size-3.5 shrink-0" />
                  {item.label}
                  {item.documentUrgency === "expired" ? (
                    <Badge variant="destructive" className="text-[10px]">
                      Expired
                    </Badge>
                  ) : item.documentUrgency === "expiring_soon" ? (
                    <Badge variant="secondary" className="text-[10px]">
                      Expiring soon
                    </Badge>
                  ) : null}
                </span>
                {item.actionHref && (
                  <Link
                    href={item.actionHref}
                    className="shrink-0 text-xs font-medium text-foreground hover:underline"
                  >
                    {item.actionLabel || "Fix"}
                  </Link>
                )}
              </li>
            ))}
          </ul>
        )}

        {readiness.nextAction && (
          <Button
            size="sm"
            className="w-fit"
            nativeButton={false}
            render={<Link href={readiness.nextAction.href} />}
          >
            {readiness.nextAction.label}
            <ChevronRight data-icon="inline-end" />
          </Button>
        )}

        {isLive && unauthenticated && !qaOverride && (
          <p className="text-xs text-muted-foreground">
            Sign in to sync your live profile readiness.
          </p>
        )}
      </CardContent>
    </Card>
  )
}
