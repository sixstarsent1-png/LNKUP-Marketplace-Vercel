"use client"

import Link from "next/link"
import { Play, Pause, Trash2, Search } from "lucide-react"
import type { SavedSearch, SavedSearchAlertFrequency } from "@/lib/api/saved-searches"
import { savedSearchToFindUrl } from "@/lib/api/saved-searches"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"

const FREQUENCIES: SavedSearchAlertFrequency[] = ["none", "instant", "daily", "weekly"]

export function SavedSearchAlertCard({
  search,
  onFrequencyChange,
  onPause,
  onResume,
  onRun,
  onDelete,
  busy,
}: {
  search: SavedSearch
  onFrequencyChange?: (id: string, frequency: SavedSearchAlertFrequency) => void
  onPause?: (id: string) => void
  onResume?: (id: string) => void
  onRun?: (id: string) => void
  onDelete?: (id: string) => void
  busy?: boolean
}) {
  return (
    <Card className="rounded-2xl" data-testid={`saved-search-alert-${search.id}`}>
      <CardContent className="flex flex-col gap-3 py-4">
        <div className="flex items-start justify-between gap-2">
          <div>
            <p className="font-semibold">{search.name}</p>
            <p className="text-xs text-muted-foreground">{search.filterSummary ?? "Saved filters"}</p>
          </div>
          {search.isPaused && <Badge variant="secondary">Paused</Badge>}
          {(search.newMatches ?? 0) > 0 && (
            <Badge variant="default">{search.newMatches} new</Badge>
          )}
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <Select
            value={String(search.alertFrequency ?? "weekly")}
            onValueChange={(value) => {
              trackLnkupEvent({
                event: "saved_search_alert_frequency_changed",
                source: "saved_search_alert_card",
                metadata: { frequency: value },
              })
              onFrequencyChange?.(search.id, value as SavedSearchAlertFrequency)
            }}
            disabled={busy}
          >
            <SelectTrigger className="w-[140px]" data-testid="saved-search-frequency">
              <SelectValue placeholder="Alerts" />
            </SelectTrigger>
            <SelectContent>
              {FREQUENCIES.map((f) => (
                <SelectItem key={f} value={f}>
                  {f === "none" ? "No alerts" : f.charAt(0).toUpperCase() + f.slice(1)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          {search.isPaused ? (
            <Button
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={() => {
                trackLnkupEvent({ event: "saved_search_resumed", source: "saved_search_alert_card" })
                onResume?.(search.id)
              }}
              data-testid="saved-search-resume"
            >
              <Play data-icon="inline-start" className="size-3" />
              Resume
            </Button>
          ) : (
            <Button
              size="sm"
              variant="outline"
              disabled={busy}
              onClick={() => {
                trackLnkupEvent({ event: "saved_search_paused", source: "saved_search_alert_card" })
                onPause?.(search.id)
              }}
              data-testid="saved-search-pause"
            >
              <Pause data-icon="inline-start" className="size-3" />
              Pause
            </Button>
          )}

          <Button
            size="sm"
            variant="outline"
            disabled={busy}
            onClick={() => onRun?.(search.id)}
          >
            <Search data-icon="inline-start" className="size-3" />
            Run
          </Button>

          <Button size="sm" variant="ghost" nativeButton={false} render={<Link href={savedSearchToFindUrl(search)} />}>
            Open
          </Button>

          {onDelete && (
            <Button size="sm" variant="ghost" disabled={busy} onClick={() => onDelete(search.id)}>
              <Trash2 className="size-4 text-muted-foreground" />
            </Button>
          )}
        </div>

        {(search.lastMatchCount != null || search.lastRunAt) && (
          <p className="text-xs text-muted-foreground">
            {search.lastMatchCount != null ? `${search.lastMatchCount} matches last run` : ""}
            {search.lastRunAt ? ` · Last run ${search.lastRunAt}` : ""}
          </p>
        )}
      </CardContent>
    </Card>
  )
}
