import type { WpVendorApplicationItem, WpVendorApplicationsResponse } from "@/lib/api/types"

export type VendorRequestStatus =
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

export type VendorRequest = {
  id: string
  opportunityId?: string
  eventId?: string
  eventName: string
  eventImage?: string
  eventDate?: string
  city?: string
  state?: string
  organizerName?: string
  category?: string
  status: VendorRequestStatus
  submittedAt?: string
  approvedAt?: string
  paymentDueAt?: string
  amountDue?: number
  currency?: string
  vendorFee?: number
  paymentId?: string
  paymentStatus?: string
  reason?: string
  raw?: unknown
}

const KNOWN_STATUSES = new Set<VendorRequestStatus>([
  "submitted",
  "under_review",
  "approved",
  "payment_required",
  "paid",
  "waitlisted",
  "rejected",
  "cancelled",
  "completed",
])

export function normalizeVendorRequestStatus(value: string | undefined): VendorRequestStatus {
  const normalized = (value ?? "").trim().toLowerCase().replace(/-/g, "_")
  if (KNOWN_STATUSES.has(normalized as VendorRequestStatus)) {
    return normalized as VendorRequestStatus
  }
  return "unknown"
}

/** Mock `lib/data.ts` hyphen statuses → normalized underscore statuses */
export function mockStatusToNormalized(
  status: "under-review" | "approved" | "payment-due" | "paid" | "waitlisted" | "rejected",
): VendorRequestStatus {
  const map: Record<string, VendorRequestStatus> = {
    "under-review": "under_review",
    approved: "approved",
    "payment-due": "payment_required",
    paid: "paid",
    waitlisted: "waitlisted",
    rejected: "rejected",
  }
  return map[status] ?? "unknown"
}

export function vendorRequestStatusLabel(status: VendorRequestStatus): string {
  const labels: Record<VendorRequestStatus, string> = {
    submitted: "Under review",
    under_review: "Under review",
    approved: "Payment required",
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

export function vendorRequestStatusVariant(
  status: VendorRequestStatus,
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
  return parsed.toLocaleDateString("en-US", {
    month: "short",
    day: "numeric",
    year: "numeric",
  })
}

function formatLocation(city?: string, state?: string): string {
  return [city, state].filter(Boolean).join(", ")
}

export function mapWpVendorApplication(item: WpVendorApplicationItem): VendorRequest {
  const status = normalizeVendorRequestStatus(item.status)
  return {
    id: String(item.application_id ?? item.id ?? ""),
    opportunityId: item.opportunity_id ? String(item.opportunity_id) : undefined,
    eventId: item.event_id ? String(item.event_id) : undefined,
    eventName: item.event_name ?? "Event",
    eventImage: item.featured_image || "/placeholder.svg",
    eventDate: formatDate(item.event_date) ?? item.event_date,
    city: formatLocation(item.city, item.state) || item.city,
    state: item.state,
    organizerName: item.organizer_name,
    category: item.category,
    status,
    submittedAt: formatDate(item.submitted_at) ?? item.submitted_at,
    approvedAt: formatDate(item.approved_at) ?? item.approved_at,
    paymentDueAt: formatDate(item.payment_due_at) ?? item.payment_due_at,
    amountDue: item.amount_due ?? item.vendor_fee,
    currency: item.currency ?? "USD",
    vendorFee: item.vendor_fee ?? item.amount_due,
    paymentId: item.payment_id ? String(item.payment_id) : undefined,
    paymentStatus: item.payment_status,
    reason: item.rejection_reason,
    raw: item,
  }
}

export function mapVendorRequestList(
  data: WpVendorApplicationsResponse | WpVendorApplicationItem[],
): VendorRequest[] {
  const items = Array.isArray(data) ? data : data.items ?? []
  return items.map(mapWpVendorApplication).filter((item) => item.id)
}

export function filterPaymentRequired(requests: VendorRequest[]): VendorRequest[] {
  return requests.filter((r) => r.status === "payment_required" || r.status === "approved")
}

export function filterBooked(requests: VendorRequest[]): VendorRequest[] {
  return requests.filter((r) => r.status === "paid" || r.status === "completed")
}
