import type { WpVendorDocumentItem, WpVendorDocumentsResponse } from "@/lib/api/types"

export type VendorDocumentStatus =
  | "pending"
  | "approved"
  | "rejected"
  | "expired"
  | "missing"
  | "unknown"

export type VendorDocumentRecord = {
  id: string
  type: string
  label: string
  status: VendorDocumentStatus
  required?: boolean
  fileName?: string
  uploadedAt?: string
  expiresAt?: string
  rejectionReason?: string
  downloadUrl?: string
  raw?: unknown
}

const DOCUMENT_TYPES: Record<string, string> = {
  certificate_of_insurance: "Certificate of Insurance",
  health_permit: "Health Permit",
  business_license: "Business License",
  w9: "W9",
  sales_tax_certificate: "Sales Tax Certificate",
  food_handler_permit: "Food Handler Permit",
  temporary_event_permit: "Temporary Event Permit",
  other: "Other",
}

const CORE_REQUIRED = new Set([
  "certificate_of_insurance",
  "health_permit",
  "business_license",
  "w9",
  "sales_tax_certificate",
])

function mapStatus(raw?: string): VendorDocumentStatus {
  switch (raw) {
    case "approved":
    case "verified":
      return "approved"
    case "rejected":
      return "rejected"
    case "expired":
    case "expiring":
      return "expired"
    case "pending":
      return "pending"
    case "missing":
      return "missing"
    default:
      return "unknown"
  }
}

function formatExpires(value?: string): string | undefined {
  if (!value) return undefined
  const parsed = Date.parse(value)
  if (Number.isNaN(parsed)) return value
  return new Date(parsed).toLocaleDateString("en-US", { month: "short", year: "numeric" })
}

export function mapVendorDocument(item: WpVendorDocumentItem): VendorDocumentRecord {
  return {
    id: item.id ? String(item.id) : `type-${item.type}`,
    type: item.type || "other",
    label: item.label || DOCUMENT_TYPES[item.type || ""] || item.type || "Document",
    status: mapStatus(item.status),
    required: item.required ?? CORE_REQUIRED.has(item.type || ""),
    fileName: item.file_name,
    uploadedAt: item.uploaded_at,
    expiresAt: formatExpires(item.expires_at),
    rejectionReason: item.rejection_reason,
    downloadUrl: item.download_url,
    raw: item,
  }
}

export function mapVendorDocumentsResponse(data: WpVendorDocumentsResponse): VendorDocumentRecord[] {
  const fromApi = (data.items ?? []).map(mapVendorDocument)
  if (fromApi.length > 0) return fromApi
  return buildPlaceholderDocuments()
}

function buildPlaceholderDocuments(): VendorDocumentRecord[] {
  return Object.entries(DOCUMENT_TYPES).map(([type, label]) => ({
    id: `type-${type}`,
    type,
    label,
    status: "missing" as const,
    required: CORE_REQUIRED.has(type),
  }))
}

export function documentsCompletionPercent(docs: VendorDocumentRecord[]): number {
  if (!docs.length) return 0
  const required = docs.filter((d) => d.required)
  const pool = required.length ? required : docs
  const approved = pool.filter((d) => d.status === "approved").length
  return Math.round((approved / pool.length) * 100)
}

export const DOCUMENT_TYPE_OPTIONS = Object.entries(DOCUMENT_TYPES).map(([value, label]) => ({
  value,
  label,
}))

export const mockVendorDocumentsNormalized: VendorDocumentRecord[] = [
  {
    id: "1",
    type: "business_license",
    label: "Business License",
    status: "approved",
    required: true,
    expiresAt: "Dec 2026",
  },
  {
    id: "2",
    type: "certificate_of_insurance",
    label: "Certificate of Insurance",
    status: "pending",
    required: true,
  },
  {
    id: "3",
    type: "health_permit",
    label: "Health Permit",
    status: "approved",
    required: true,
    expiresAt: "Aug 2026",
  },
  {
    id: "type-food_handler_permit",
    type: "food_handler_permit",
    label: "Food Handler Permit",
    status: "missing",
    required: false,
  },
  {
    id: "4",
    type: "w9",
    label: "W9",
    status: "approved",
    required: true,
  },
  {
    id: "type-sales_tax_certificate",
    type: "sales_tax_certificate",
    label: "Sales Tax Certificate",
    status: "missing",
    required: true,
  },
  {
    id: "type-temporary_event_permit",
    type: "temporary_event_permit",
    label: "Temporary Event Permit",
    status: "missing",
    required: false,
  },
  {
    id: "type-other",
    type: "other",
    label: "Other",
    status: "missing",
    required: false,
  },
]

export const MAX_DOCUMENT_UPLOAD_BYTES = 10 * 1024 * 1024
export const ALLOWED_DOCUMENT_MIMES = [
  "application/pdf",
  "image/jpeg",
  "image/jpg",
  "image/png",
]
