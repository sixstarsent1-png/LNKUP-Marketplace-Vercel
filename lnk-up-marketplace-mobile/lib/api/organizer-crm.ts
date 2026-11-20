import { isLiveApiEnabled } from "@/lib/api/config"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { formatShortDate } from "@/lib/format/date"

export type VendorNote = {
  id: string
  content: string
  createdAt?: string
  pinned: boolean
  eventTitle?: string
}

export type OrganizerInvite = {
  id: string
  vendorName: string
  status: "sent" | "opened" | "accepted" | "declined" | "expired" | "unknown"
  eventId?: string
  eventTitle?: string
  opportunityId?: string
  createdAt?: string
}

type WpNote = { id?: number; content?: string; note?: string; created_at?: string; date?: string; pinned?: boolean; event_title?: string }
type WpInvite = {
  id?: number
  vendor?: string
  status?: string
  event_id?: number
  event_title?: string
  opportunity_id?: number
  created?: string
}

const INVITE_STATUSES = new Set(["sent", "opened", "accepted", "declined", "expired"])

/** Private CRM notes this organizer wrote about a vendor (never visible to the vendor). */
export async function fetchVendorNotes(profileId: string, auth?: WpAuth): Promise<VendorNote[]> {
  if (!isLiveApiEnabled()) return []
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  const data = await wpFetchJson<{ items?: WpNote[] }>(`/crm/vendors/${profileId}/notes`, { auth })
  return (data.items ?? [])
    .filter((note) => note.id != null)
    .map((note) => ({
      id: String(note.id),
      content: String(note.content ?? note.note ?? ""),
      createdAt: formatShortDate(note.created_at ?? note.date),
      pinned: Boolean(note.pinned),
      eventTitle: note.event_title || undefined,
    }))
}

export async function addVendorNote(
  profileId: string,
  content: string,
  auth?: WpAuth,
  eventId?: string,
): Promise<void> {
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  if (!isLiveApiEnabled()) return
  await wpFetchJson(`/crm/vendors/${profileId}/notes`, {
    method: "POST",
    auth,
    body: { content, ...(eventId ? { event_id: Number(eventId) } : {}) },
  })
}

const INVITES_KEY = "organizer.invites:"

/** Invites this organizer has sent, newest first, with their response status. */
export async function fetchOrganizerInvites(auth?: WpAuth, options: CachedRequestOptions = {}): Promise<OrganizerInvite[]> {
  if (!isLiveApiEnabled()) return []
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  return cachedRequest(
    `${INVITES_KEY}${auth.username}`,
    async () => {
      const data = await wpFetchJson<{ items?: WpInvite[] }>("/crm/invites/list", { auth })
      return (data.items ?? [])
        .filter((row) => row.id != null)
        .map((row) => {
          const status = String(row.status ?? "").toLowerCase()
          return {
            id: String(row.id),
            vendorName: row.vendor || "Vendor",
            status: (INVITE_STATUSES.has(status) ? status : "unknown") as OrganizerInvite["status"],
            eventId: row.event_id ? String(row.event_id) : undefined,
            eventTitle: row.event_title || undefined,
            opportunityId: row.opportunity_id ? String(row.opportunity_id) : undefined,
            createdAt: formatShortDate(row.created),
          }
        })
    },
    options,
  )
}

export function invalidateOrganizerInvites(): void {
  invalidateCache(INVITES_KEY)
}

export function inviteStatusLabel(status: OrganizerInvite["status"]): string {
  switch (status) {
    case "sent":
      return "Sent"
    case "opened":
      return "Opened"
    case "accepted":
      return "Applied"
    case "declined":
      return "Declined"
    case "expired":
      return "Expired"
    case "unknown":
      return "Pending"
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}
