"use client"

import { useState } from "react"
import Link from "next/link"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { Bookmark } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import type { SavedSearchAlertFrequency, SavedSearchFilters } from "@/lib/api/saved-searches"

const FREQUENCY_OPTIONS: { value: SavedSearchAlertFrequency; label: string }[] = [
  { value: "none", label: "No alerts" },
  { value: "instant", label: "Instant" },
  { value: "daily", label: "Daily" },
  { value: "weekly", label: "Weekly" },
]

type SaveSearchDialogProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  filters: SavedSearchFilters
  isAuthenticated: boolean
  saving?: boolean
  onSave: (name: string, frequency: SavedSearchAlertFrequency) => Promise<void>
}

export function SaveSearchDialog({
  open,
  onOpenChange,
  filters,
  isAuthenticated,
  saving,
  onSave,
}: SaveSearchDialogProps) {
  const [name, setName] = useState("")
  const [frequency, setFrequency] = useState<SavedSearchAlertFrequency>("weekly")
  const [error, setError] = useState<string | null>(null)

  async function handleSave() {
    setError(null)
    const trimmed = name.trim() || "Saved search"
    try {
      await onSave(trimmed, frequency)
      trackLnkupEvent({
        event: "save_search_clicked",
        source: "vendor_find",
        metadata: {
          city: filters.city ?? null,
          category: filters.category ?? null,
          q: filters.q ?? null,
        },
      })
      trackLnkupEvent({
        event: "saved_search_created",
        source: "vendor_find",
        metadata: { frequency },
      })
      setName("")
      onOpenChange(false)
    } catch (err) {
      setError(err instanceof Error ? err.message : "Could not save search.")
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md" data-testid="save-search-dialog">
        {!isAuthenticated ? (
          <>
            <DialogHeader>
              <DialogTitle>Sign in to save searches</DialogTitle>
              <DialogDescription>
                Saved searches help you track new event opportunities.
              </DialogDescription>
            </DialogHeader>
            <div className="flex flex-col gap-2">
              <Button nativeButton={false} render={<Link href="/login?redirect=/vendor/find" />}>
                Log in
              </Button>
              <Button variant="outline" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            </div>
          </>
        ) : (
          <>
            <DialogHeader>
              <DialogTitle>Save this search</DialogTitle>
              <DialogDescription>
                We will notify you when new opportunities match these filters.
              </DialogDescription>
            </DialogHeader>
            <div className="flex flex-col gap-3">
              <div className="flex flex-col gap-2">
                <Label htmlFor="search-name">Search name</Label>
                <Input
                  id="search-name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder={
                    filters.city
                      ? `Events in ${filters.city}`
                      : filters.q
                        ? `"${filters.q}"`
                        : "My saved search"
                  }
                />
              </div>
              <div className="flex flex-col gap-2">
                <Label>Alert frequency</Label>
                <Select
                  value={frequency}
                  onValueChange={(v) => setFrequency(v as SavedSearchAlertFrequency)}
                >
                  <SelectTrigger data-testid="save-search-frequency">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {FREQUENCY_OPTIONS.map((opt) => (
                      <SelectItem key={opt.value} value={opt.value}>
                        {opt.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              {(filters.q || filters.city || filters.category) && (
                <p className="text-xs text-muted-foreground">
                  {[
                    filters.q && `Query: ${filters.q}`,
                    filters.city && `City: ${filters.city}`,
                    filters.category && `Category: ${filters.category}`,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </p>
              )}
              {error && <p className="text-sm text-destructive">{error}</p>}
              <Button onClick={handleSave} disabled={saving} data-testid="save-search-submit">
                {saving ? "Saving…" : "Save search"}
              </Button>
            </div>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

export function SaveSearchButton({
  filters,
  isAuthenticated,
  onSave,
  saving,
  open: controlledOpen,
  onOpenChange: controlledOnOpenChange,
}: {
  filters: SavedSearchFilters
  isAuthenticated: boolean
  saving?: boolean
  onSave: (name: string, frequency: SavedSearchAlertFrequency) => Promise<void>
  open?: boolean
  onOpenChange?: (open: boolean) => void
}) {
  const [internalOpen, setInternalOpen] = useState(false)
  const open = controlledOpen ?? internalOpen
  const setOpen = controlledOnOpenChange ?? setInternalOpen

  return (
    <>
      <Button
        variant="outline"
        size="sm"
        onClick={() => setOpen(true)}
        data-testid="save-search-button"
      >
        <Bookmark data-icon="inline-start" />
        Save search
      </Button>
      <SaveSearchDialog
        open={open}
        onOpenChange={setOpen}
        filters={filters}
        isAuthenticated={isAuthenticated}
        saving={saving}
        onSave={onSave}
      />
    </>
  )
}
