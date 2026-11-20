export type OrganizerVendorRequestStatus =
  | "submitted"
  | "under_review"
  | "approved"
  | "payment_required"
  | "paid"
  | "waitlisted"
  | "rejected"
  | "cancelled"
  | "completed"
  | "unknown"

export type OrganizerVendorRequest = {
  id: string
  applicationId?: string
  vendorId?: string
  vendorProfileId?: string
  vendorBusinessName: string
  vendorCategory?: string
  eventId?: string
  eventName: string
  opportunityId?: string
  opportunityName?: string
  submittedAt?: string
  status: OrganizerVendorRequestStatus
  paymentStatus?: string
  documentsStatus?: "ready" | "missing" | "pending" | "unknown"
  trustBadges?: string[]
  reputationPreview?: import("@/lib/api/vendor-reputation").OrganizerVendorReputationPreview
  message?: string
  amountDue?: number
  currency?: string
  raw?: unknown
}

export type WpOrganizerApplicationItem = {
  application_id?: number
  opportunity_id?: number
  event_id?: number
  event_name?: string
  opportunity_name?: string
  vendor_profile_id?: number
  vendor_user_id?: number
  vendor_business_name?: string
  category?: string
  status?: string
  submitted_at?: string
  payment_status?: string
  documents_status?: string
  trust_badges?: string[]
  message?: string
  amount_due?: number
  currency?: string
}

const KNOWN: OrganizerVendorRequestStatus[] = [
  "submitted",
  "under_review",
  "approved",
  "payment_required",
  "paid",
  "waitlisted",
  "rejected",
  "cancelled",
  "completed",
]

export function normalizeOrganizerRequestStatus(value?: string): OrganizerVendorRequestStatus {
  const s = (value ?? "").trim().toLowerCase().replace(/-/g, "_")
  if (KNOWN.includes(s as OrganizerVendorRequestStatus)) {
    return s as OrganizerVendorRequestStatus
  }
  return "unknown"
}

export function organizerRequestStatusLabel(status: OrganizerVendorRequestStatus): string {
  const labels: Record<OrganizerVendorRequestStatus, string> = {
    submitted: "Under review",
    under_review: "Under review",
    approved: "Approved",
    payment_required: "Payment required",
    paid: "Booked",
    waitlisted: "Waitlisted",
    rejected: "Not selected",
    cancelled: "Cancelled",
    completed: "Completed",
    unknown: "Status pending",
  }
  return labels[status] ?? "Status pending"
}

export function organizerRequestStatusVariant(
  status: OrganizerVendorRequestStatus,
): "review" | "approved" | "due" | "paid" | "waitlist" | "rejected" {
  switch (status) {
    case "approved":
    case "payment_required":
      return "due"
    case "paid":
    case "completed":
      return "paid"
    case "waitlisted":
      return "waitlist"
    case "rejected":
    case "cancelled":
      return "rejected"
    default:
      return "review"
  }
}

function formatDate(value?: string): string | undefined {
  if (!value) return undefined
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return value
  return parsed.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" })
}

export function mapWpOrganizerApplication(item: WpOrganizerApplicationItem): OrganizerVendorRequest {
  const id = String(item.application_id ?? "")
  return {
    id,
    applicationId: id,
    vendorId: item.vendor_user_id ? String(item.vendor_user_id) : undefined,
    vendorProfileId: item.vendor_profile_id ? String(item.vendor_profile_id) : undefined,
    vendorBusinessName: item.vendor_business_name || "Vendor",
    vendorCategory: item.category,
    eventId: item.event_id ? String(item.event_id) : undefined,
    eventName: item.event_name || "Event",
    opportunityId: item.opportunity_id ? String(item.opportunity_id) : undefined,
    opportunityName: item.opportunity_name,
    submittedAt: formatDate(item.submitted_at) ?? item.submitted_at,
    status: normalizeOrganizerRequestStatus(item.status),
    paymentStatus: item.payment_status,
    documentsStatus: (item.documents_status as OrganizerVendorRequest["documentsStatus"]) ?? "unknown",
    trustBadges: item.trust_badges,
    message: item.message,
    amountDue: item.amount_due,
    currency: item.currency ?? "USD",
    raw: item,
  }
}

export function mapOrganizerRequestList(
  data: { items?: WpOrganizerApplicationItem[] } | WpOrganizerApplicationItem[],
): OrganizerVendorRequest[] {
  const items = Array.isArray(data) ? data : data.items ?? []
  return items.map(mapWpOrganizerApplication).filter((r) => r.id)
}

export type OrganizerReviewAction = {
  action: "approve" | "waitlist" | "reject"
  reason?: string
  internalNote?: string
}
