/**
 * WordPress sends event dates as Y-m-d calendar days. `new Date("2026-12-30")` is UTC midnight,
 * which renders as Dec 29 in US time zones — plain dates are parsed as local calendar days.
 * Timestamps with a time part keep normal Date parsing.
 */
export function parseCalendarDate(value: string): Date | null {
  const trimmed = value.trim()
  const plain = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})$/)
  if (plain) return new Date(Number(plain[1]), Number(plain[2]) - 1, Number(plain[3]))
  const mysql = trimmed.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/)
  if (mysql) {
    return new Date(
      Number(mysql[1]),
      Number(mysql[2]) - 1,
      Number(mysql[3]),
      Number(mysql[4]),
      Number(mysql[5]),
      Number(mysql[6] ?? 0),
    )
  }
  const parsed = new Date(trimmed)
  return Number.isNaN(parsed.getTime()) ? null : parsed
}

/** "Dec 30, 2026" (or the raw value when it isn't a date). */
export function formatShortDate(value?: string | null): string | undefined {
  if (!value) return undefined
  const parsed = parseCalendarDate(value)
  if (!parsed) return value
  return parsed.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })
}

/** "Dec 30, 2026, 3:05 PM". GMT timestamps (`...Z` or `gmt` true) are shown in local time. */
export function formatDateTime(value?: string | null, { gmt = false }: { gmt?: boolean } = {}): string | undefined {
  if (!value) return undefined
  const normalized = gmt && /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/.test(value.trim()) ? `${value.trim().replace(" ", "T")}Z` : value
  const parsed = gmt ? new Date(normalized) : parseCalendarDate(value)
  if (!parsed || Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleString("en-US", { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" })
}

/** "3:05 PM" for a check-in time on the event day. */
export function formatTime(value?: string | null): string | undefined {
  if (!value) return undefined
  const parsed = parseCalendarDate(value)
  if (!parsed) return value
  return parsed.toLocaleTimeString("en-US", { hour: "numeric", minute: "2-digit" })
}

/** "Today", "Tomorrow", "In 5 days", "3 days ago" relative to a Y-m-d calendar day. */
export function formatRelativeDay(value?: string | null, now: Date = new Date()): string | undefined {
  if (!value) return undefined
  const parsed = parseCalendarDate(value)
  if (!parsed) return undefined
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  const day = new Date(parsed.getFullYear(), parsed.getMonth(), parsed.getDate())
  const diff = Math.round((day.getTime() - start.getTime()) / 86_400_000)
  if (diff === 0) return "Today"
  if (diff === 1) return "Tomorrow"
  if (diff === -1) return "Yesterday"
  return diff > 0 ? `In ${diff} days` : `${Math.abs(diff)} days ago`
}

/** Local Y-m-d for "today" comparisons against WordPress calendar days. */
export function localIsoDay(date: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, "0")
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
