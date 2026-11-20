import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpDownloadFile, wpFetchFormData, wpFetchJson } from "@/lib/api/wp-client"
import { MOCK_VENDOR_DOCUMENTS } from "@/lib/data/fixtures"
import { invalidateVendorReadiness } from "@/lib/api/vendor-readiness"

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
}

export type VendorDocumentsResult = {
  items: VendorDocumentRecord[]
  total: number
  compliance?: {
    total?: number
    verified?: number
    attention_count?: number
  }
}

type WpVendorDocumentItem = {
  id?: number
  type?: string
  label?: string
  status?: string
  required?: boolean
  file_name?: string
  uploaded_at?: string
  expires_at?: string
  rejection_reason?: string
  download_url?: string
}

type WpVendorDocumentsResponse = {
  items?: WpVendorDocumentItem[]
  compliance?: VendorDocumentsResult["compliance"]
  total?: number
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

export const DOCUMENT_TYPE_OPTIONS = Object.entries(DOCUMENT_TYPES).map(([value, label]) => ({
  value,
  label,
}))

export const MAX_DOCUMENT_UPLOAD_BYTES = 10 * 1024 * 1024

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

function mapVendorDocument(item: WpVendorDocumentItem): VendorDocumentRecord {
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
  }
}

export function documentStatusLabel(status: VendorDocumentStatus): string {
  const labels: Record<VendorDocumentStatus, string> = {
    pending: "Pending review",
    approved: "Approved",
    rejected: "Rejected",
    expired: "Expired",
    missing: "Missing",
    unknown: "Unknown",
  }
  return labels[status]
}

export async function fetchVendorDocuments(auth?: WpAuth): Promise<VendorDocumentsResult> {
  if (!isLiveApiEnabled()) {
    return {
      items: MOCK_VENDOR_DOCUMENTS.map((d) => ({ ...d })),
      total: MOCK_VENDOR_DOCUMENTS.length,
      compliance: { total: 5, verified: 2, attention_count: 2 },
    }
  }

  const data = await wpFetchJson<WpVendorDocumentsResponse>("/documents/vendor", { auth })
  const items = (data.items ?? []).map(mapVendorDocument)
  return {
    items,
    total: data.total ?? items.length,
    compliance: data.compliance,
  }
}

export type UploadDocumentInput = {
  documentType: string
  uri: string
  fileName: string
  mimeType?: string
  expirationDate?: string
}

export async function uploadVendorDocument(
  input: UploadDocumentInput,
  auth?: WpAuth,
): Promise<{ success: boolean; documentId?: number; message?: string }> {
  if (!isLiveApiEnabled()) {
    return { success: true, documentId: Date.now(), message: "Document uploaded (mock)." }
  }

  const formData = new FormData()
  formData.append("document_type", input.documentType)
  if (input.expirationDate) {
    formData.append("expiration_date", input.expirationDate)
  }

  // React Native FormData file shape
  formData.append("file", {
    uri: input.uri,
    name: input.fileName,
    type: input.mimeType || "application/octet-stream",
  } as unknown as Blob)

  const result = await wpFetchFormData<{
    success?: boolean
    document_id?: number
    message?: string
  }>("/documents/upload", formData, { auth }).finally(invalidateVendorReadiness)

  return {
    success: result.success !== false,
    documentId: result.document_id,
    message: result.message,
  }
}

/**
 * Download a vendor document via authenticated REST and share/open it.
 */
export async function shareVendorDocument(
  documentId: string,
  auth?: WpAuth,
  fileName?: string,
): Promise<void> {
  if (!documentId || documentId.startsWith("type-")) {
    throw new Error("No file available for this document.")
  }

  if (!isLiveApiEnabled()) {
    throw new Error("Document download requires live API.")
  }

  let FileSystem: { cacheDirectory: string | null }
  let Sharing: {
    isAvailableAsync: () => Promise<boolean>
    shareAsync: (uri: string, opts?: { mimeType?: string; dialogTitle?: string }) => Promise<void>
  }
  try {
    // Lazy require — older store binaries may not include these native modules.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    FileSystem = require("expo-file-system/legacy")
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    Sharing = require("expo-sharing")
  } catch {
    throw new Error("Document download requires a newer app build. Update LNKup from the App Store.")
  }

  const safeName = (fileName || `document-${documentId}.pdf`).replace(/[^\w.\-]+/g, "_")
  const dest = `${FileSystem.cacheDirectory ?? ""}${safeName}`
  const downloaded = await wpDownloadFile(`/documents/${documentId}/file`, dest, { auth })

  const canShare = await Sharing.isAvailableAsync()
  if (!canShare) {
    throw new Error("Sharing is not available on this device.")
  }
  await Sharing.shareAsync(downloaded.uri, {
    mimeType: downloaded.mimeType || "application/octet-stream",
    dialogTitle: "View or share document",
  })
}
