import { normalizeCurrency, pickNumber } from "@/lib/api/format-money"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import { parseCalendarDate } from "@/lib/format/date"

/**
 * Vendor money ledger (`/financial/transactions`). History shows money that moved; invoices that
 * are still open live under Due, and reminders live in Notifications.
 */
export type VendorTransactionStatus = "paid" | "refunded" | "failed" | "pending" | "removed" | "other"

export type VendorTransaction = {
  id: string
  publicId?: string
  type: string
  typeLabel: string
  status: VendorTransactionStatus
  amount: number
  currency: string
  eventTitle?: string
  eventDateIso?: string
  place?: string
  organizerName?: string
  applicationId?: string
  paymentId?: string
  /** When the money moved (paid date), falling back to the ledger row date. */
  occurredAt?: string
}

export type VendorTransactionDetail = VendorTransaction & {
  lineItems: Array<{ description: string; amount: number }>
  invoiceNumber?: string
  receipt?: VendorReceiptDetail | null
  provider?: string
}

export type VendorReceiptDetail = {
  id: string
  number: string
  amount: number
  currency: string
  paymentStatus: string
  paymentMethod?: string
  issuedAt?: string
  eventTitle?: string
  eventDateIso?: string
  place?: string
  organizerName?: string
  applicationId?: string
  viewUrl?: string
  pdfAvailable: boolean
}

const TYPE_LABELS: Record<string, string> = {
  payment: "Vendor payment",
  booth_payment: "Vendor payment",
  application_fee: "Application fee",
  refund: "Refund",
  partial_refund: "Partial refund",
  credit: "Credit applied",
  referral_credit: "Referral credit",
  deposit: "Deposit",
  installment: "Installment",
}

function str(value: unknown): string | undefined {
  if (value == null) return undefined
  const s = String(value).trim()
  return s ? s : undefined
}

function isoDay(value: unknown): string | undefined {
  const s = str(value)
  const match = s?.match(/^(\d{4}-\d{2}-\d{2})/)
  return match ? match[1] : undefined
}

export function transactionStatus(raw: Record<string, unknown>): VendorTransactionStatus {
  const payment = String(raw.payment_status ?? "").toLowerCase()
  const ledger = String(raw.status ?? "").toLowerCase()
  if (payment === "removed") return "removed"
  if (payment === "refunded" || payment === "partially_refunded" || ledger === "refunded") return "refunded"
  if (ledger === "failed" || payment === "failed") return "failed"
  if (ledger === "paid" || ledger === "completed" || payment === "paid") return "paid"
  if (ledger === "pending" || payment === "pending") return "pending"
  return "other"
}

export function mapVendorTransaction(raw: Record<string, unknown>): VendorTransaction {
  const type = String(raw.type ?? "payment").toLowerCase()
  return {
    id: String(raw.id ?? ""),
    publicId: str(raw.public_id),
    type,
    typeLabel: TYPE_LABELS[type] ?? type.replace(/_/g, " ").replace(/^\w/, (c) => c.toUpperCase()),
    status: transactionStatus(raw),
    amount: pickNumber(raw.amount) ?? 0,
    currency: normalizeCurrency(String(raw.currency ?? "USD")),
    eventTitle: str(raw.event_title),
    eventDateIso: isoDay(raw.event_date),
    place: str(raw.place),
    organizerName: str(raw.organizer_name),
    applicationId: raw.application_id ? String(raw.application_id) : undefined,
    paymentId: raw.payment_post_id ? String(raw.payment_post_id) : undefined,
    occurredAt: str(raw.paid_at) ?? str(raw.created_at),
  }
}

/** Only money that actually moved belongs in History. */
export function isMoneyMovement(t: VendorTransaction): boolean {
  return t.status === "paid" || t.status === "refunded" || t.status === "failed"
}

export function mapReceiptDetail(raw: Record<string, unknown>): VendorReceiptDetail {
  return {
    id: String(raw.id ?? ""),
    number: String(raw.receipt_number ?? ""),
    amount: pickNumber(raw.amount) ?? 0,
    currency: normalizeCurrency(String(raw.currency ?? "USD")),
    paymentStatus: String(raw.payment_status ?? (raw.status === "issued" ? "paid" : raw.status ?? "")).toLowerCase(),
    paymentMethod: str(raw.payment_method),
    issuedAt: str(raw.created_at),
    eventTitle: str(raw.event_title),
    eventDateIso: isoDay(raw.event_date),
    place: str(raw.place),
    organizerName: str(raw.organizer_name),
    applicationId: raw.application_id ? String(raw.application_id) : undefined,
    viewUrl: str(raw.view_url),
    pdfAvailable: raw.pdf_available === true,
  }
}

export function mapTransactionDetail(raw: Record<string, unknown>): VendorTransactionDetail {
  const base = mapVendorTransaction(raw)
  const items = Array.isArray(raw.line_items) ? (raw.line_items as Array<Record<string, unknown>>) : []
  const invoice = raw.invoice && typeof raw.invoice === "object" ? (raw.invoice as Record<string, unknown>) : null
  const receipt = raw.receipt && typeof raw.receipt === "object" ? mapReceiptDetail(raw.receipt as Record<string, unknown>) : null
  return {
    ...base,
    lineItems: items.map((i) => ({ description: String(i.description ?? ""), amount: pickNumber(i.amount) ?? 0 })),
    invoiceNumber: invoice ? str(invoice.number) : undefined,
    receipt,
    provider: str(raw.provider),
  }
}

export async function fetchVendorTransactions(auth: WpAuth, limit = 50): Promise<VendorTransaction[]> {
  const data = await wpFetchJson<unknown>("/financial/transactions", { auth, query: { role: "vendor", limit } })
  const rows = Array.isArray(data) ? (data as Array<Record<string, unknown>>) : []
  return rows.map(mapVendorTransaction).filter((t) => t.id)
}

export async function fetchTransactionDetail(id: string, auth: WpAuth): Promise<VendorTransactionDetail> {
  const data = await wpFetchJson<Record<string, unknown>>(`/financial/transactions/${encodeURIComponent(id)}`, { auth })
  return mapTransactionDetail(data ?? {})
}

export async function fetchReceiptDetail(id: string, auth: WpAuth): Promise<VendorReceiptDetail> {
  const data = await wpFetchJson<Record<string, unknown>>(`/financial/receipts/${encodeURIComponent(id)}`, { auth })
  return mapReceiptDetail(data ?? {})
}

export type TransactionGroup = { title: string; items: VendorTransaction[] }

/** Today / This week / This month / "October 2026", newest first. */
export function groupTransactionsByDate(items: VendorTransaction[], now: Date = new Date()): TransactionGroup[] {
  const startOfDay = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()
  const startOfWeek = startOfDay - now.getDay() * 86_400_000
  const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1).getTime()
  const groups = new Map<string, VendorTransaction[]>()
  const order: string[] = []
  const sorted = [...items].sort((a, b) => (b.occurredAt ?? "").localeCompare(a.occurredAt ?? ""))
  for (const t of sorted) {
    const day = t.occurredAt ? parseCalendarDate(t.occurredAt.slice(0, 10)) : null
    let title = "Earlier"
    if (day) {
      const ms = day.getTime()
      if (ms >= startOfDay) title = "Today"
      else if (ms >= startOfWeek) title = "This week"
      else if (ms >= startOfMonth) title = "This month"
      else title = day.toLocaleDateString("en-US", { month: "long", year: "numeric" })
    }
    if (!groups.has(title)) {
      groups.set(title, [])
      order.push(title)
    }
    groups.get(title)?.push(t)
  }
  return order.map((title) => ({ title, items: groups.get(title) ?? [] }))
}

/** "Nov 24, 2026" from a MySQL datetime, never the raw timestamp. */
export function formatLedgerDate(value?: string): string | undefined {
  if (!value) return undefined
  const day = parseCalendarDate(value.slice(0, 10))
  return day ? day.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" }) : undefined
}
