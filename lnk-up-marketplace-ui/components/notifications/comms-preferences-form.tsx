"use client"

import { useState } from "react"
import type { CommsPreferenceCategory, CommsPreferences, CommsPreferencesUpdate, EmailMode } from "@/lib/api/comms"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

const EMAIL_LABELS: Record<EmailMode, string> = {
  immediate: "Immediately",
  daily: "Daily digest",
  weekly: "Weekly digest",
  off: "Off",
}

const START_TIMES = ["20:00", "21:00", "22:00", "23:00"]
const END_TIMES = ["06:00", "07:00", "08:00", "09:00"]

const selectClass =
  "h-9 rounded-lg border border-input bg-background px-3 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:opacity-50"

function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || ""
  } catch {
    return ""
  }
}

export function CommsPreferencesForm({
  comms,
  saving,
  onSave,
}: {
  comms: CommsPreferences
  saving?: boolean
  onSave: (update: CommsPreferencesUpdate, optimistic: CommsPreferences) => Promise<boolean>
}) {
  const [status, setStatus] = useState<{ ok: boolean; message: string } | null>(null)

  async function save(update: CommsPreferencesUpdate, optimistic: CommsPreferences) {
    const tz = browserTimezone()
    const ok = await onSave(tz && tz !== comms.timezone ? { ...update, timezone: tz } : update, optimistic)
    setStatus(ok ? { ok: true, message: "Saved" } : { ok: false, message: "We couldn't save that change. Please try again." })
  }

  function updateCategory(cat: CommsPreferenceCategory, change: { email?: EmailMode; push?: "on" | "off" }) {
    const categories = comms.categories.map((c) => (c.key === cat.key ? { ...c, ...change } : c))
    void save({ categories: [{ key: cat.key, ...change }] }, { ...comms, categories })
  }

  function updateQuiet(change: Partial<CommsPreferences["quietHours"]>) {
    const quietHours = { ...comms.quietHours, ...change }
    void save({ quietHours }, { ...comms, quietHours })
  }

  return (
    <div className="flex flex-col gap-4" data-testid="comms-preferences-form">
      <p className="text-sm text-muted-foreground">
        Urgent event, payment and account messages always reach your inbox. Push settings apply to the LNKup app on your phone.
      </p>

      {comms.categories.map((cat) => (
        <Card key={cat.key} className="rounded-2xl">
          <CardContent className="flex flex-col gap-3 py-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="min-w-0">
              <p className="flex items-center gap-2 font-semibold">
                {cat.label}
                {cat.promotional ? <Badge variant="secondary">Marketing</Badge> : null}
              </p>
              <p className="text-xs text-muted-foreground">{cat.description}</p>
            </div>
            {cat.locked ? (
              <span className="text-sm text-muted-foreground">Always on</span>
            ) : (
              <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2 text-sm">
                  <span className="text-muted-foreground">Email</span>
                  <select
                    className={selectClass}
                    value={cat.email}
                    disabled={saving}
                    onChange={(e) => updateCategory(cat, { email: e.target.value as EmailMode })}
                    data-testid={`comms-email-${cat.key}`}
                  >
                    {comms.emailModes.map((mode) => (
                      <option key={mode} value={mode}>
                        {EMAIL_LABELS[mode]}
                      </option>
                    ))}
                  </select>
                </label>
                <Button
                  size="sm"
                  variant={cat.push === "on" ? "default" : "outline"}
                  disabled={saving || !comms.pushEnabled}
                  aria-pressed={cat.push === "on"}
                  onClick={() => updateCategory(cat, { push: cat.push === "on" ? "off" : "on" })}
                  data-testid={`comms-push-${cat.key}`}
                >
                  Push {cat.push === "on" ? "on" : "off"}
                </Button>
              </div>
            )}
          </CardContent>
        </Card>
      ))}

      <Card className="rounded-2xl">
        <CardContent className="flex flex-col gap-3 py-4">
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="font-semibold">Push notifications</p>
              <p className="text-xs text-muted-foreground">Turn all app alerts on or off for your devices.</p>
            </div>
            <Button
              size="sm"
              variant={comms.pushEnabled ? "default" : "outline"}
              aria-pressed={comms.pushEnabled}
              disabled={saving}
              onClick={() => void save({ pushEnabled: !comms.pushEnabled }, { ...comms, pushEnabled: !comms.pushEnabled })}
            >
              {comms.pushEnabled ? "On" : "Off"}
            </Button>
          </div>
          <div className="flex items-center justify-between gap-3">
            <div>
              <p className="font-semibold">Quiet hours</p>
              <p className="text-xs text-muted-foreground">Non-urgent push alerts wait until quiet hours end.</p>
            </div>
            <Button
              size="sm"
              variant={comms.quietHours.enabled ? "default" : "outline"}
              aria-pressed={comms.quietHours.enabled}
              disabled={saving}
              onClick={() => updateQuiet({ enabled: !comms.quietHours.enabled })}
            >
              {comms.quietHours.enabled ? "On" : "Off"}
            </Button>
          </div>
          {comms.quietHours.enabled ? (
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <label className="flex items-center gap-2">
                From
                <select className={selectClass} value={comms.quietHours.start} disabled={saving} onChange={(e) => updateQuiet({ start: e.target.value })}>
                  {Array.from(new Set([...START_TIMES, comms.quietHours.start])).map((t) => (
                    <option key={t} value={t}>
                      {t}
                    </option>
                  ))}
                </select>
              </label>
              <label className="flex items-center gap-2">
                Until
                <select className={selectClass} value={comms.quietHours.end} disabled={saving} onChange={(e) => updateQuiet({ end: e.target.value })}>
                  {Array.from(new Set([...END_TIMES, comms.quietHours.end])).map((t) => (
                    <option key={t} value={t}>
                      {t}
                    </option>
                  ))}
                </select>
              </label>
              <span className="text-xs text-muted-foreground">Time zone: {browserTimezone() || comms.timezone}</span>
            </div>
          ) : null}
        </CardContent>
      </Card>

      {status ? (
        <p role="status" className={status.ok ? "text-sm font-medium" : "text-sm font-medium text-destructive"}>
          {status.message}
        </p>
      ) : null}
    </div>
  )
}
