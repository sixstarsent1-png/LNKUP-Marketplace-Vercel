import { getVendorPaymentsUrl, isLiveApiEnabled } from "@/lib/api/config"
import { withFixtureFallback } from "@/lib/api/fixture-honesty"
import { normalizeCurrency, pickNumber } from "@/lib/api/format-money"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import {
  VENDOR_REQUESTS_CACHE_PREFIX,
  fetchVendorRequests,
  isPaymentDueRequest,
  type VendorRequest,
} from "@/lib/api/vendor-requests"
import { fetchVendorTransactions, type VendorTransaction } from "@/lib/api/vendor-transactions"
import { isPastEventInvoice } from "@/lib/vendor/booking-lifecycle"
import {
  MOCK_INVOICES,
  MOCK_WALLET_SUMMARY,
} from "@/lib/data/fixtures"

export { formatMoney, formatMoneyOrZero, pickNumber } from "@/lib/api/format-money"

export type VendorWalletSummary = {
  amountDue?: number
  /** Part of amountDue on invoices for events that already happened. */
  pastEventDue?: number
  totalPaid?: number
  availableCredits?: number
  pendingCredits?: number
  pendingRefunds?: number
  currency?: string
  paymentRequiredCount?: number
  paidBookingsCount?: number
  lastPaymentAt?: string
  wordpressFinancialUrl?: string
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
  createdAt?: string
  href?: string
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
  eventDateIso?: string
  place?: string
  organizerName?: string
  applicationId?: string
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
  wordpressUrl?: string
}

export type VendorWalletBundle = {
  summary: VendorWalletSummary
  paymentRequired: VendorRequest[]
  /** Money ledger (`/financial/transactions`), newest first. */
  transactions: VendorTransaction[]
  /** Invoices and receipts merged for Wallet UI. */
  invoices: VendorInvoiceOrReceipt[]
  disputes: VendorDispute[]
}

/** Due when payment is required, or approved with a payment id ready. */
export { isPaymentDueRequest } from "@/lib/api/vendor-requests"

export function mapVendorWalletSummary(
  summaryData: unknown,
  dashboardData: unknown,
  requests: VendorRequest[],
): VendorWalletSummary {
  const s = (summaryData && typeof summaryData === "object" ? summaryData : {}) as Record<
    string,
    unknown
  >
  const d = (dashboardData && typeof dashboardData === "object" ? dashboardData : {}) as Record<
    string,
    unknown
  >
  const wallet = (s.wallet && typeof s.wallet === "object" ? s.wallet : d.wallet) as
    | Record<string, unknown>
    | undefined

  const paymentRequired = requests.filter(isPaymentDueRequest)
  const paid = requests.filter((r) => r.status === "paid" || r.status === "completed")

  const amountDueFromRequests = paymentRequired.reduce((sum, r) => sum + (r.amountDue ?? 0), 0)
  const pastEventDue = paymentRequired.filter((r) => isPastEventInvoice(r)).reduce((sum, r) => sum + (r.amountDue ?? 0), 0)
  const totalPaidFromRequests = paid.reduce((sum, r) => sum + (r.amountDue ?? 0), 0)

  const amountDue =
    amountDueFromRequests > 0
      ? amountDueFromRequests
      : (pickNumber(
          s.outstanding,
          d.outstanding,
          s.pending_payments,
          d.pending_payments,
          s.amount_due,
          d.amount_due,
        ) ?? 0)

  const totalPaid =
    totalPaidFromRequests > 0
      ? totalPaidFromRequests
      : (pickNumber(
          s.revenue_paid,
          d.revenue_paid,
          s.money_spent,
          d.money_spent,
          s.lifetime_spend,
          d.lifetime_spend,
          s.total_paid,
          d.total_paid,
        ) ?? 0)

  const availableCredits =
    pickNumber(
      s.credits_available,
      d.credits_available,
      s.available_credits,
      d.available_credits,
      s.credit_balance,
      d.credit_balance,
      s.wallet_balance,
      d.wallet_balance,
      wallet?.balance,
    ) ?? 0

  return {
    amountDue,
    pastEventDue,
    totalPaid,
    availableCredits,
    pendingCredits: pickNumber(s.pending_credits, wallet?.pending_credits),
    pendingRefunds: pickNumber(s.pending_refunds, d.pending_refunds, s.refunds, d.refunds),
    currency: normalizeCurrency(String(s.currency ?? d.currency ?? "USD")),
    paymentRequiredCount: paymentRequired.filter((r) => !isPastEventInvoice(r)).length,
    paidBookingsCount: paid.length,
    lastPaymentAt: String(s.last_payment_at ?? d.last_payment_at ?? "") || undefined,
    wordpressFinancialUrl: getVendorPaymentsUrl(),
  }
}

export function mapVendorActivityItem(
  item: Record<string, unknown>,
  index: number,
): VendorFinancialActivityItem {
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

  const eventName = item.event_name
    ? String(item.event_name)
    : item.event_title
      ? String(item.event_title)
      : undefined

  const message = item.message ? String(item.message) : ""
  let label = String(item.label ?? item.title ?? item.description ?? (message || "Activity"))
  if (!item.label && !item.title && !item.description && !message) {
    if (type === "payment" || type === "booking") {
      label = `Booth payment${eventName ? ` — ${eventName}` : ""}`
    } else if (type === "credit") {
      label = "Referral credit applied"
    } else if (type === "refund") {
      label = `Refund${eventName ? ` — ${eventName}` : ""}`
    }
  }

  const entityType = String(item.entity_type ?? "").toLowerCase()
  const entityId = item.entity_id != null ? String(item.entity_id) : undefined

  return {
    id: String(item.id ?? item.activity_id ?? `activity-${index}`),
    type,
    label,
    description: item.description
      ? String(item.description)
      : message && message !== label
        ? message
        : undefined,
    amount: pickNumber(item.amount, item.total, item.total_amount),
    currency: normalizeCurrency(String(item.currency ?? "USD")),
    status,
    eventName,
    requestId: item.application_id
      ? String(item.application_id)
      : item.request_id
        ? String(item.request_id)
        : entityType === "application" || entityType === "payment"
          ? entityId
          : undefined,
    paymentId: item.payment_id
      ? String(item.payment_id)
      : entityType === "payment"
        ? entityId
        : undefined,
    createdAt: String(item.created_at ?? item.date ?? item.timestamp ?? "") || undefined,
    href: item.url ? String(item.url) : item.pdf_url ? String(item.pdf_url) : undefined,
  }
}

export function mapVendorActivityList(data: unknown): VendorFinancialActivityItem[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (
    Array.isArray(data) ? data : (d.items ?? d.activities ?? d.transactions ?? [])
  ) as Record<string, unknown>[]
  return items.map((item, i) => mapVendorActivityItem(item, i))
}

export function mapVendorInvoiceOrReceipt(
  item: Record<string, unknown>,
  type: "invoice" | "receipt",
  index: number,
): VendorInvoiceOrReceipt {
  // Receipts carry the payment's status; their own `status` is a document state ("issued").
  const statusRaw = String((type === "receipt" ? item.payment_status ?? item.status : item.status) ?? "").toLowerCase()
  let status: VendorInvoiceOrReceipt["status"] = "unknown"
  if (["open", "paid", "void", "refunded"].includes(statusRaw)) {
    status = statusRaw as VendorInvoiceOrReceipt["status"]
  } else if (["pending", "sent", "overdue", "partially_paid", "failed"].includes(statusRaw)) {
    status = "open"
  } else if (statusRaw === "partially_refunded") {
    status = "refunded"
  } else if (type === "receipt" && (statusRaw === "issued" || statusRaw === "completed")) {
    status = "paid"
  }

  return {
    id: String(item.id ?? item.invoice_id ?? item.receipt_id ?? `${type}-${index}`),
    type,
    number: item.number
      ? String(item.number)
      : item.invoice_number
        ? String(item.invoice_number)
        : item.receipt_number
          ? String(item.receipt_number)
          : undefined,
    eventName: item.event_name
      ? String(item.event_name)
      : item.event_title
        ? String(item.event_title)
        : undefined,
    amount: pickNumber(item.total_amount, item.amount, item.total, item.paid_amount),
    currency: normalizeCurrency(String(item.currency ?? "USD")),
    status,
    issuedAt: String(item.issued_at ?? item.issue_date ?? item.created_at ?? "") || undefined,
    dueAt: String(item.due_at ?? item.due_date ?? "") || undefined,
    paidAt: String(item.paid_at ?? "") || undefined,
    pdfUrl: item.pdf_url ? String(item.pdf_url) : undefined,
    wordpressUrl: item.url ? String(item.url) : item.view_url ? String(item.view_url) : undefined,
    eventDateIso: item.event_date ? String(item.event_date).slice(0, 10) : undefined,
    place: item.place ? String(item.place) : undefined,
    organizerName: item.organizer_name ? String(item.organizer_name) : undefined,
    applicationId: item.application_id ? String(item.application_id) : undefined,
  }
}

export function mapVendorInvoicesList(data: unknown): VendorInvoiceOrReceipt[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : (d.items ?? d.invoices ?? [])) as Record<
    string,
    unknown
  >[]
  return items.map((item, i) => mapVendorInvoiceOrReceipt(item, "invoice", i))
}

export function mapVendorReceiptsList(data: unknown): VendorInvoiceOrReceipt[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (Array.isArray(data) ? data : (d.items ?? d.receipts ?? [])) as Record<
    string,
    unknown
  >[]
  return items.map((item, i) => mapVendorInvoiceOrReceipt(item, "receipt", i))
}

export function mergeInvoicesAndReceipts(
  invoices: VendorInvoiceOrReceipt[],
  receipts: VendorInvoiceOrReceipt[],
  limit = 20,
): VendorInvoiceOrReceipt[] {
  const seen = new Set<string>()
  const merged: VendorInvoiceOrReceipt[] = []
  for (const item of [...receipts, ...invoices]) {
    const key = `${item.type}:${item.id}`
    if (seen.has(key)) continue
    seen.add(key)
    merged.push(item)
  }
  return merged.slice(0, limit)
}

/**
 * Fresh, signed URL for a receipt or invoice. The system browser that opens it has no login, so the
 * link carries a short-lived signature; refetching on tap keeps a list left open from going stale.
 */
export async function fetchDocumentUrl(doc: VendorInvoiceOrReceipt, auth?: WpAuth): Promise<string | undefined> {
  const fallback = doc.pdfUrl || doc.wordpressUrl
  if (!isLiveApiEnabled() || !auth || !/^\d+$/.test(doc.id)) return fallback
  const path = doc.type === "receipt" ? `/financial/receipts/${doc.id}` : `/financial/invoices/${doc.id}`
  const data = await wpFetchJson<Record<string, unknown>>(path, { auth })
  const fresh = mapVendorInvoiceOrReceipt(data ?? {}, doc.type, 0)
  return fresh.pdfUrl || fresh.wordpressUrl || fallback
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
    eventName: item.event_title ? String(item.event_title) : undefined,
    wordpressUrl: getVendorPaymentsUrl({
      finTab: "disputes",
      disputePayment: paymentId != null ? String(paymentId) : undefined,
    }),
  }
}

export function mapVendorDisputesList(data: unknown): VendorDispute[] {
  const rows = Array.isArray(data)
    ? data
    : data && typeof data === "object" && Array.isArray((data as { items?: unknown[] }).items)
      ? (data as { items: unknown[] }).items
      : []
  return rows
    .filter((row): row is Record<string, unknown> => Boolean(row) && typeof row === "object")
    .map(mapVendorDispute)
    .filter((d) => Boolean(d.id))
}

export async function openVendorDispute(
  paymentId: string,
  reason: string,
  auth?: WpAuth,
): Promise<VendorDispute | null> {
  if (!isLiveApiEnabled()) {
    return {
      id: `dsp-mock-${Date.now()}`,
      paymentId,
      status: "open",
      isOpen: true,
      reason,
      currency: "USD",
      wordpressUrl: getVendorPaymentsUrl({ finTab: "disputes", disputePayment: paymentId }),
    }
  }
  if (!auth) return null
  const data = await wpFetchJson<{ id?: number; dispute?: Record<string, unknown> }>(
    "/financial/disputes",
    {
      auth,
      method: "POST",
      body: { payment_id: Number(paymentId), reason },
    },
  ).finally(() => invalidateCache(WALLET_CACHE_PREFIX))
  if (data?.dispute) return mapVendorDispute(data.dispute)
  if (data?.id) {
    return mapVendorDispute({
      id: data.id,
      payment_id: paymentId,
      reason,
      status: "open",
      is_open: true,
    })
  }
  return null
}

/**
 * Under the bookings prefix on purpose: anything that invalidates bookings (payment return, apply,
 * info reply) also drops the wallet, so a cached balance never outlives a payment.
 */
const WALLET_CACHE_PREFIX = `${VENDOR_REQUESTS_CACHE_PREFIX}wallet:`

export async function fetchVendorWallet(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<VendorWalletBundle> {
  if (!isLiveApiEnabled() || !auth) return loadVendorWallet(auth)
  return cachedRequest(`${WALLET_CACHE_PREFIX}${auth.username}`, () => loadVendorWallet(auth, options.force), {
    ttlMs: 10_000,
    ...options,
  })
}

async function loadVendorWallet(auth?: WpAuth, force = false): Promise<VendorWalletBundle> {
  return withFixtureFallback(
    "vendor.wallet",
    async () => {
      if (!auth) {
        return {
          summary: {
            currency: "USD",
            wordpressFinancialUrl: getVendorPaymentsUrl(),
          },
          paymentRequired: [],
          transactions: [],
          invoices: [],
          disputes: [],
        }
      }

      const roleQuery = { role: "vendor" as const }
      // `/financial/summary` and `/financial/dashboard` are the same handler; one call is enough.
      const [requestsResult, summaryData, transactions, invoicesData, receiptsData, disputesData] = await Promise.all([
        fetchVendorRequests(auth, { force }),
        wpFetchJson<unknown>("/financial/summary", { auth, query: roleQuery }).catch(() => null),
        fetchVendorTransactions(auth).catch(() => [] as VendorTransaction[]),
        wpFetchJson<unknown>("/financial/invoices", { auth, query: roleQuery }).catch(() => null),
        wpFetchJson<unknown>("/financial/receipts", { auth, query: roleQuery }).catch(() => null),
        wpFetchJson<unknown>("/financial/disputes", { auth, query: roleQuery }).catch(() => null),
      ])

      const paymentRequired = requestsResult.items.filter(isPaymentDueRequest)

      return {
        summary: mapVendorWalletSummary(summaryData, summaryData, requestsResult.items),
        paymentRequired,
        transactions,
        invoices: mergeInvoicesAndReceipts(
          mapVendorInvoicesList(invoicesData),
          mapVendorReceiptsList(receiptsData),
        ),
        disputes: mapVendorDisputesList(disputesData),
      }
    },
    async () => {
      const { MOCK_REQUESTS, MOCK_RECEIPTS, MOCK_DISPUTES } = await import("@/lib/data/fixtures")
      const paymentRequired = MOCK_REQUESTS.filter(isPaymentDueRequest)
      return {
        summary: { ...MOCK_WALLET_SUMMARY, paymentRequiredCount: paymentRequired.length },
        paymentRequired,
        transactions: [],
        invoices: mergeInvoicesAndReceipts(MOCK_INVOICES, MOCK_RECEIPTS),
        disputes: MOCK_DISPUTES ?? [],
      }
    },
  )
}
