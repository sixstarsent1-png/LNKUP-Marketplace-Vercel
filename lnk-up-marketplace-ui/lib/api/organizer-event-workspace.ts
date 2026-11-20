import type { OrganizerFinancialSnapshot } from "@/lib/api/organizer-financial"
import { events, incomingRequests } from "@/lib/data"
import { getOpportunityApplyUrl, getOrganizerDashboardUrl } from "@/lib/api/config"

export type OrganizerEventWorkspace = {
  eventId: string
  title: string
  date?: string
  city?: string
  state?: string
  venue?: string
  status?: string
  eventType?: string
  expectedAttendance?: number
  opportunities: Array<{
    id: string
    title?: string
    categories?: string[]
    vendorFee?: number
    currency?: string
    spotsTotal?: number
    spotsRemaining?: number
    applicationDeadline?: string
    status?: string
  }>
  stats: {
    requests: number
    approved: number
    paymentRequired: number
    paid: number
    waitlisted: number
    rejected: number
    missingDocuments?: number
  }
  launchChecklist?: Array<{
    id: string
    label: string
    complete: boolean
    href?: string
  }>
  financial?: OrganizerFinancialSnapshot
  wordpressUrl?: string
  raw?: unknown
}

export type OrganizerShareLink = {
  eventId: string
  opportunityId?: string
  title: string
  opportunityUrl?: string
  applyUrl?: string
  applicationUrl?: string
  publicUrl?: string
  previewUrl?: string
  status: "ready" | "missing_opportunity" | "unavailable"
}

type WpOpsEvent = {
  id?: number
  title?: string
  event_date?: string
  city?: string
  state?: string
  venue_name?: string
  status?: string
  event_type?: string
  expected_attendance?: number
  opportunities?: Array<{
    id?: number
    title?: string
    categories?: string[]
    vendor_fee?: number
    spots_total?: number
    spots_remaining?: number
    application_deadline?: string
    status?: string
  }>
}

type WpRoster = {
  groups?: Record<string, { count?: number }>
  total?: number
}

export function mapRosterStats(roster: WpRoster | null | undefined): OrganizerEventWorkspace["stats"] {
  const groups = roster?.groups ?? {}
  const count = (key: string) => groups[key]?.count ?? 0
  return {
    requests: roster?.total ?? Object.values(groups).reduce((s, g) => s + (g.count ?? 0), 0),
    approved: count("approved") + count("paid") + count("completed"),
    paymentRequired: count("payment_required") + count("approved"),
    paid: count("paid") + count("completed"),
    waitlisted: count("waitlisted"),
    rejected: count("rejected"),
    missingDocuments: count("documents_missing") || undefined,
  }
}

export function mapOpsToWorkspace(
  eventId: string,
  ops: WpOpsEvent | null,
  roster: WpRoster | null,
): OrganizerEventWorkspace {
  const dash = getOrganizerDashboardUrl()
  const stats = mapRosterStats(roster)
  const opportunities = (ops?.opportunities ?? []).map((o) => ({
    id: String(o.id ?? ""),
    title: o.title,
    categories: o.categories,
    vendorFee: o.vendor_fee,
    spotsTotal: o.spots_total,
    spotsRemaining: o.spots_remaining,
    applicationDeadline: o.application_deadline,
    status: o.status,
  }))

  return {
    eventId,
    title: ops?.title ?? "Event",
    date: ops?.event_date,
    city: ops?.city,
    state: ops?.state,
    venue: ops?.venue_name,
    status: ops?.status,
    eventType: ops?.event_type,
    expectedAttendance: ops?.expected_attendance,
    opportunities,
    stats,
    launchChecklist: buildEventChecklist(stats, opportunities.length > 0, eventId),
    wordpressUrl: dash ? `${dash}?view=operations&event=${eventId}` : undefined,
    raw: { ops, roster },
  }
}

export function buildEventChecklist(
  stats: OrganizerEventWorkspace["stats"],
  hasOpportunity: boolean,
  eventId?: string,
): OrganizerEventWorkspace["launchChecklist"] {
  const dash = getOrganizerDashboardUrl()
  const wpOps = dash && eventId ? `${dash}?view=operations&event=${eventId}` : dash ?? undefined
  const shareHref = eventId ? `/organizer/events/${eventId}` : undefined

  return [
    { id: "event", label: "Create event", complete: true, href: "/organizer/launch" },
    { id: "opportunity", label: "Add first vendor opportunity", complete: hasOpportunity, href: "/organizer/launch" },
    { id: "share", label: "Share application link", complete: false, href: shareHref },
    { id: "review", label: "Review vendor requests", complete: stats.requests > 0, href: "/organizer/vendors" },
    { id: "approve", label: "Approve vendors", complete: stats.approved > 0, href: "/organizer/vendors" },
    { id: "payments", label: "Collect payments", complete: stats.paid > 0, href: "/organizer/money" },
    { id: "packet", label: "Add packet/logistics details", complete: false, href: wpOps },
    { id: "wp_ops", label: "View event operations on LNKup web", complete: false, href: wpOps },
  ]
}

export function buildMockEventWorkspace(eventId: string): OrganizerEventWorkspace | null {
  const event = events.find((e) => e.id === eventId)
  if (!event) return null

  const requests = incomingRequests.filter((r) => r.eventId === eventId || r.eventName === event.name)
  const approved = requests.filter((r) => r.status === "approved" || r.status === "paid").length
  const paid = requests.filter((r) => r.status === "paid").length

  const stats = {
    requests: requests.length,
    approved,
    paymentRequired: requests.filter((r) => r.status === "approved").length,
    paid,
    waitlisted: requests.filter((r) => r.status === "waitlisted").length,
    rejected: requests.filter((r) => r.status === "rejected").length,
    missingDocuments: 1,
  }

  const opportunities = [
    {
      id: "101",
      title: `Vendors for ${event.name}`,
      categories: event.categoriesNeeded,
      vendorFee: event.vendorFee,
      spotsTotal: event.totalSpots,
      spotsRemaining: event.totalSpots - event.approvedVendors,
      applicationDeadline: event.deadline !== "Rolling" ? event.deadline : undefined,
      status: "open",
    },
  ]

  const dash = getOrganizerDashboardUrl()
  return {
    eventId,
    title: event.name,
    date: event.date,
    city: event.city,
    state: event.state,
    venue: event.venue,
    status: event.status,
    expectedAttendance: event.expectedAttendance,
    opportunities,
    stats,
    launchChecklist: buildEventChecklist(stats, true, eventId),
    wordpressUrl: dash ? `${dash}?view=operations&event=${eventId}` : undefined,
  }
}

export function buildShareLink(workspace: OrganizerEventWorkspace): OrganizerShareLink {
  const opp = workspace.opportunities[0]
  if (!opp) {
    return {
      eventId: workspace.eventId,
      title: workspace.title,
      status: "missing_opportunity",
    }
  }
  const params = new URLSearchParams()
  if (workspace.city) params.set("city", workspace.city)
  if (workspace.state) params.set("state", workspace.state)
  if (opp.categories?.[0]) params.set("category", opp.categories[0])
  const findUrl = `/vendor/find?opportunityId=${encodeURIComponent(opp.id)}`
  const findApply = `${findUrl}&apply=1`
  const wpApply = getOpportunityApplyUrl(opp.id)
  return {
    eventId: workspace.eventId,
    opportunityId: opp.id,
    title: opp.title ?? workspace.title,
    applicationUrl: wpApply,
    opportunityUrl: findUrl,
    applyUrl: wpApply || findApply,
    previewUrl: findUrl,
    publicUrl: wpApply,
    status: "ready",
  }
}
