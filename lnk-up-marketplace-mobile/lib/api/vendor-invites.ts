import { isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson, type WpAuth } from "@/lib/api/wp-client"

/**
 * Organizer invitations addressed to the signed-in vendor (`lnkup/v1/vendor/invites`).
 * The invite token never reaches the app; responses go by invite ID and are ownership-checked server-side.
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

type WpVendorInvite = {
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
  const status = STATUSES.find((s) => s === raw.status) ?? "expired"
  return {
    id: Number(raw.id ?? 0),
    status,
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

export async function fetchVendorInvites(auth: WpAuth, scope: "open" | "all" = "open"): Promise<VendorInvite[]> {
  if (!isLiveApiEnabled()) return []
  const data = await wpFetchJson<{ items?: WpVendorInvite[] }>("/vendor/invites", { auth, query: { scope } })
  return (data.items ?? []).map(mapVendorInvite).filter((invite) => invite.id > 0)
}

/** Accept (returns the opportunity to apply to) or decline an invitation. */
export async function respondToVendorInvite(
  auth: WpAuth,
  inviteId: number,
  accept: boolean,
): Promise<{ opportunityId: number | null }> {
  const data = await wpFetchJson<{ success?: boolean; apply_url?: string }>(`/vendor/invites/${inviteId}/respond`, {
    auth,
    method: "POST",
    body: { accept },
  })
  const match = typeof data.apply_url === "string" ? /[?&]apply=(\d+)/.exec(data.apply_url) : null
  return { opportunityId: match ? Number(match[1]) : null }
}
