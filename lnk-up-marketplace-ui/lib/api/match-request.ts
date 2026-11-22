/**
 * Validated organizer recruitment request bodies (BFF -> WordPress).
 */

export interface InviteRequest {
  mode: "preview" | "send"
  eventId: number
  opportunityId: number
  profileIds: number[]
  message: string
}

export interface ShortlistRequest {
  eventId: number
  profileIds: number[]
  action: "add" | "remove"
}

function positiveInt(value: unknown): number | null {
  const n = typeof value === "string" ? Number(value) : value
  return typeof n === "number" && Number.isInteger(n) && n > 0 ? n : null
}

function idList(value: unknown, max: number): number[] | null {
  if (!Array.isArray(value) || value.length === 0 || value.length > max) return null
  const ids = value.map(positiveInt)
  return ids.every((id): id is number => id !== null) ? Array.from(new Set(ids)) : null
}

export function parseInviteRequest(body: unknown): InviteRequest | null {
  if (!body || typeof body !== "object") return null
  const raw = body as Record<string, unknown>
  const mode = raw.mode === "send" ? "send" : raw.mode === "preview" ? "preview" : null
  const eventId = positiveInt(raw.eventId)
  const profileIds = idList(raw.profileIds, 200)
  const opportunityId = raw.opportunityId === undefined || raw.opportunityId === 0 ? 0 : positiveInt(raw.opportunityId)
  const message = typeof raw.message === "string" ? raw.message.slice(0, 1000) : ""
  if (!mode || !eventId || !profileIds || opportunityId === null) return null
  return { mode, eventId, opportunityId, profileIds, message }
}

export function parseShortlistRequest(body: unknown): ShortlistRequest | null {
  if (!body || typeof body !== "object") return null
  const raw = body as Record<string, unknown>
  const eventId = positiveInt(raw.eventId)
  const profileIds = idList(raw.profileIds, 100)
  const action = raw.action === "remove" ? "remove" : "add"
  if (!eventId || !profileIds) return null
  return { eventId, profileIds, action }
}
