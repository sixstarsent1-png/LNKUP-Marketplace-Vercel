import { getOrganizerDashboardUrl } from "@/lib/api/config"
import type { OrganizerEventLite } from "@/lib/api/organizer-events"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { normalizeOrganizerRequestStatus } from "@/lib/api/organizer-requests"
import type { OrganizerVendorReputationPreview } from "@/lib/api/vendor-reputation"
import { mapWpBadge } from "@/lib/api/vendor-reputation"
import { events, incomingRequests } from "@/lib/data"

export const mockOrganizerFinancial = {
  collectedToDate: 16140,
  pendingPayments: 8320,
  paidVendorCount: 1,
  paymentRequiredCount: 2,
  availableBalance: 12400,
  pendingBalance: 8320,
  reserveBalance: 500,
  payoutStatus: "active" as const,
  currency: "USD",
  wordpressFinancialUrl: getOrganizerFinancialUrl() || "",
}

function getOrganizerFinancialUrl() {
  const base = getOrganizerDashboardUrl()
  return base ? `${base}?view=financial` : ""
}

const mockReputationTrusted: OrganizerVendorReputationPreview = {
  tier: "trusted",
  tierLabel: "Trusted vendor",
  score: 78,
  rating: 4.7,
  reviewCount: 12,
  completedEvents: 14,
  badges: [mapWpBadge("verified", true), mapWpBadge("documents_ready", true)],
}

const mockReputationNew: OrganizerVendorReputationPreview = {
  tier: "new",
  tierLabel: "New vendor",
  score: 15,
  reviewCount: 0,
  completedEvents: 1,
  badges: [mapWpBadge("verified", false)],
}

export const mockOrganizerEvents: OrganizerEventLite[] = events
  .filter((e) => e.organizer === "CultureWave Events" || e.organizer === "Southern Social Co.")
  .slice(0, 2)
  .map((e) => ({
    id: e.id,
    title: e.name,
    date: e.date,
    city: e.city,
    state: e.state,
    venue: e.venue,
    status: e.status,
    opportunitiesCount: 1,
    requestsCount: e.vendorRequests,
    approvedCount: e.approvedVendors,
    paymentRequiredCount: Math.max(0, e.approvedVendors - e.paidVendors),
    paidCount: e.paidVendors,
    wordpressUrl: getOrganizerDashboardUrl()
      ? `${getOrganizerDashboardUrl()}?view=operations&event=${e.id}`
      : undefined,
  }))

const statusMap: Record<string, string> = {
  "under-review": "under_review",
  approved: "approved",
  "payment-due": "payment_required",
  paid: "paid",
  waitlisted: "waitlisted",
  rejected: "rejected",
}

export function mapMockIncomingToOrganizerRequest(
  item: (typeof incomingRequests)[number],
  index: number,
): OrganizerVendorRequest {
  return {
    id: item.id,
    applicationId: item.id,
    vendorId: item.vendorId,
    vendorProfileId: item.vendorProfileId ?? item.vendorId,
    vendorBusinessName: item.vendorName,
    vendorCategory: item.category,
    eventId: item.eventId,
    eventName: item.eventName ?? "Charlotte Night Market",
    opportunityId: item.opportunityId,
    submittedAt: "Jun 2026",
    status: normalizeOrganizerRequestStatus(statusMap[item.status] ?? item.status),
    paymentStatus: item.paymentStatus,
    documentsStatus: item.verified ? "ready" : "pending",
    trustBadges: item.verified ? ["verified"] : [],
    message: item.message,
    amountDue: 320,
    currency: "USD",
    reputationPreview: index % 2 === 0 ? mockReputationTrusted : mockReputationNew,
  }
}

export const mockOrganizerRequests: OrganizerVendorRequest[] =
  incomingRequests.map(mapMockIncomingToOrganizerRequest)
