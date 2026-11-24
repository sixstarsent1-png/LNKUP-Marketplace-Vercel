import type { OrganizerEventLite, OrganizerEventOpportunity } from "@/lib/api/organizer-events"

export type PublishChecklistItem = {
  key: string
  label: string
  done: boolean
  required: boolean
  tab: "overview" | "inventory"
}

/** What a draft needs before its slots open: date, place, and a priced slot (description optional). */
export function publishChecklist(event: OrganizerEventLite, opportunities: OrganizerEventOpportunity[]): PublishChecklistItem[] {
  const priced = opportunities.some((opp) => opp.vendorFee != null && (opp.spotsTotal ?? 0) > 0)
  return [
    { key: "date", label: "Event date", done: Boolean(event.date), required: true, tab: "overview" },
    { key: "place", label: "City and state", done: Boolean(event.city && event.state), required: true, tab: "overview" },
    { key: "slot", label: "A vendor slot with a fee and spots", done: priced, required: true, tab: "inventory" },
    { key: "description", label: "Event description", done: Boolean(event.description?.trim()), required: false, tab: "overview" },
  ]
}

/**
 * Destination for an event-score factor action. "tab:<key>" switches the event detail tab;
 * anything else is a route.
 */
export function scoreActionHref(target: string, eventId: string): string | null {
  switch (target) {
    case "recruit":
      return `/(organizer)/vendors/find?eventId=${eventId}`
    case "review":
      return "tab:vendors"
    case "money":
      return `/(organizer)/money/event?eventId=${eventId}`
    case "operations":
      return `/(organizer)/events/operations?eventId=${eventId}`
    case "checkin":
      return `/(organizer)/events/checkin?eventId=${eventId}`
    case "share":
      return "tab:overview"
    default:
      return null
  }
}
