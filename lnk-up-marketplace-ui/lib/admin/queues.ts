import type { AdminDataMeta } from "@/lib/admin/phase15"
import { buildWpAdminHandoffUrls } from "@/lib/admin/phase15"

export type AdminQueueCode =
  | "ok"
  | "admin_connectors_partial"
  | "admin_external_events_partial"
  | "admin_verification_partial"
  | "admin_action_failed"
  | "handoff_required"

export type ConnectorQueueItem = {
  id: number
  displayName: string
  email: string
  city?: string
  state?: string
  status: string
  marketSlug?: string
  createdAt?: string
}

export type ExternalEventQueueItem = {
  id: number
  title: string
  city?: string
  state?: string
  sourcePlatform?: string
  eventType?: string
  workflowStatus: string
  claimStatus?: string
  startDatetime?: string
  vendorInterestCount?: number
  vendorFitScore?: number
  organizerEmail?: string
  createdAt?: string
}

export type VerificationQueueItem = {
  id: number
  documentId: number
  vendorUserId?: number
  vendorProfileId?: number
  vendorName: string
  documentType: string
  documentTypeLabel: string
  status: string
  uploadedAt?: string
}

export function nowIso() {
  return new Date().toISOString()
}

export function mockMeta(partial = false): AdminDataMeta {
  const timestamp = nowIso()
  return {
    live: false,
    mocked: true,
    partial,
    stale: false,
    source: "mock",
    updatedAt: timestamp,
    lastUpdatedAt: timestamp,
  }
}

export function liveMeta(reasons: string[]): AdminDataMeta {
  const timestamp = nowIso()
  return {
    live: true,
    mocked: false,
    partial: reasons.length > 0,
    stale: reasons.length > 0,
    source: reasons.length > 0 ? "mixed" : "wordpress",
    updatedAt: timestamp,
    lastUpdatedAt: timestamp,
    unavailableReasons: reasons.length > 0 ? reasons : undefined,
  }
}

export function getMockConnectorItems(): ConnectorQueueItem[] {
  return [
    {
      id: 101,
      displayName: "Jordan Lee",
      email: "jordan@example.com",
      city: "Charlotte",
      state: "NC",
      status: "pending",
      marketSlug: "charlotte",
      createdAt: "2026-07-28T12:00:00.000Z",
    },
    {
      id: 102,
      displayName: "Sam Rivera",
      email: "sam@example.com",
      city: "Atlanta",
      state: "GA",
      status: "pending",
      marketSlug: "atlanta",
      createdAt: "2026-07-27T09:00:00.000Z",
    },
    {
      id: 103,
      displayName: "Avery Chen",
      email: "avery@example.com",
      city: "Raleigh",
      state: "NC",
      status: "active",
      marketSlug: "raleigh",
      createdAt: "2026-07-20T15:00:00.000Z",
    },
  ]
}

export function getMockExternalEventItems(): ExternalEventQueueItem[] {
  return [
    {
      id: 501,
      title: "Uptown Night Bazaar (imported)",
      city: "Charlotte",
      state: "NC",
      sourcePlatform: "eventbrite",
      eventType: "Night Market",
      workflowStatus: "pending_review",
      claimStatus: "unclaimed",
      vendorInterestCount: 8,
      vendorFitScore: 72,
      createdAt: "2026-07-29T10:00:00.000Z",
    },
    {
      id: 502,
      title: "Midtown Maker Fair",
      city: "Atlanta",
      state: "GA",
      sourcePlatform: "posh",
      eventType: "Maker Fair",
      workflowStatus: "pending_review",
      claimStatus: "unclaimed",
      vendorInterestCount: 3,
      vendorFitScore: 61,
      createdAt: "2026-07-28T18:00:00.000Z",
    },
  ]
}

export function getMockVerificationItems(): VerificationQueueItem[] {
  return [
    {
      id: 901,
      documentId: 901,
      vendorUserId: 44,
      vendorProfileId: 220,
      vendorName: "City Drip Apparel",
      documentType: "insurance",
      documentTypeLabel: "Insurance",
      status: "pending",
      uploadedAt: "2026-07-29T14:00:00.000Z",
    },
    {
      id: 902,
      documentId: 902,
      vendorUserId: 45,
      vendorProfileId: 221,
      vendorName: "Rolling Dessert Co.",
      documentType: "health_permit",
      documentTypeLabel: "Health permit",
      status: "pending",
      uploadedAt: "2026-07-28T11:00:00.000Z",
    },
  ]
}

export function mapConnectorRow(row: Record<string, unknown>): ConnectorQueueItem | null {
  const id = Number(row.id ?? 0)
  if (!id) return null
  return {
    id,
    displayName: String(row.display_name ?? row.displayName ?? "Connector"),
    email: String(row.email ?? ""),
    city: row.city ? String(row.city) : undefined,
    state: row.state ? String(row.state) : undefined,
    status: String(row.status ?? "pending"),
    marketSlug: row.market_slug ? String(row.market_slug) : undefined,
    createdAt: row.created_at ? String(row.created_at) : undefined,
  }
}

export function mapExternalEventRow(row: Record<string, unknown>): ExternalEventQueueItem | null {
  const id = Number(row.id ?? 0)
  if (!id) return null
  return {
    id,
    title: String(row.title ?? "External event"),
    city: row.city ? String(row.city) : undefined,
    state: row.state ? String(row.state) : undefined,
    sourcePlatform: row.source_platform ? String(row.source_platform) : undefined,
    eventType: row.event_type ? String(row.event_type) : undefined,
    workflowStatus: String(row.workflow_status ?? "pending_review"),
    claimStatus: row.claim_status ? String(row.claim_status) : undefined,
    startDatetime: row.start_datetime ? String(row.start_datetime) : undefined,
    vendorInterestCount: Number(row.vendor_interest_count ?? 0) || 0,
    vendorFitScore: Number(row.vendor_fit_score ?? 0) || 0,
    organizerEmail: row.organizer_email ? String(row.organizer_email) : undefined,
    createdAt: row.created_at ? String(row.created_at) : undefined,
  }
}

export function mapVerificationRow(row: Record<string, unknown>): VerificationQueueItem | null {
  const id = Number(row.document_id ?? row.id ?? 0)
  if (!id) return null
  return {
    id,
    documentId: id,
    vendorUserId: row.vendor_user_id ? Number(row.vendor_user_id) : undefined,
    vendorProfileId: row.vendor_profile_id ? Number(row.vendor_profile_id) : undefined,
    vendorName: String(row.vendor_name ?? "Vendor"),
    documentType: String(row.document_type ?? ""),
    documentTypeLabel: String(row.document_type_label ?? row.document_type ?? "Document"),
    status: String(row.status ?? "pending"),
    uploadedAt: row.uploaded_at ? String(row.uploaded_at) : undefined,
  }
}

export function handoffUrls() {
  return buildWpAdminHandoffUrls()
}
