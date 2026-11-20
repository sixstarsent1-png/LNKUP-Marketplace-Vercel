import { getOrganizerDashboardUrl } from "@/lib/api/config"

export type OrganizerEventLite = {
  id: string
  title: string
  date?: string
  city?: string
  state?: string
  venue?: string
  status?: string
  opportunitiesCount?: number
  requestsCount?: number
  approvedCount?: number
  paymentRequiredCount?: number
  paidCount?: number
  operationsStatus?: string
  wordpressUrl?: string
  raw?: unknown
}

type WpInviteOption = {
  event_id?: number
  event_title?: string
  date?: string
  city?: string
  opportunities?: unknown[]
}

type WpRosterGroups = {
  applied?: unknown[]
  approved?: unknown[]
  paid?: unknown[]
  missing_payment?: unknown[]
  waitlisted?: unknown[]
  rejected?: unknown[]
}

export function mapInviteOptionToEventLite(item: WpInviteOption): OrganizerEventLite {
  const id = String(item.event_id ?? "")
  const base = getOrganizerDashboardUrl()
  return {
    id,
    title: item.event_title || "Event",
    date: item.date,
    city: item.city,
    opportunitiesCount: Array.isArray(item.opportunities) ? item.opportunities.length : 0,
    wordpressUrl: base ? `${base}?view=operations&event=${id}` : undefined,
    raw: item,
  }
}

export function enrichEventWithRoster(
  event: OrganizerEventLite,
  roster: { groups?: WpRosterGroups; total?: number },
): OrganizerEventLite {
  const groups = roster.groups ?? {}
  const applied = (groups.applied?.length ?? 0) + (groups.waitlisted?.length ?? 0)
    + (groups.approved?.length ?? 0) + (groups.paid?.length ?? 0)
    + (groups.missing_payment?.length ?? 0) + (groups.rejected?.length ?? 0)
  return {
    ...event,
    requestsCount: roster.total ?? applied,
    approvedCount: (groups.approved?.length ?? 0) + (groups.missing_payment?.length ?? 0),
    paymentRequiredCount: groups.missing_payment?.length ?? 0,
    paidCount: groups.paid?.length ?? 0,
  }
}
