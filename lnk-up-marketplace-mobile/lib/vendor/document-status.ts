import type { VendorDocumentRecord, VendorDocumentStatus } from "@/lib/api/vendor-documents"
import { parseCalendarDate } from "@/lib/format/date"
import type { StatusTone } from "@/lib/theme"

const SOON_DAYS = 30
const DAY_MS = 24 * 60 * 60 * 1000

export type ExpiryState = "expired" | "soon" | "ok"

/** Whether a document's expiry date has passed or falls within the next 30 days (local calendar days). */
export function documentExpiry(expiresAt: string | undefined, today: Date = new Date()): ExpiryState | null {
  if (!expiresAt) return null
  const expiry = parseCalendarDate(expiresAt)
  if (!expiry) return null
  const start = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  const days = Math.round((expiry.getTime() - start.getTime()) / DAY_MS)
  if (days < 0) return "expired"
  if (days <= SOON_DAYS) return "soon"
  return "ok"
}

export function documentStatusTone(status: VendorDocumentStatus): StatusTone {
  switch (status) {
    case "approved":
      return "paid"
    case "pending":
      return "review"
    case "rejected":
    case "expired":
      return "rejected"
    case "missing":
      return "due"
    case "unknown":
      return "neutral"
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}

/** Required documents approved vs required total, from the server's per-document status. */
export function requiredDocumentProgress(items: VendorDocumentRecord[]): {
  approved: number
  required: number
  attention: number
} {
  const required = items.filter((item) => item.required)
  return {
    approved: required.filter((item) => item.status === "approved").length,
    required: required.length,
    attention: items.filter(
      (item) =>
        (item.required && item.status !== "approved" && item.status !== "pending") ||
        item.status === "rejected" ||
        item.status === "expired",
    ).length,
  }
}

export function canUploadDocument(item: VendorDocumentRecord): boolean {
  return item.status === "missing" || item.status === "rejected" || item.status === "expired" || item.status === "pending"
}
