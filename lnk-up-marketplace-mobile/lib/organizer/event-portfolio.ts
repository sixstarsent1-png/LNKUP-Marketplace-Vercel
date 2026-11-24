import type { OrgEventCard, OrgEventFilters } from "@/lib/api/organizer-mobile"

export type PortfolioFilter = "all" | "upcoming" | "open" | "draft" | "past" | "tour"
export type PortfolioSort = "date" | "needs_review" | "underfilled" | "outstanding"

export const PORTFOLIO_SORTS: { id: PortfolioSort; label: string }[] = [
  { id: "date", label: "Event date" },
  { id: "needs_review", label: "Needs review" },
  { id: "underfilled", label: "Most open booths" },
  { id: "outstanding", label: "Unpaid booth fees" },
]

/** Server filters for a portfolio chip. "tour" is applied on the client (any tour). */
export function serverFilters(filter: PortfolioFilter, q: string): OrgEventFilters {
  const base: OrgEventFilters = { q }
  switch (filter) {
    case "all":
    case "tour":
      return base
    case "upcoming":
      return { ...base, chronology: "upcoming" }
    case "open":
      return { ...base, applications: "open", publication: "live" }
    case "draft":
      return { ...base, publication: "draft" }
    case "past":
      return { ...base, chronology: "completed" }
    default: {
      const exhaustive: never = filter
      return exhaustive
    }
  }
}

function fillRatio(event: OrgEventCard): number {
  return event.capacity.total > 0 ? event.capacity.confirmed / event.capacity.total : 1
}

/** Client sort over server rows (server order is already event date). */
export function sortPortfolio(events: OrgEventCard[], sort: PortfolioSort): OrgEventCard[] {
  switch (sort) {
    case "date":
      return events
    case "needs_review":
      return [...events].sort((a, b) => b.applications.pending - a.applications.pending)
    case "underfilled":
      return [...events].sort((a, b) => fillRatio(a) - fillRatio(b) || b.capacity.open - a.capacity.open)
    case "outstanding":
      return [...events].sort((a, b) => (b.money?.outstanding ?? 0) - (a.money?.outstanding ?? 0))
    default: {
      const exhaustive: never = sort
      return exhaustive
    }
  }
}
