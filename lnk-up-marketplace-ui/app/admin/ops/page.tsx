"use client"

import { useCallback, useEffect, useState } from "react"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent } from "@/components/ui/card"
import { Button, buttonVariants } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Skeleton } from "@/components/ui/skeleton"

type Severity = "critical" | "high" | "medium" | "low"

interface OpsItem {
  key: string
  source: string
  severity: Severity
  title: string
  detail: string
  age_hours: number
  count: number
  owner: { id: number; name: string } | null
  action: { label: string; url: string } | null
  state: "" | "dismissed" | "snoozed"
}

interface OpsQueue {
  items: OpsItem[]
  hidden: number
  counts: Record<Severity, number>
  mocked?: boolean
}

const SEVERITY_LABEL: Record<Severity, string> = {
  critical: "Critical",
  high: "High",
  medium: "Medium",
  low: "Low",
}

const SEVERITY_VARIANT: Record<Severity, "destructive" | "default" | "secondary" | "outline"> = {
  critical: "destructive",
  high: "default",
  medium: "secondary",
  low: "outline",
}

export default function AdminOpsPage() {
  const [queue, setQueue] = useState<OpsQueue | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [showHidden, setShowHidden] = useState(false)
  const [pendingKey, setPendingKey] = useState<string | null>(null)

  const load = useCallback(async () => {
    setError(null)
    try {
      const res = await fetch(`/api/admin/ops${showHidden ? "?hidden=1" : ""}`, { cache: "no-store" })
      if (!res.ok || !res.headers.get("content-type")?.includes("json")) throw new Error("unavailable")
      setQueue((await res.json()) as OpsQueue)
    } catch {
      setError("The operations queue is unavailable right now.")
    }
  }, [showHidden])

  useEffect(() => {
    void load()
  }, [load])

  const handleState = useCallback(
    async (key: string, state: "dismissed" | "snoozed" | "clear") => {
      setPendingKey(key)
      try {
        const res = await fetch("/api/admin/ops", {
          method: "POST",
          headers: { "content-type": "application/json" },
          body: JSON.stringify({ key, state, hours: 24 }),
        })
        if (!res.ok) throw new Error("failed")
        await load()
      } catch {
        setError("We couldn't update that item. Try again.")
      } finally {
        setPendingKey(null)
      }
    },
    [load],
  )

  return (
    <div className="flex flex-col gap-6" data-testid="admin-ops-page">
      <PageHeader
        title="Operations queue"
        subtitle="Exceptions across applications, payments, events, trust, messages, matching and partners."
      />

      {error ? (
        <Card className="rounded-2xl" data-testid="admin-ops-error">
          <CardContent className="flex items-center justify-between gap-3 p-4">
            <p className="text-sm text-muted-foreground">{error}</p>
            <Button size="sm" variant="outline" onClick={() => void load()}>
              Retry
            </Button>
          </CardContent>
        </Card>
      ) : null}

      {!queue && !error ? (
        <div className="flex flex-col gap-3" aria-busy="true">
          <Skeleton className="h-20 rounded-2xl" />
          <Skeleton className="h-20 rounded-2xl" />
        </div>
      ) : null}

      {queue ? (
        <>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            {(Object.keys(SEVERITY_LABEL) as Severity[]).map((severity) => (
              <Badge key={severity} variant={SEVERITY_VARIANT[severity]}>
                {SEVERITY_LABEL[severity]} · {queue.counts[severity] ?? 0}
              </Badge>
            ))}
            {queue.hidden > 0 || showHidden ? (
              <Button size="sm" variant="ghost" onClick={() => setShowHidden((v) => !v)}>
                {showHidden ? "Hide dismissed and snoozed" : `Show ${queue.hidden} dismissed or snoozed`}
              </Button>
            ) : null}
            {queue.mocked ? <span className="text-muted-foreground">Preview data</span> : null}
          </div>

          {queue.items.length === 0 ? (
            <Card className="rounded-2xl" data-testid="admin-ops-empty">
              <CardContent className="p-6 text-sm text-muted-foreground">Nothing needs attention right now.</CardContent>
            </Card>
          ) : (
            <ul className="flex flex-col gap-3" data-testid="admin-ops-list">
              {queue.items.map((item) => (
                <li key={item.key}>
                  <Card className="rounded-2xl">
                    <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                      <div className="flex min-w-0 flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant={SEVERITY_VARIANT[item.severity]}>{SEVERITY_LABEL[item.severity]}</Badge>
                          {item.state ? <Badge variant="outline">{item.state}</Badge> : null}
                          <span className="text-xs text-muted-foreground">{formatAge(item.age_hours)}</span>
                        </div>
                        <p className="font-medium">{item.title}</p>
                        {item.detail ? <p className="text-sm text-muted-foreground">{item.detail}</p> : null}
                        {item.owner ? <p className="text-xs text-muted-foreground">Owner: {item.owner.name}</p> : null}
                      </div>
                      <div className="flex shrink-0 flex-wrap gap-2">
                        {item.action?.url ? (
                          <a className={buttonVariants({ size: "sm" })} href={item.action.url} target="_blank" rel="noreferrer">
                            {item.action.label}
                          </a>
                        ) : null}
                        {item.state ? (
                          <Button size="sm" variant="outline" disabled={pendingKey === item.key} onClick={() => void handleState(item.key, "clear")}>
                            Restore
                          </Button>
                        ) : (
                          <>
                            <Button size="sm" variant="outline" disabled={pendingKey === item.key} onClick={() => void handleState(item.key, "snoozed")}>
                              Snooze a day
                            </Button>
                            <Button size="sm" variant="ghost" disabled={pendingKey === item.key} onClick={() => void handleState(item.key, "dismissed")}>
                              Dismiss
                            </Button>
                          </>
                        )}
                      </div>
                    </CardContent>
                  </Card>
                </li>
              ))}
            </ul>
          )}
        </>
      ) : null}
    </div>
  )
}

function formatAge(hours: number): string {
  if (hours <= 0) return "Now"
  if (hours >= 48) return `${Math.floor(hours / 24)} days`
  return `${Math.ceil(hours)} hours`
}
