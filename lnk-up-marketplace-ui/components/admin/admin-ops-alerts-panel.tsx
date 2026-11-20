"use client"

import Link from "next/link"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"
import type { AdminOpsAlert } from "@/lib/admin/ops-alerts"
import type { ExperimentRecommendation } from "@/lib/ops/experimentation-contract"
import { recommendationsForAlert } from "@/lib/admin/ops-alerts"

export function AdminOpsAlertsPanel({
  alerts,
  recommendations,
}: {
  alerts: AdminOpsAlert[]
  recommendations: ExperimentRecommendation[]
}) {
  return (
    <Card className="rounded-2xl" data-testid="admin-ops-alerts">
      <CardHeader>
        <CardTitle className="text-base">Ops alerts → recommendations</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {alerts.length === 0 ? (
          <p className="text-sm text-muted-foreground">No queue alerts — Admin Lite looks clear.</p>
        ) : (
          alerts.map((alert) => {
            const linked = recommendationsForAlert(alert, recommendations)
            return (
              <div key={alert.id} className="rounded-xl border p-4" data-testid={`admin-alert-${alert.id}`}>
                <div className="flex flex-wrap items-center gap-2">
                  <Badge variant={alert.severity === "urgent" ? "destructive" : "secondary"}>
                    {alert.severity}
                  </Badge>
                  <span className="font-medium">{alert.title}</span>
                </div>
                <p className="mt-2 text-sm text-muted-foreground">{alert.description}</p>
                {alert.suggestedAction ? (
                  <p className="mt-1 text-sm font-medium">{alert.suggestedAction}</p>
                ) : null}
                {alert.href ? (
                  <Link href={alert.href} className="mt-2 inline-block text-sm underline">
                    Open queue
                  </Link>
                ) : null}
                {linked.length > 0 ? (
                  <ul className="mt-3 flex flex-col gap-2 border-t pt-3">
                    {linked.map((rec) => (
                      <li key={rec.id} className="text-sm">
                        <span className="font-medium">{rec.candidateAction}</span>
                        <span className="text-muted-foreground"> · {rec.issue}</span>
                        {rec.href ? (
                          <Link href={rec.href} className="ml-2 underline">
                            View
                          </Link>
                        ) : (
                          <Link href="/ops/analytics" className="ml-2 underline">
                            Ops analytics
                          </Link>
                        )}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            )
          })
        )}
      </CardContent>
    </Card>
  )
}
