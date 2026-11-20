import { getPublicWpSiteUrl } from "@/lib/api/config"

export type VendorSupportTopic =
  | "load_in"
  | "parking"
  | "booth"
  | "documents"
  | "payment"
  | "general"

export type VendorSupportRequest = {
  id: string
  requestId: string
  topic: VendorSupportTopic
  message: string
  status: "submitted" | "open" | "resolved" | "closed" | "unknown"
  createdAt?: string
  response?: string
}

export type VendorSupportSubmitResult =
  | { status: "submitted"; item: VendorSupportRequest }
  | { status: "handoff_required"; message: string; wordpressUrl?: string }

const TOPIC_TO_WP: Record<VendorSupportTopic, string> = {
  load_in: "load_in",
  parking: "parking",
  booth: "booth",
  documents: "documents",
  payment: "payment",
  general: "general",
}

const WP_TO_TOPIC: Record<string, VendorSupportTopic> = {
  load_in: "load_in",
  parking: "parking",
  booth: "booth",
  power: "booth",
  documents: "documents",
  payment: "payment",
  general: "general",
}

export function supportTopicToWpCategory(topic: VendorSupportTopic): string {
  return TOPIC_TO_WP[topic] ?? "general"
}

export function mapWpSupportTopic(category?: string): VendorSupportTopic {
  const key = (category ?? "").toLowerCase()
  return WP_TO_TOPIC[key] ?? "general"
}

type WpSupportItem = {
  id?: number
  application_id?: number
  category?: string
  status?: string
  message?: string
  created_at?: string
  resolved_at?: string | null
}

export function mapWpSupportItem(requestId: string, item: WpSupportItem): VendorSupportRequest {
  const statusRaw = (item.status ?? "open").toLowerCase()
  let status: VendorSupportRequest["status"] = "unknown"
  if (statusRaw === "open" || statusRaw === "submitted") status = "open"
  else if (statusRaw === "resolved") status = "resolved"
  else if (statusRaw === "closed") status = "closed"

  return {
    id: String(item.id ?? ""),
    requestId,
    topic: mapWpSupportTopic(item.category),
    message: item.message ?? "",
    status,
    createdAt: item.created_at,
  }
}

export function mockSupportHistory(requestId: string): VendorSupportRequest[] {
  return [
    {
      id: "sup-1",
      requestId,
      topic: "parking",
      message: "Where should food trucks park for load-in?",
      status: "resolved",
      createdAt: "Jun 10, 2026",
      response: "Use vendor lot B — enter from 16th St.",
    },
  ]
}

export function buildSupportHandoff(): VendorSupportSubmitResult {
  const base = getPublicWpSiteUrl()
  return {
    status: "handoff_required",
    message: "Support requests are handled on LNKup web right now.",
    wordpressUrl: base ? `${base}/vendor-dashboard/?view=operations` : undefined,
  }
}

export const SUPPORT_TOPIC_LABELS: Record<VendorSupportTopic, string> = {
  load_in: "Load-in",
  parking: "Parking",
  booth: "Booth assignment",
  documents: "Documents",
  payment: "Payment",
  general: "General question",
}
