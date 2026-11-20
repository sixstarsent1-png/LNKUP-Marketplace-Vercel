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

/** Local Y-m-d for "today" comparisons against WordPress calendar days. */
export function localIsoDay(date: Date = new Date()): string {
  const pad = (n: number) => String(n).padStart(2, "0")
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}
