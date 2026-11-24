/**
 * Organizer invitations addressed to the signed-in vendor (WordPress `lnkup/v1/vendor/invites`).
 * The email token never leaves WordPress; responses go by invite ID and are ownership-checked there.
 */

export type VendorInviteStatus = "sent" | "opened" | "accepted" | "declined" | "expired"

export type VendorInvite = {
  id: number
  status: VendorInviteStatus
  message: string
  organizerName: string
  eventId: number
  eventTitle: string
  eventDate: string
  city: string
  state: string
  imageUrl: string
  opportunityId: number
  opportunityTitle: string
  expiresAt: string
  canRespond: boolean
}

export type WpVendorInvite = {
  id?: number
  status?: string
  message?: string
  organizer_name?: string
  event_id?: number
  event_title?: string
  event_date?: string
  city?: string
  state?: string
  image_url?: string
  opportunity_id?: number
  opportunity_title?: string
  expires_at?: string
  can_respond?: boolean
}

const STATUSES: VendorInviteStatus[] = ["sent", "opened", "accepted", "declined", "expired"]

export function mapVendorInvite(raw: WpVendorInvite): VendorInvite {
  return {
    id: Number(raw.id ?? 0),
    status: STATUSES.find((s) => s === raw.status) ?? "expired",
    message: raw.message ?? "",
    organizerName: raw.organizer_name ?? "",
    eventId: Number(raw.event_id ?? 0),
    eventTitle: raw.event_title ?? "",
    eventDate: raw.event_date ?? "",
    city: raw.city ?? "",
    state: raw.state ?? "",
    imageUrl: raw.image_url ?? "",
    opportunityId: Number(raw.opportunity_id ?? 0),
    opportunityTitle: raw.opportunity_title ?? "",
    expiresAt: raw.expires_at ?? "",
    canRespond: raw.can_respond === true,
  }
}

/** Opportunity to apply to after accepting, parsed from WordPress's apply URL. */
export function opportunityFromApplyUrl(url: unknown): number | null {
  const match = typeof url === "string" ? /[?&]apply=(\d+)/.exec(url) : null
  return match ? Number(match[1]) : null
}
