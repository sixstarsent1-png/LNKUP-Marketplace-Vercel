export type OrganizerVendorDirectoryItem = {
  profileId: string
  businessName: string
  category?: string
  city?: string
  state?: string
  rating?: number
  completedEvents?: number
  isFavorite?: boolean
  isPreferred?: boolean
  trustBadges?: string[]
  raw?: unknown
}

export type OrganizerQueueBuckets = {
  all: number
  submitted: number
  underReview: number
  approved: number
  paymentRequired: number
}

export type OrganizerVendorTimelineItem = {
  id: string
  type: string
  label: string
  timestamp?: string
}

export type OrganizerVendorNoteSummary = {
  id: string
  content: string
  pinned?: boolean
  eventId?: string
  createdAt?: string
}

export type OrganizerListSummary = {
  id: string
  name: string
  color?: string
  count: number
  updatedAt?: string
}

export type OrganizerInviteSummary = {
  id: string
  vendor: string
  event: string
  sentAt?: string
  status: "accepted" | "pending" | "declined"
}

function toStringOrUndefined(value: unknown): string | undefined {
  return value == null ? undefined : String(value)
}

function toNumberOrUndefined(value: unknown): number | undefined {
  if (typeof value === "number") return value
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : undefined
}

export function mapOrganizerVendorDirectory(data: unknown): OrganizerVendorDirectoryItem[] {
  const root = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : root.items ?? []) as Record<string, unknown>[]
  return items.map((item) => ({
    profileId: String(item.profile_id ?? item.id ?? ""),
    businessName: String(item.business_name ?? item.name ?? "Vendor"),
    category: toStringOrUndefined(item.category),
    city: toStringOrUndefined(item.city),
    state: toStringOrUndefined(item.state),
    rating: toNumberOrUndefined(item.avg_rating ?? item.rating),
    completedEvents: toNumberOrUndefined(item.completed_events),
    isFavorite: Boolean(item.is_favorite),
    isPreferred: Boolean(item.is_preferred),
    trustBadges: Array.isArray(item.badges) ? item.badges.map(String) : undefined,
    raw: item,
  })).filter((item) => item.profileId)
}

export function buildOrganizerQueueBucketsFromRequests(
  requests: Array<{ status?: string }>,
): OrganizerQueueBuckets {
  let submitted = 0
  let underReview = 0
  let approved = 0
  let paymentRequired = 0
  for (const request of requests) {
    const status = (request.status ?? "").toLowerCase()
    if (status === "submitted") submitted += 1
    if (status === "under_review") underReview += 1
    if (status === "approved") approved += 1
    if (status === "payment_required") paymentRequired += 1
  }
  return {
    all: requests.length,
    submitted,
    underReview,
    approved,
    paymentRequired,
  }
}

export function mapOrganizerVendorTimeline(data: unknown): OrganizerVendorTimelineItem[] {
  const root = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : root.items ?? []) as Record<string, unknown>[]
  return items.map((item, index) => ({
    id: String(item.id ?? `timeline-${index}`),
    type: String(item.activity_type ?? item.type ?? "activity"),
    label: String(item.label ?? item.message ?? item.activity_type ?? "Activity"),
    timestamp: toStringOrUndefined(item.created_at ?? item.timestamp),
  }))
}

export function mapOrganizerVendorNotes(data: unknown): OrganizerVendorNoteSummary[] {
  const root = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : root.items ?? []) as Record<string, unknown>[]
  return items.map((item) => ({
    id: String(item.id ?? ""),
    content: String(item.content ?? item.note ?? ""),
    pinned: Boolean(item.pinned),
    eventId: toStringOrUndefined(item.event_id),
    createdAt: toStringOrUndefined(item.created_at ?? item.date),
  })).filter((item) => item.id)
}

export function mapOrganizerLists(data: unknown): OrganizerListSummary[] {
  const root = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : root.items ?? []) as Record<string, unknown>[]
  return items.map((item) => ({
    id: String(item.id ?? ""),
    name: String(item.name ?? "List"),
    color: toStringOrUndefined(item.color),
    count: Number(item.count ?? item.vendor_count ?? 0),
    updatedAt: toStringOrUndefined(item.updated_at ?? item.modified_at),
  })).filter((item) => item.id)
}

export function mapOrganizerInvites(data: unknown): OrganizerInviteSummary[] {
  const root = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : root.items ?? []) as Record<string, unknown>[]
  return items.map((item) => {
    const normalized = String(item.status ?? "pending").toLowerCase()
    const status: OrganizerInviteSummary["status"] =
      normalized === "accepted" || normalized === "declined" ? normalized : "pending"
    return {
      id: String(item.id ?? ""),
      vendor: String(item.vendor_name ?? item.vendor ?? "Vendor"),
      event: String(item.event_name ?? item.event ?? "Event"),
      sentAt: toStringOrUndefined(item.sent_at ?? item.created_at),
      status,
    }
  }).filter((item) => item.id)
}

