import { getOrganizerFinancialUrl } from "@/lib/api/config"

export type OrganizerFinancialSnapshot = {
  collectedToDate?: number
  pendingPayments?: number
  paidVendorCount?: number
  paymentRequiredCount?: number
  availableBalance?: number
  pendingBalance?: number
  reserveBalance?: number
  payoutStatus?: "active" | "setup_required" | "pending" | "unknown"
  currency?: string
  wordpressFinancialUrl?: string
  raw?: unknown
}

export type OrganizerMoneySummary = OrganizerFinancialSnapshot

export type OrganizerFinancialActivityItem = {
  id: string
  type: "payment" | "payout" | "refund" | "reserve" | "fee" | "unknown"
  label: string
  description?: string
  amount?: number
  currency?: string
  status?: "pending" | "completed" | "failed" | "unknown"
  eventName?: string
  eventId?: string
  createdAt?: string
  href?: string
  raw?: unknown
}

export type OrganizerEventMoneySummary = {
  eventId: string
  eventName?: string
  collected?: number
  pending?: number
  paymentRequiredCount?: number
  paidVendorCount?: number
  unpaidVendorCount?: number
  currency?: string
  recentActivity?: OrganizerFinancialActivityItem[]
  wordpressFinancialUrl?: string
  raw?: unknown
}

export function mapOrganizerFinancialSnapshot(data: unknown): OrganizerFinancialSnapshot {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const wallet = (d.wallet && typeof d.wallet === "object" ? d.wallet : d.balances) as Record<string, unknown> | undefined
  const display = (d.display_balances && typeof d.display_balances === "object"
    ? d.display_balances
    : {}) as Record<string, unknown>
  const connect = (d.connect && typeof d.connect === "object" ? d.connect : d.connect_status) as Record<string, unknown> | undefined
  const stats = (d.stats && typeof d.stats === "object" ? d.stats : d.counts) as Record<string, unknown> | undefined

  const collected =
    Number(d.collected ?? d.gross_revenue ?? d.vendor_revenue ?? wallet?.total ?? display.available ?? 0) || undefined
  const pending =
    Number(d.outstanding ?? d.pending_balance ?? display.pending ?? wallet?.pending ?? 0) || undefined
  const availableBalance =
    Number(display.available ?? wallet?.available ?? d.available_balance ?? 0) || undefined
  const pendingBalance =
    Number(display.pending ?? wallet?.pending ?? d.pending_balance ?? 0) || undefined
  const reserveBalance =
    Number(display.reserve ?? wallet?.reserve ?? d.reserve_balance ?? 0) || undefined

  let payoutStatus: OrganizerFinancialSnapshot["payoutStatus"] = "unknown"
  const connectStatus = String(connect?.status ?? connect?.payouts_status ?? "").toLowerCase()
  if (connectStatus.includes("active") || connect?.payouts_enabled) {
    payoutStatus = "active"
  } else if (connectStatus.includes("pending")) {
    payoutStatus = "pending"
  } else if (connect && Object.keys(connect).length > 0) {
    payoutStatus = "setup_required"
  }

  const paidVendorCount =
    Number(stats?.paid ?? stats?.paid_vendors ?? d.paid_vendor_count ?? d.paid_vendors ?? 0) || undefined
  const paymentRequiredCount =
    Number(stats?.payment_required ?? stats?.awaiting_payment ?? d.payment_required_count ?? 0) || undefined

  return {
    collectedToDate: collected,
    pendingPayments: pending,
    paidVendorCount,
    paymentRequiredCount,
    availableBalance,
    pendingBalance,
    reserveBalance,
    payoutStatus,
    currency: String(d.currency ?? "USD"),
    wordpressFinancialUrl: getOrganizerFinancialUrl(),
    raw: data,
  }
}

export function mapOrganizerActivityItem(item: Record<string, unknown>, index: number): OrganizerFinancialActivityItem {
  const typeRaw = String(item.type ?? item.activity_type ?? "unknown").toLowerCase()
  let type: OrganizerFinancialActivityItem["type"] = "unknown"
  if (typeRaw.includes("payout")) type = "payout"
  else if (typeRaw.includes("refund")) type = "refund"
  else if (typeRaw.includes("reserve")) type = "reserve"
  else if (typeRaw.includes("fee")) type = "fee"
  else if (typeRaw.includes("payment")) type = "payment"

  const statusRaw = String(item.status ?? "").toLowerCase()
  let status: OrganizerFinancialActivityItem["status"] = "unknown"
  if (statusRaw.includes("complete") || statusRaw === "paid") status = "completed"
  else if (statusRaw.includes("pending")) status = "pending"
  else if (statusRaw.includes("fail")) status = "failed"

  const eventName = item.event_name ? String(item.event_name) : undefined
  let label = String(item.label ?? item.title ?? item.description ?? "Activity")
  if (!item.label && !item.title) {
    if (type === "payment") {
      label = `Collected vendor payment${eventName ? ` — ${eventName}` : ""}`
    } else if (type === "payout") {
      label = "Payout to bank"
    } else if (type === "refund") {
      label = `Refund${eventName ? ` — ${eventName}` : ""}`
    } else if (type === "fee") {
      label = "Platform fee"
    }
  }

  return {
    id: String(item.id ?? item.entry_id ?? `org-activity-${index}`),
    type,
    label,
    description: item.description ? String(item.description) : undefined,
    amount: typeof item.amount === "number" ? item.amount : Number(item.amount ?? item.total ?? 0) || undefined,
    currency: String(item.currency ?? "USD"),
    status,
    eventName,
    eventId: item.event_id ? String(item.event_id) : undefined,
    createdAt: String(item.created_at ?? item.date ?? "") || undefined,
    href: item.url ? String(item.url) : undefined,
    raw: item,
  }
}

export function mapOrganizerActivityList(data: unknown): OrganizerFinancialActivityItem[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : d.items ?? d.activities ?? d.entries ?? []) as Record<string, unknown>[]
  return items.map((item, i) => mapOrganizerActivityItem(item, i))
}

export function mapOrganizerEventMoney(
  eventId: string,
  data: unknown,
  eventName?: string,
): OrganizerEventMoneySummary {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const stats = (d.stats && typeof d.stats === "object" ? d.stats : d) as Record<string, unknown>

  return {
    eventId,
    eventName: eventName ?? (d.event_name ? String(d.event_name) : d.title ? String(d.title) : undefined),
    collected: Number(d.collected ?? stats.collected ?? stats.gross_revenue ?? 0) || undefined,
    pending: Number(d.pending ?? stats.pending ?? stats.outstanding ?? 0) || undefined,
    paymentRequiredCount: Number(stats.payment_required ?? d.payment_required_count ?? 0) || undefined,
    paidVendorCount: Number(stats.paid ?? stats.paid_vendors ?? d.paid_vendor_count ?? 0) || undefined,
    unpaidVendorCount: Number(stats.unpaid ?? stats.awaiting_payment ?? 0) || undefined,
    currency: String(d.currency ?? "USD"),
    recentActivity: d.recent_activity ? mapOrganizerActivityList(d.recent_activity) : undefined,
    wordpressFinancialUrl: getOrganizerFinancialUrl(),
    raw: data,
  }
}

export const mockOrganizerActivity: OrganizerFinancialActivityItem[] = [
  {
    id: "org-act-1",
    type: "payment",
    label: "Vendor booth payment",
    description: "Charlotte Night Market",
    amount: 185,
    currency: "USD",
    status: "completed",
    eventName: "Charlotte Night Market",
    eventId: "charlotte-night-market",
    createdAt: "Jun 2026",
  },
  {
    id: "org-act-2",
    type: "payment",
    label: "Payment pending",
    description: "Atlanta Food Fest",
    amount: 345,
    currency: "USD",
    status: "pending",
    eventName: "Atlanta Food Fest",
    eventId: "atlanta-food-fest",
    createdAt: "Jul 2026",
  },
]

export const mockOrganizerEventMoney: OrganizerEventMoneySummary = {
  eventId: "charlotte-night-market",
  eventName: "Charlotte Night Market",
  collected: 4070,
  pending: 832,
  paymentRequiredCount: 2,
  paidVendorCount: 22,
  unpaidVendorCount: 6,
  currency: "USD",
  recentActivity: mockOrganizerActivity,
  wordpressFinancialUrl: getOrganizerFinancialUrl(),
}
