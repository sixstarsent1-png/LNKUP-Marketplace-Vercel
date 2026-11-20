"use client"

import type { NotificationPreferences } from "@/lib/api/notifications"
import { WordPressSettingsHandoff } from "@/components/notifications/wordpress-settings-handoff"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"

export function NotificationPreferencesForm({
  preferences,
  onToggle,
  saving,
}: {
  preferences: NotificationPreferences
  onToggle?: (itemId: string, channel: string, enabled: boolean) => void
  saving?: boolean
}) {
  if (preferences.unavailable) {
    return (
      <WordPressSettingsHandoff
        role={preferences.role === "organizer" ? "organizer" : "vendor"}
        url={preferences.handoffUrl}
      />
    )
  }

  return (
    <div className="flex flex-col gap-4" data-testid="notification-preferences-form">
      {preferences.items.map((item) => (
        <Card key={item.id} className="rounded-2xl">
          <CardContent className="flex flex-col gap-3 py-4">
            <div>
              <p className="font-semibold">{item.label}</p>
              {item.description && (
                <p className="text-xs text-muted-foreground">{item.description}</p>
              )}
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              {(["email", "in_app", "sms", "push"] as const).map((channel) => {
                const locked = item.lockedChannels?.includes(channel)
                const isPush = channel === "push"
                const enabled = isPush ? false : item.channels[channel]
                return (
                  <div key={channel} className="flex items-center justify-between gap-2 rounded-xl bg-muted/50 px-3 py-2">
                    <span className="text-sm capitalize">
                      {isPush ? "Push (coming soon)" : channel.replace("_", " ")}
                    </span>
                    <Button
                      size="sm"
                      variant={enabled ? "default" : "outline"}
                      disabled={locked || saving || isPush}
                      onClick={() => onToggle?.(item.id, channel, !enabled)}
                      data-testid={`pref-${item.id}-${channel}`}
                    >
                      {enabled ? "On" : "Off"}
                    </Button>
                  </div>
                )
              })}
            </div>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
