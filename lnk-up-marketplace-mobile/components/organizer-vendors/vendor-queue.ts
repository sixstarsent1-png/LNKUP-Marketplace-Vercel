import type { OrganizerVendorRequest, OrganizerVendorRequestStatus } from "@/lib/api/organizer-requests"
import type {
  OrganizerDiscoverFilters,
  OrganizerDiscoverSort,
} from "@/lib/api/organizer-vendor-directory"

export type SegmentKey = "requests" | "discover" | "invites"
export type FilterKey = "all" | "review" | "needs_info" | "waitlisted" | "awaiting_payment" | "confirmed" | "declined"

export const DISCOVER_SORTS: { id: OrganizerDiscoverSort; label: string }[] = [
  { id: "score", label: "Best match" },
  { id: "rating", label: "Top rated" },
  { id: "completed", label: "Most events" },
  { id: "reliable", label: "Most reliable" },
  { id: "new", label: "Newer vendors" },
]

export const EMPTY_DISCOVER_FILTERS: OrganizerDiscoverFilters = {}

/** Same buckets and words as the web inbox. */
export const REQUEST_FILTERS: { key: FilterKey; label: string }[] = [
  { key: "review", label: "To review" },
  { key: "needs_info", label: "Needs info" },
  { key: "waitlisted", label: "Waitlisted" },
  { key: "awaiting_payment", label: "Awaiting payment" },
  { key: "confirmed", label: "Confirmed" },
  { key: "declined", label: "Declined" },
  { key: "all", label: "All" },
]

export function matchesFilter(status: OrganizerVendorRequestStatus, filter: FilterKey): boolean {
  switch (filter) {
    case "all":
      return true
    case "review":
      return status === "submitted" || status === "under_review"
    case "needs_info":
      return status === "info_requested"
    case "waitlisted":
      return status === "waitlisted"
    case "awaiting_payment":
      return status === "approved" || status === "payment_required"
    case "confirmed":
      return status === "paid" || status === "completed"
    case "declined":
      return status === "rejected" || status === "cancelled"
    default: {
      const exhaustive: never = filter
      return exhaustive
    }
  }
}

/**
 * Vendors tab badge and Home's "Applications to review": submitted + under review, the same
 * statuses the server counts (LNKup_Organizer_Presentation::PENDING_STATUSES).
 */
export function awaitingReviewCount(items: Pick<OrganizerVendorRequest, "status">[]): number {
  return items.filter((item) => matchesFilter(item.status, "review")).length
}

export function canReview(status: OrganizerVendorRequestStatus): boolean {
  return (
    status === "submitted" ||
    status === "under_review" ||
    status === "waitlisted" ||
    status === "info_requested"
  )
}

/** Waitlisting is a decision for open requests; a waitlisted vendor can only be approved or declined. */
export function canWaitlist(status: OrganizerVendorRequestStatus): boolean {
  return canReview(status) && status !== "waitlisted"
}

/** Organizer can ask a question while the request is still waiting for a decision. */
export function canRequestInfo(status: OrganizerVendorRequestStatus): boolean {
  return status === "submitted" || status === "under_review"
}

export function parseFilterKey(value: string | string[] | undefined): FilterKey | null {
  const raw = Array.isArray(value) ? value[0] : value
  return REQUEST_FILTERS.find((item) => item.key === raw)?.key ?? null
}

export function vendorProfileHref(profileId: string, applicationId?: string): string {
  return applicationId
    ? `/(organizer)/vendors/${profileId}?applicationId=${applicationId}`
    : `/(organizer)/vendors/${profileId}?from=discover`
}
