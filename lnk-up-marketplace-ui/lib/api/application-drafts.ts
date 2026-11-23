/** Saved application drafts (WordPress `GET /applications/drafts`), shown as resume cards. */

export interface WpDraftCard {
  id: number
  opportunity_id: number
  event_name?: string
  opportunity_title?: string
  event_date?: string
  location_label?: string
  deadline?: string
  progress_percent?: number
  can_submit?: boolean
  block_reason?: string
  updated_at?: string
  price?: { quoted?: number; current?: number; changed?: boolean }
}

export interface DraftCard {
  id: number
  opportunityId: number
  eventName: string
  boothName: string
  eventDate: string
  location: string
  deadline: string
  progress: number
  canSubmit: boolean
  priceChanged: boolean
  blockedLabel: string | null
  resumeHref: string
}

const BLOCKED_LABELS: Record<string, string> = {
  deadline: "Deadline passed",
  full: "Booth is full",
  closed: "Applications closed",
  invite_only: "Invite only",
  duplicate: "Already applied",
  previously_declined: "Declined earlier",
}

export function mapDraftCard(item: WpDraftCard): DraftCard {
  const progress = typeof item.progress_percent === "number" ? Math.max(0, Math.min(100, item.progress_percent)) : 0
  const canSubmit = item.can_submit !== false
  return {
    id: item.id,
    opportunityId: item.opportunity_id,
    eventName: item.event_name || "Saved application",
    boothName: item.opportunity_title ?? "",
    eventDate: item.event_date ?? "",
    location: item.location_label ?? "",
    deadline: item.deadline ?? "",
    progress,
    canSubmit,
    priceChanged: Boolean(item.price?.changed),
    blockedLabel: canSubmit ? null : (BLOCKED_LABELS[item.block_reason ?? ""] ?? "Can't submit right now"),
    resumeHref: `/vendor/find?opportunityId=${item.opportunity_id}&apply=1`,
  }
}
