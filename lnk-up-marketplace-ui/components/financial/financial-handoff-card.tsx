"use client"

import Link from "next/link"
import { ExternalLink } from "lucide-react"
import { getOrganizerFinancialUrl, getVendorDashboardPaymentsUrl } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export function FinancialHandoffCard({
  role = "vendor",
  url,
  title = "Financial details are managed on LNKup web",
  description = "Cashouts, refunds, and advanced payment settings are available in the LNKup Financial Center.",
  testId = "financial-handoff-card",
}: {
  role?: "vendor" | "organizer"
  url?: string
  title?: string
  description?: string
  testId?: string
}) {
  const handoffUrl =
    url ??
    (role === "organizer" ? getOrganizerFinancialUrl() : getVendorDashboardPaymentsUrl())

  function handleClick() {
    trackLnkupEvent({
      event: "financial_handoff_clicked",
      source: "financial_handoff_card",
      metadata: { role },
    })
    trackLnkupEvent({
      event: "wordpress_financial_center_opened",
      source: "financial_handoff_card",
      metadata: { role },
    })
  }

  return (
    <Card className="rounded-2xl border-dashed" data-testid={testId}>
      <CardContent className="flex flex-col items-center gap-3 py-8 text-center">
        <p className="text-sm font-semibold">{title}</p>
        <p className="max-w-md text-sm text-muted-foreground">{description}</p>
        {handoffUrl && (
          <Button
            variant="outline"
            nativeButton={false}
            render={
              <a href={handoffUrl} target="_blank" rel="noopener noreferrer" onClick={handleClick} />
            }
          >
            <ExternalLink data-icon="inline-start" />
            Open Financial Center
          </Button>
        )}
      </CardContent>
    </Card>
  )
}
