"use client"

import Link from "next/link"
import { ExternalLink } from "lucide-react"
import { getWordPressSettingsHandoffUrl } from "@/lib/api/notifications"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export function WordPressSettingsHandoff({
  role = "vendor",
  url,
  title = "Notification delivery is managed on LNKup web.",
  description = "Email alerts, SMS, and advanced settings are configured in your LNKup dashboard.",
  testId = "wordpress-notification-handoff",
}: {
  role?: "vendor" | "organizer"
  url?: string
  title?: string
  description?: string
  testId?: string
}) {
  const handoffUrl = url ?? getWordPressSettingsHandoffUrl(role)

  function handleClick() {
    trackLnkupEvent({
      event: "wordpress_notification_settings_opened",
      source: testId,
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
            Open notification settings on LNKup web
          </Button>
        )}
        <Button variant="ghost" size="sm" nativeButton={false} render={<Link href="/notifications/preferences" />}>
          Manage saved search alerts
        </Button>
      </CardContent>
    </Card>
  )
}
