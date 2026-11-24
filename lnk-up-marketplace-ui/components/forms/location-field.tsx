"use client"

import { useCallback, useEffect, useId, useRef, useState } from "react"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Button } from "@/components/ui/button"

/** One validated place from the WordPress Location Service. */
export interface LocationSuggestion {
  city: string
  state_code: string
  display: string
  label?: string
}

export type LocationStatus = "" | "checking" | "verified" | "normalized" | "suggested" | "ambiguous" | "unresolved"

interface LocationResolveResult {
  status: Exclude<LocationStatus, "" | "checking">
  city: string
  state_code: string
  message?: string
  suggestions?: LocationSuggestion[]
}

export interface LocationFieldProps {
  city: string
  state: string
  onChange: (value: { city: string; state: string }) => void
  /** Reports whether the current pair is safe to submit. */
  onStatusChange?: (status: LocationStatus) => void
  required?: boolean
  /** Server-provided suggestions after a 422 from a save. */
  externalSuggestions?: LocationSuggestion[]
  externalMessage?: string
}

const OK_STATUSES: LocationStatus[] = ["verified", "normalized"]

export function isLocationSubmittable(status: LocationStatus): boolean {
  return status === "" || OK_STATUSES.includes(status)
}

/**
 * City + state entry backed by /api/locations/* (WordPress Location Service). Autocompletes
 * "Atlanta", "atlanta ga", "Charlotte North Carolina"; asks instead of guessing for
 * "Atlanta, GE" or "Columbus". WordPress re-validates on save.
 */
export function LocationField({
  city,
  state,
  onChange,
  onStatusChange,
  required = false,
  externalSuggestions,
  externalMessage,
}: LocationFieldProps) {
  const listId = useId()
  const [results, setResults] = useState<LocationSuggestion[]>([])
  const [open, setOpen] = useState(false)
  const [active, setActive] = useState(-1)
  const [status, setStatus] = useState<LocationStatus>(city || state ? "checking" : "")
  const [message, setMessage] = useState<string>("")
  const [suggestions, setSuggestions] = useState<LocationSuggestion[]>([])
  const suggestTimer = useRef<ReturnType<typeof setTimeout> | null>(null)
  const resolveTimer = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateStatus = useCallback(
    (next: LocationStatus) => {
      setStatus(next)
      onStatusChange?.(next)
    },
    [onStatusChange],
  )

  const resolve = useCallback(
    (nextCity: string, nextState: string) => {
      if (resolveTimer.current) clearTimeout(resolveTimer.current)
      if (!nextCity.trim() && !nextState.trim()) {
        updateStatus("")
        setMessage("")
        setSuggestions([])
        return
      }
      updateStatus("checking")
      resolveTimer.current = setTimeout(async () => {
        try {
          const params = new URLSearchParams({ city: nextCity, state: nextState })
          const res = await fetch(`/api/locations/resolve?${params.toString()}`)
          if (!res.ok) throw new Error("lookup_failed")
          const data = (await res.json()) as LocationResolveResult
          updateStatus(data.status)
          if (OK_STATUSES.includes(data.status)) {
            setMessage("")
            setSuggestions([])
            if (data.city && (data.city !== nextCity || data.state_code !== nextState)) {
              onChange({ city: data.city, state: data.state_code })
            }
          } else {
            setMessage(data.message ?? "Choose a city and state from the list.")
            setSuggestions(data.suggestions ?? [])
          }
        } catch {
          // Lookup unavailable: WordPress still validates the location on save.
          updateStatus("")
        }
      }, 250)
    },
    [onChange, updateStatus],
  )

  useEffect(() => {
    if (city || state) resolve(city, state)
    // Validate the initial value once on mount.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(
    () => () => {
      if (suggestTimer.current) clearTimeout(suggestTimer.current)
      if (resolveTimer.current) clearTimeout(resolveTimer.current)
    },
    [],
  )

  function handleCityInput(value: string) {
    onChange({ city: value, state })
    updateStatus("checking")
    if (suggestTimer.current) clearTimeout(suggestTimer.current)
    if (value.trim().length < 2) {
      setResults([])
      setOpen(false)
      return
    }
    suggestTimer.current = setTimeout(async () => {
      try {
        const q = `${value} ${state}`.trim()
        const res = await fetch(`/api/locations/suggest?q=${encodeURIComponent(q)}&limit=8`)
        if (!res.ok) throw new Error("lookup_failed")
        const data = (await res.json()) as { results?: LocationSuggestion[] }
        setResults(data.results ?? [])
        setOpen((data.results ?? []).length > 0)
        setActive(-1)
      } catch {
        setResults([])
        setOpen(false)
      }
    }, 220)
  }

  function choose(place: LocationSuggestion) {
    onChange({ city: place.city, state: place.state_code })
    setOpen(false)
    setResults([])
    setMessage("")
    setSuggestions([])
    updateStatus("verified")
  }

  function handleKeyDown(event: React.KeyboardEvent<HTMLInputElement>) {
    if (!open || results.length === 0) return
    if (event.key === "ArrowDown") {
      event.preventDefault()
      setActive((i) => Math.min(results.length - 1, i + 1))
    } else if (event.key === "ArrowUp") {
      event.preventDefault()
      setActive((i) => Math.max(0, i - 1))
    } else if (event.key === "Enter" && active >= 0) {
      event.preventDefault()
      choose(results[active])
    } else if (event.key === "Escape") {
      setOpen(false)
    }
  }

  const shownSuggestions = externalSuggestions?.length ? externalSuggestions : suggestions
  const shownMessage = externalMessage || message

  return (
    <div className="flex flex-col gap-2 sm:col-span-2" data-testid="location-field">
      <div className="grid gap-4 sm:grid-cols-[2fr_1fr]">
        <div className="relative flex flex-col gap-2">
          <Label htmlFor={`${listId}-city`}>City{required ? " *" : ""}</Label>
          <Input
            id={`${listId}-city`}
            value={city}
            autoComplete="off"
            role="combobox"
            aria-autocomplete="list"
            aria-expanded={open}
            aria-controls={`${listId}-list`}
            aria-activedescendant={active >= 0 ? `${listId}-opt-${active}` : undefined}
            placeholder="Start typing a city"
            onChange={(e) => handleCityInput(e.target.value)}
            onKeyDown={handleKeyDown}
            onBlur={() => {
              setTimeout(() => setOpen(false), 150)
              resolve(city, state)
            }}
          />
          {open && (
            <ul
              id={`${listId}-list`}
              role="listbox"
              className="absolute top-full z-50 mt-1 max-h-64 w-full overflow-auto rounded-xl border bg-popover p-1 shadow-lg"
            >
              {results.map((place, index) => (
                <li
                  key={`${place.city}-${place.state_code}`}
                  id={`${listId}-opt-${index}`}
                  role="option"
                  aria-selected={index === active}
                  className="cursor-pointer rounded-lg px-3 py-2 text-sm hover:bg-muted aria-selected:bg-muted"
                  onMouseDown={(e) => {
                    e.preventDefault()
                    choose(place)
                  }}
                >
                  {place.label ?? place.display}
                </li>
              ))}
            </ul>
          )}
        </div>
        <div className="flex flex-col gap-2">
          <Label htmlFor={`${listId}-state`}>State{required ? " *" : ""}</Label>
          <Input
            id={`${listId}-state`}
            value={state}
            maxLength={2}
            autoComplete="address-level1"
            onChange={(e) => {
              const next = e.target.value.toUpperCase()
              onChange({ city, state: next })
              resolve(city, next)
            }}
          />
        </div>
      </div>
      {shownMessage && !isLocationSubmittable(status) && (
        <div
          role="status"
          aria-live="polite"
          className="flex flex-col gap-2 rounded-xl border border-warning/40 bg-warning/10 p-3 text-sm"
          data-testid="location-field-suggestion"
        >
          <p>{shownMessage}</p>
          {shownSuggestions.length > 0 && (
            <div className="flex flex-wrap gap-2">
              {shownSuggestions.slice(0, 4).map((place) => (
                <Button
                  key={`${place.city}-${place.state_code}`}
                  type="button"
                  size="sm"
                  variant="outline"
                  onClick={() => choose(place)}
                >
                  Use {place.display}
                </Button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
