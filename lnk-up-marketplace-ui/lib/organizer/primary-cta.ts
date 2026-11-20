import type { OrganizerHomeSummary } from "@/lib/api/organizer-home"

export type OrganizerPrimaryCta = {
  label: string
  href: string
  variant?: "default" | "outline"
}

export function resolveOrganizerPrimaryCta(summary: OrganizerHomeSummary | null): OrganizerPrimaryCta {
  if (!summary) {
    return { label: "Launch first event", href: "/organizer/launch" }
  }

  const { stats } = summary
  if (stats.events === 0) {
    return { label: "Launch first event", href: "/organizer/launch" }
  }
  if (stats.openOpportunities === 0) {
    return { label: "Add vendor opportunity", href: "/organizer/launch" }
  }
  if (stats.vendorRequests === 0) {
    const firstEvent = summary.upcomingEvents[0]
    return {
      label: "Share application link",
      href: firstEvent ? `/organizer/events/${firstEvent.id}` : "/organizer/events",
    }
  }
  if (stats.paymentRequired > 0) {
    return { label: "View payment status", href: "/organizer/money" }
  }
  if (stats.vendorRequests > 0) {
    return { label: "Review vendor requests", href: "/organizer/vendors" }
  }
  return { label: "Launch your next event", href: "/organizer/launch" }
}
