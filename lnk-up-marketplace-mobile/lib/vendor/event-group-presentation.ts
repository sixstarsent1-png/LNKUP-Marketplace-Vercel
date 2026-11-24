import type { EventGroup, EventOption } from "@/lib/api/event-groups"
import { formatMoney } from "@/lib/api/format-money"
import { formatShortDate, localIsoDay } from "@/lib/format/date"

const CLOSING_SOON_DAYS = 7

function daysUntil(iso: string, today: string): number {
  const a = Date.parse(`${today}T00:00:00Z`)
  const b = Date.parse(`${iso}T00:00:00Z`)
  return Math.round((b - a) / 86_400_000)
}

/** "From $300" for the event (or for the filtered category); null when pricing is hidden or unset. */
export function groupPriceLabel(group: Pick<EventGroup, "gated" | "fromPrice" | "priceMax">): string | null {
  if (group.gated) return "Sign in for pricing"
  if (group.fromPrice == null) return null
  if (group.priceMax != null && group.priceMax > group.fromPrice) return `From ${formatMoney(group.fromPrice)}`
  return formatMoney(group.fromPrice)
}

/** Category summary: "Art Vendor · Clothing Brand" or "Art Vendor +3 more". */
export function groupCategoryLabel(categories: string[], max = 2): string | null {
  if (categories.length === 0) return null
  if (categories.length <= max) return categories.join(" · ")
  return `${categories.slice(0, max).join(" · ")} +${categories.length - max} more`
}

export type AvailabilityNote = { label: string; tone: "ok" | "low" | "closed" }

/**
 * Event-level availability without adding unrelated capacities together: an event is only
 * "Full" when no listed option is open.
 */
export function groupAvailability(group: EventGroup): AvailabilityNote | null {
  if (group.gated) return null
  if (group.openOptions === 0 && group.totalOptions > 0) return { label: "No open vendor spaces", tone: "closed" }
  if (group.totalOptions > 1) {
    const full = group.fullOptions ?? 0
    if (full > 0) return { label: `${group.openOptions} of ${group.totalOptions} vendor options open`, tone: "low" }
    return { label: `${group.totalOptions} vendor options`, tone: "ok" }
  }
  const only = group.options[0]
  return only ? optionAvailability(only) : null
}

/** "5 of 5 spaces available", "2 spaces left", "Open spaces", or "Full". */
export function optionAvailability(option: EventOption): AvailabilityNote | null {
  if (option.spotsUnlimited) return { label: "Open spaces", tone: "ok" }
  if (option.spotsRemaining == null || option.spotsTotal == null) return null
  if (option.spotsRemaining <= 0) return { label: "Full", tone: "closed" }
  const low = option.spotsRemaining <= Math.max(2, Math.ceil(option.spotsTotal * 0.2))
  if (low) return { label: `${option.spotsRemaining} space${option.spotsRemaining === 1 ? "" : "s"} left`, tone: "low" }
  return { label: `${option.spotsRemaining} of ${option.spotsTotal} spaces available`, tone: "ok" }
}

/** "Apply by Nov 25", with urgency only inside a week; null when the deadline has passed. */
export function deadlineLabel(deadlineIso: string | undefined, today = localIsoDay()): { label: string; urgent: boolean } | null {
  if (!deadlineIso) return null
  const days = daysUntil(deadlineIso, today)
  if (days < 0) return null
  if (days === 0) return { label: "Applications close today", urgent: true }
  const date = formatShortDate(deadlineIso) ?? deadlineIso
  return { label: `Apply by ${date}`, urgent: days <= CLOSING_SOON_DAYS }
}

/** Option price; never shows another category's price. */
export function optionPriceLabel(option: EventOption, gated: boolean): string {
  if (gated) return "Sign in for pricing"
  if (option.vendorFee == null || option.vendorFee <= 0) return "Fee set by organizer"
  return formatMoney(option.vendorFee)
}

/** Plain-language environment ("Indoor and outdoor areas"), or null. */
export function environmentLabel(indoor: boolean, outdoor: boolean): string | null {
  if (indoor && outdoor) return "Indoor and outdoor areas"
  if (indoor) return "Indoor"
  if (outdoor) return "Outdoor"
  return null
}

/** Accessible one-line summary of a grouped card. */
export function groupAccessibilityLabel(group: EventGroup): string {
  return [
    group.title,
    group.dateLabel,
    group.place,
    groupPriceLabel(group),
    groupCategoryLabel(group.categories, 4),
    groupAvailability(group)?.label,
  ]
    .filter(Boolean)
    .join(", ")
}
