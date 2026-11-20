"use client"

import { List, Map } from "lucide-react"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

export function MapListToggle({
  view,
  onChange,
}: {
  view: "list" | "map"
  onChange: (view: "list" | "map") => void
}) {
  return (
    <div className="flex rounded-xl border p-0.5" data-testid="map-list-toggle">
      <Button
        type="button"
        size="sm"
        variant={view === "list" ? "default" : "ghost"}
        className="h-8 rounded-lg px-3"
        onClick={() => onChange("list")}
        aria-pressed={view === "list"}
      >
        <List data-icon="inline-start" />
        List
      </Button>
      <Button
        type="button"
        size="sm"
        variant={view === "map" ? "default" : "ghost"}
        className={cn("h-8 rounded-lg px-3")}
        onClick={() => onChange("map")}
        aria-pressed={view === "map"}
        data-testid="map-view-toggle"
      >
        <Map data-icon="inline-start" />
        Map
      </Button>
    </div>
  )
}
