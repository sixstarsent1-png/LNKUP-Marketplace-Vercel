import type { VendorRequest } from "@/lib/api/vendor-requests"
import { mockStatusToNormalized } from "@/lib/api/vendor-requests"
import { getVendorDashboardPaymentsUrl } from "@/lib/api/config"
import { vendorRequests as mockVendorRequests } from "@/lib/data"
import type { VendorPaymentSummary } from "@/lib/api/vendor-payment-handoff"
import { mapCheckoutSummary } from "@/lib/api/vendor-payment-handoff"

export type VendorWalletSummary = {
  amountDue?: number
  totalPaid?: number
  availableCredits?: number
  pendingCredits?: number
  pendingRefunds?: number
  currency?: string
  paymentRequiredCount?: number
  paidBookingsCount?: number
  lastPaymentAt?: string
  wordpressFinancialUrl?: string
  raw?: unknown
}

export type VendorFinancialActivityItem = {
  id: string
  type:
    | "payment"
    | "invoice"
    | "receipt"
    | "credit"
    | "refund"
    | "application_fee"
    | "booking"
    | "unknown"
  label: string
  description?: string
  amount?: number
  currency?: string
  status?: "pending" | "paid" | "failed" | "refunded" | "cancelled" | "unknown"
  eventName?: string
  requestId?: string
  paymentId?: string
  invoiceId?: string
  receiptId?: string
  createdAt?: string
  href?: string
  raw?: unknown
}

export type VendorInvoiceOrReceipt = {
  id: string
  type: "invoice" | "receipt"
  number?: string
  eventName?: string
  amount?: number
  currency?: string
  status?: "open" | "paid" | "void" | "refunded" | "unknown"
  issuedAt?: string
  dueAt?: string
  paidAt?: string
  pdfUrl?: string
  wordpressUrl?: string
  raw?: unknown
}

export type VendorPaymentTimelineStep = {
  id: string
  label: string
  status: "complete" | "current" | "upcoming" | "failed"
  timestamp?: string
}

export type VendorPaymentDetail = {
  requestId?: string
  paymentId?: string
  eventName?: string
  status?: "pending" | "payment_required" | "paid" | "failed" | "refunded" | "disputed" | "unknown"
  vendorFee?: number
  lnkupFee?: number
  processingFee?: number
  total?: number
  amountPaid?: number
  currency?: string
  dueAt?: string
  paidAt?: string
  invoiceUrl?: string
  receiptUrl?: string
  checkoutUrl?: string
  wordpressUrl?: string
  timeline?: VendorPaymentTimelineStep[]
  raw?: unknown
}

export type VendorDispute = {
  id: string
  paymentId?: string
  status: string
  isOpen: boolean
  reason?: string
  amount?: number
  currency?: string
  eventName?: string
  paymentStatus?: string
  resolutionNote?: string
  source?: "vendor" | "stripe_chargeback" | string
  createdAt?: string
  updatedAt?: string
  wordpressUrl?: string
  raw?: unknown
}

export type VendorWalletResponse = {
  summary: VendorWalletSummary
  paymentRequired: VendorRequest[]
}

const STATUS_LABELS: Record<string, string> = {
  pending: "Payment required",
  payment_required: "Payment required",
  approved: "Payment required",
  paid: "Paid",
  completed: "Paid",
  failed: "Failed",
  refunded: "Refunded",
  disputed: "Disputed",
  cancelled: "Cancelled",
  open: "Open",
  void: "Void",
}

export function mapPaymentStatusLabel(status?: string): string {
  if (!status) return "Unknown"
  const key = status.toLowerCase().replace(/\s+/g, "_")
  return STATUS_LABELS[key] ?? status.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
}

export function mapVendorWalletSummary(
  summaryData: unknown,
  dashboardData: unknown,
  requests: VendorRequest[],
): VendorWalletSummary {
  const s = (summaryData && typeof summaryData === "object" ? summaryData : {}) as Record<string, unknown>
  const d = (dashboardData && typeof dashboardData === "object" ? dashboardData : {}) as Record<string, unknown>
  const wallet = (s.wallet && typeof s.wallet === "object" ? s.wallet : d.wallet) as Record<string, unknown> | undefined

  const paymentRequired = requests.filter(
    (r) => r.status === "payment_required" || r.status === "approved",
  )
  const paid = requests.filter((r) => r.status === "paid" || r.status === "completed")

  const amountDue = paymentRequired.reduce((sum, r) => sum + (r.amountDue ?? r.vendorFee ?? 0), 0)
  const totalPaid = paid.reduce((sum, r) => sum + (r.amountDue ?? r.vendorFee ?? 0), 0)

  const credits =
    Number(s.available_credits ?? s.credit_balance ?? wallet?.credits ?? d.credits_available ?? 0) || undefined
  const pendingCredits = Number(s.pending_credits ?? wallet?.pending_credits ?? 0) || undefined
  const pendingRefunds = Number(s.pending_refunds ?? d.pending_refunds ?? 0) || undefined

  return {
    amountDue: amountDue > 0 ? amountDue : Number(s.amount_due ?? d.amount_due ?? 0) || undefined,
    totalPaid: totalPaid > 0 ? totalPaid : Number(s.total_paid ?? d.total_paid ?? 0) || undefined,
    availableCredits: credits,
    pendingCredits,
    pendingRefunds,
    currency: String(s.currency ?? d.currency ?? "USD"),
    paymentRequiredCount: paymentRequired.length,
    paidBookingsCount: paid.length,
    lastPaymentAt: String(s.last_payment_at ?? d.last_payment_at ?? "") || undefined,
    wordpressFinancialUrl: getVendorDashboardPaymentsUrl() || undefined,
    raw: { summary: summaryData, dashboard: dashboardData },
  }
}

function eventNameOf(item: Record<string, unknown>): string | undefined {
  if (item.event_name) return String(item.event_name)
  const event = item.event as { title?: unknown } | null | undefined
  return event?.title ? String(event.title) : undefined
}

export function mapVendorActivityItem(item: Record<string, unknown>, index: number): VendorFinancialActivityItem {
  const typeRaw = String(item.type ?? item.activity_type ?? item.kind ?? "unknown").toLowerCase()
  let type: VendorFinancialActivityItem["type"] = "unknown"
  if (typeRaw.includes("payment")) type = "payment"
  else if (typeRaw.includes("invoice")) type = "invoice"
  else if (typeRaw.includes("receipt")) type = "receipt"
  else if (typeRaw.includes("credit")) type = "credit"
  else if (typeRaw.includes("refund")) type = "refund"
  else if (typeRaw.includes("fee")) type = "application_fee"
  else if (typeRaw.includes("booking") || typeRaw.includes("application")) type = "booking"

  const statusRaw = String(item.status ?? "").toLowerCase()
  let status: VendorFinancialActivityItem["status"] = "unknown"
  if (["pending", "paid", "failed", "refunded", "cancelled"].includes(statusRaw)) {
    status = statusRaw as VendorFinancialActivityItem["status"]
  }

  const eventName = eventNameOf(item)

  let label = String(item.label ?? item.title ?? item.description ?? "Activity")
  if (!item.label && !item.title) {
    if (type === "payment" || type === "booking") {
      label = `Booth payment${eventName ? ` — ${eventName}` : ""}`
    } else if (type === "credit") {
      label = "Referral credit applied"
    } else if (type === "refund") {
      label = `Refund${eventName ? ` — ${eventName}` : ""}`
    } else if (type === "application_fee") {
      label = "Platform fee"
    }
  }

  return {
    id: String(item.id ?? item.activity_id ?? `activity-${index}`),
    type,
    label,
    description: item.description ? String(item.description) : undefined,
    amount: typeof item.amount === "number" ? item.amount : Number(item.amount ?? item.total ?? 0) || undefined,
    currency: String(item.currency ?? "USD"),
    status,
    eventName,
    requestId: item.application_id ? String(item.application_id) : item.request_id ? String(item.request_id) : undefined,
    paymentId: item.payment_id ? String(item.payment_id) : undefined,
    invoiceId: item.invoice_id ? String(item.invoice_id) : undefined,
    receiptId: item.receipt_id ? String(item.receipt_id) : undefined,
    createdAt: String(item.created_at ?? item.date ?? item.timestamp ?? "") || undefined,
    href: item.url ? String(item.url) : item.pdf_url ? String(item.pdf_url) : undefined,
    raw: item,
  }
}

export function mapVendorActivityList(data: unknown): VendorFinancialActivityItem[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : d.items ?? d.activities ?? d.transactions ?? []) as Record<string, unknown>[]
  return items.map((item, i) => mapVendorActivityItem(item, i))
}

export function mapVendorInvoiceOrReceipt(
  item: Record<string, unknown>,
  type: "invoice" | "receipt",
  index: number,
): VendorInvoiceOrReceipt {
  const statusRaw = String(item.status ?? "").toLowerCase()
  let status: VendorInvoiceOrReceipt["status"] = "unknown"
  if (["open", "paid", "void", "refunded"].includes(statusRaw)) {
    status = statusRaw as VendorInvoiceOrReceipt["status"]
  }

  const id = String(item.id ?? item.invoice_id ?? item.receipt_id ?? `${type}-${index}`)

  return {
    id,
    type,
    number: item.number ? String(item.number) : item.invoice_number ? String(item.invoice_number) : undefined,
    eventName: eventNameOf(item),
    amount: typeof item.amount === "number" ? item.amount : Number(item.amount ?? item.total ?? 0) || undefined,
    currency: String(item.currency ?? "USD"),
    status,
    issuedAt: String(item.issued_at ?? item.created_at ?? "") || undefined,
    dueAt: String(item.due_at ?? item.due_date ?? "") || undefined,
    paidAt: String(item.paid_at ?? "") || undefined,
    pdfUrl: item.pdf_url ? String(item.pdf_url) : undefined,
    wordpressUrl: item.url ? String(item.url) : item.view_url ? String(item.view_url) : undefined,
    raw: item,
  }
}

export function mapVendorInvoicesList(data: unknown): VendorInvoiceOrReceipt[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : d.items ?? d.invoices ?? []) as Record<string, unknown>[]
  return items.map((item, i) => mapVendorInvoiceOrReceipt(item, "invoice", i))
}

export function mapVendorReceiptsList(data: unknown): VendorInvoiceOrReceipt[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : d.items ?? d.receipts ?? []) as Record<string, unknown>[]
  return items.map((item, i) => mapVendorInvoiceOrReceipt(item, "receipt", i))
}

export function mapPaymentTimeline(data: unknown): VendorPaymentTimelineStep[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const steps = (Array.isArray(data) ? data : d.timeline ?? d.steps ?? d.items ?? []) as Record<string, unknown>[]
  return steps.map((step, i) => {
    const statusRaw = String(step.status ?? step.state ?? "upcoming").toLowerCase()
    let status: VendorPaymentTimelineStep["status"] = "upcoming"
    if (statusRaw.includes("complete") || statusRaw === "done") status = "complete"
    else if (statusRaw.includes("current") || statusRaw === "active") status = "current"
    else if (statusRaw.includes("fail")) status = "failed"

    return {
      id: String(step.id ?? step.key ?? `step-${i}`),
      label: String(step.label ?? step.title ?? step.name ?? "Step"),
      status,
      timestamp: String(step.timestamp ?? step.date ?? step.completed_at ?? "") || undefined,
    }
  })
}

export function mapVendorPaymentDetail(
  request: VendorRequest,
  summary?: VendorPaymentSummary | null,
  timeline?: VendorPaymentTimelineStep[],
  urls?: { invoiceUrl?: string; receiptUrl?: string },
): VendorPaymentDetail {
  const mapped = summary ?? (request.paymentId ? undefined : undefined)
  let status: VendorPaymentDetail["status"] = "unknown"
  if (request.status === "payment_required" || request.status === "approved") {
    status = "payment_required"
  } else if (request.status === "paid" || request.status === "completed") {
    status = "paid"
  } else if (mapped?.status === "pending") {
    status = "pending"
  }

  return {
    requestId: request.id,
    paymentId: request.paymentId ?? mapped?.paymentId,
    eventName: request.eventName ?? mapped?.eventName,
    status,
    vendorFee: mapped?.vendorFee ?? request.vendorFee ?? request.amountDue,
    lnkupFee: mapped?.lnkupFee,
    processingFee: mapped?.processingFee,
    total: mapped?.total ?? request.amountDue,
    amountPaid: status === "paid" ? (mapped?.total ?? request.amountDue) : undefined,
    currency: request.currency ?? mapped?.currency ?? "USD",
    dueAt: request.paymentDueAt ?? mapped?.dueAt,
    invoiceUrl: urls?.invoiceUrl,
    receiptUrl: urls?.receiptUrl,
    checkoutUrl: undefined,
    wordpressUrl: getVendorDashboardPaymentsUrl() || undefined,
    timeline,
    raw: { request, summary: mapped },
  }
}

export function buildVendorPaymentDetailFromCheckout(
  request: VendorRequest,
  checkoutData: unknown,
  timelineData?: unknown,
): VendorPaymentDetail {
  const summary = mapCheckoutSummary(checkoutData as Parameters<typeof mapCheckoutSummary>[0])
  const timeline = timelineData ? mapPaymentTimeline(timelineData) : undefined
  return mapVendorPaymentDetail(request, summary, timeline)
}

export const mockVendorWalletSummary: VendorWalletSummary = {
  amountDue: 345,
  totalPaid: 185,
  availableCredits: 25,
  pendingCredits: 0,
  pendingRefunds: 0,
  currency: "USD",
  paymentRequiredCount: 1,
  paidBookingsCount: 1,
  lastPaymentAt: "Jun 2026",
  wordpressFinancialUrl: getVendorDashboardPaymentsUrl() || "",
}

export const mockVendorActivity: VendorFinancialActivityItem[] = [
  {
    id: "act-1",
    type: "payment",
    label: "Booth payment",
    description: "Charlotte Night Market",
    amount: 185,
    currency: "USD",
    status: "paid",
    eventName: "Charlotte Night Market",
    requestId: "req-1",
    createdAt: "Jun 2026",
  },
  {
    id: "act-2",
    type: "payment",
    label: "Payment required",
    description: "Atlanta Food Fest",
    amount: 345,
    currency: "USD",
    status: "pending",
    eventName: "Atlanta Food Fest",
    requestId: "req-2",
    createdAt: "Jul 2026",
  },
]

export const mockVendorInvoices: VendorInvoiceOrReceipt[] = [
  {
    id: "inv-1",
    type: "invoice",
    number: "INV-1001",
    eventName: "Atlanta Food Fest",
    amount: 345,
    currency: "USD",
    status: "open",
    issuedAt: "Jul 2026",
    dueAt: "Aug 1, 2026",
  },
]

export const mockVendorReceipts: VendorInvoiceOrReceipt[] = [
  {
    id: "rec-1",
    type: "receipt",
    number: "REC-8842",
    eventName: "Charlotte Night Market",
    amount: 185,
    currency: "USD",
    status: "paid",
    issuedAt: "Jun 2026",
    paidAt: "Jun 2026",
  },
]

export function mockVendorWallet(): VendorWalletResponse {
  const paymentRequired = mockVendorRequests.filter(
    (r) => r.status === "payment-due" || r.status === "approved",
  )
  return {
    summary: mockVendorWalletSummary,
    paymentRequired: paymentRequired.map((r) => ({
      id: r.id,
      eventId: r.eventId,
      eventName: r.eventName,
      eventImage: r.eventImage,
      eventDate: r.date,
      city: r.city,
      status: mockStatusToNormalized(r.status),
      vendorFee: r.vendorFee,
      amountDue: r.vendorFee,
      currency: "USD",
      paymentId: r.paymentId,
      paymentDueAt: r.paymentDueAt,
    })),
  }
}

export function mapVendorDispute(item: Record<string, unknown>): VendorDispute {
  const paymentId = item.payment_id ?? item.payment_post_id ?? item.paymentId
  const status = String(item.status ?? "open")
  const isOpen =
    typeof item.is_open === "boolean"
      ? item.is_open
      : ["open", "pending", "under_review"].includes(status)
  return {
    id: String(item.id ?? ""),
    paymentId: paymentId != null ? String(paymentId) : undefined,
    status,
    isOpen,
    reason: item.reason ? String(item.reason) : undefined,
    amount: typeof item.amount === "number" ? item.amount : Number(item.amount) || undefined,
    currency: String(item.currency ?? "USD"),
    eventName: item.event_title ? String(item.event_title) : item.eventName ? String(item.eventName) : undefined,
    paymentStatus: item.payment_status ? String(item.payment_status) : undefined,
    resolutionNote: item.resolution_note ? String(item.resolution_note) : undefined,
    source: item.source ? String(item.source) : undefined,
    createdAt: item.created_at ? String(item.created_at) : undefined,
    updatedAt: item.updated_at ? String(item.updated_at) : undefined,
    wordpressUrl: getVendorDashboardPaymentsUrl({
      finTab: "disputes",
      disputePayment: paymentId != null ? String(paymentId) : undefined,
    }),
    raw: item,
  }
}

export function mapVendorDisputesList(data: unknown): VendorDispute[] {
  const rows = Array.isArray(data)
    ? data
    : data && typeof data === "object" && Array.isArray((data as { items?: unknown }).items)
      ? ((data as { items: unknown[] }).items)
      : []
  return rows
    .filter((row): row is Record<string, unknown> => Boolean(row) && typeof row === "object")
    .map(mapVendorDispute)
    .filter((d) => Boolean(d.id))
}

export const mockVendorDisputes: VendorDispute[] = [
  {
    id: "dsp-1",
    paymentId: "pay-mock-2",
    status: "open",
    isOpen: true,
    reason: "Booth fee charged for a cancelled market date.",
    amount: 185,
    currency: "USD",
    eventName: "Charlotte Night Market",
    paymentStatus: "disputed",
    source: "vendor",
    createdAt: "2026-07-20",
    wordpressUrl: getVendorDashboardPaymentsUrl({ finTab: "disputes", disputePayment: "pay-mock-2" }),
  },
]
