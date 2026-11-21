import { getOrganizerFinancialUrl, getWpBaseUrl, isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import {
  formatMoneyOrZero,
  normalizeCurrency,
  pickNumber,
} from "@/lib/api/format-money"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import type { StatusTone } from "@/lib/theme"
import { wpFetchJson } from "@/lib/api/wp-client"
import { mapInviteOptionToEventLite } from "@/lib/api/organizer-events"
import {
  MOCK_ORGANIZER_ACTIVITY,
  MOCK_ORGANIZER_MONEY,
  MOCK_ORGANIZER_MONEY_EVENTS,
} from "@/lib/data/fixtures"

export { formatMoney, formatMoneyOrZero, pickNumber } from "@/lib/api/format-money"

/** HTTPS bridge on WordPress that redirects into lnkup://money */
export function getConnectAppBridgeUrl(kind: "return" | "refresh"): string {
  return `${getWpBaseUrl()}/?lnkup_app_connect=${kind}`
}

export const CONNECT_APP_DEEP_LINK = "lnkup://money"

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
  stripeSynced?: boolean
  balanceSource?: string
  connectSettlement?: number
  /** Stripe balance could not be read: available/pending are unknown, never shown as $0. */
  balanceUnavailable?: boolean
  /** Why Cash Out is disabled (server money contract), e.g. "no_available_balance". */
  cashOutReason?: string
  lastSyncedAt?: string
  categories: MoneyCategory[]
  connect: OrganizerConnectState
}

/** One server-defined money bucket (`money_categories`); labels and hints come from WordPress. */
export type MoneyCategory = {
  key: string
  label: string
  amount: number
  hint?: string
  /** Source could not be read; render "Unavailable" instead of the amount. */
  unavailable?: boolean
}

/** Server money contract v1 (`money` on organizer financial endpoints). */
export type MoneyContractMetric = {
  cents: number | null
  status: "ok" | "unavailable" | string
  source: string
  synced_at: string | null
}

export type MoneyContract = {
  version: number
  settlement_mode: string
  last_synced_at?: string | null
  payouts?: { state: string; label: string; method?: string; can_cash_out: boolean; cash_out_reason: string }
  metrics: Record<string, MoneyContractMetric | undefined>
}

/** Category keys from `money_categories` → contract metric keys. */
const CONTRACT_METRIC_FOR_CATEGORY: Record<string, string> = {
  gross: "collected",
  fees: "platform_fees",
  refunds: "refunds",
  net: "net_earnings",
  pending: "pending",
  available: "available",
  in_transit: "in_transit",
  paid_out: "paid_out",
}

export function readMoneyContract(raw: unknown): MoneyContract | null {
  const money = raw && typeof raw === "object" ? (raw as { money?: unknown }).money : undefined
  if (!money || typeof money !== "object") return null
  const contract = money as MoneyContract
  return contract.metrics && typeof contract.metrics === "object" ? contract : null
}

/** Dollars for a readable metric; undefined when missing or unavailable. */
export function contractDollars(metric: MoneyContractMetric | undefined): number | undefined {
  if (!metric || metric.status !== "ok" || metric.cents == null) return undefined
  return metric.cents / 100
}

const CASH_OUT_REASON_COPY: Record<string, string> = {
  payouts_not_active: "Finish payout setup to cash out.",
  balance_unavailable: "We couldn’t read your payout balance right now. Pull to refresh in a moment.",
  no_available_balance: "Nothing is available to cash out yet. New card payments usually become available within a few days.",
}

export function cashOutReasonCopy(reason?: string): string | undefined {
  return reason ? CASH_OUT_REASON_COPY[reason] : undefined
}

export type ConnectStateKind =
  | "active"
  | "review"
  | "verification"
  | "rejected"
  | "disabled"
  | "not_started"
  | "unavailable"

export type OrganizerConnectState = {
  kind: ConnectStateKind
  requirements: string[]
  settlementMode: "destination" | "platform_treasury"
  instantEnabled: boolean
  /** Mirrors the server rule: destination mode needs an active account; treasury mode does not. */
  canCashout: boolean
  /** Whether the "Set up payouts" flow is offered at all on this marketplace. */
  canOnboard: boolean
}

export type OrganizerPayoutStatus =
  | "requested"
  | "approved"
  | "processing"
  | "paid"
  | "failed"
  | "cancelled"
  | "unknown"

export type OrganizerPayout = {
  id: string
  amount: number
  netAmount: number
  feeAmount: number
  type: "standard" | "instant"
  status: OrganizerPayoutStatus
  failureReason?: string
  createdAt?: string
  paidAt?: string
  canCancel: boolean
}

export type OrganizerFinancialActivityItem = {
  id: string
  type: "payment" | "payout" | "refund" | "reserve" | "fee" | "unknown"
  label: string
  amount?: number
  currency?: string
  status?: "pending" | "completed" | "failed" | "unknown"
  eventName?: string
  eventId?: string
  createdAt?: string
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
}

export function payoutStatusLabel(
  status?: OrganizerFinancialSnapshot["payoutStatus"],
): string {
  switch (status) {
    case "active":
      return "Payouts active"
    case "setup_required":
      return "Finish payout setup"
    case "pending":
      return "Payout setup pending"
    default:
      return "Payout status unavailable"
  }
}

const MONEY_CATEGORY_KEYS = new Set([
  "gross",
  "fees",
  "refunds",
  "net",
  "pending",
  "available",
  "in_transit",
  "paid_out",
])

export function mapMoneyCategories(raw: unknown): MoneyCategory[] {
  if (!Array.isArray(raw)) return []
  return raw.flatMap((item) => {
    if (!item || typeof item !== "object") return []
    const row = item as Record<string, unknown>
    const key = String(row.key ?? "")
    const amount = pickNumber(row.amount)
    if (!MONEY_CATEGORY_KEYS.has(key) || amount == null) return []
    return [
      {
        key,
        label: String(row.label ?? key),
        amount,
        hint: row.hint ? String(row.hint) : undefined,
      },
    ]
  })
}

const REQUIREMENT_LABELS: Record<string, string> = {
  external_account: "Bank account for payouts",
  "business_profile.url": "Business website",
  "business_profile.mcc": "Business category",
  "business_profile.product_description": "What you sell",
  tos_acceptance: "Accept payout terms",
  "tos_acceptance.date": "Accept payout terms",
  "tos_acceptance.ip": "Accept payout terms",
  "individual.verification.document": "Photo ID",
  "individual.id_number": "Tax ID",
  "individual.ssn_last_4": "Last 4 of SSN",
  "individual.dob.day": "Date of birth",
  "individual.dob.month": "Date of birth",
  "individual.dob.year": "Date of birth",
  "individual.address.line1": "Home address",
  "individual.phone": "Phone number",
  "individual.email": "Email",
  "company.tax_id": "Business tax ID",
}

/** Stripe requirement codes in plain words; unknown codes are softened rather than shown raw. */
export function friendlyRequirement(code: string): string {
  if (REQUIREMENT_LABELS[code]) return REQUIREMENT_LABELS[code]
  const last = code.split(".").filter(Boolean).pop() ?? code
  const words = last.replace(/_/g, " ").trim()
  return words ? words.charAt(0).toUpperCase() + words.slice(1) : "Account details"
}

function stringList(raw: unknown): string[] {
  return Array.isArray(raw) ? raw.map((item) => String(item)).filter(Boolean) : []
}

export function mapConnectState(
  connect: Record<string, unknown> | undefined,
  settlementHint?: unknown,
): OrganizerConnectState {
  const c = connect ?? {}
  const status = String(c.status ?? "not_started").toLowerCase()
  const settlementRaw = String(c.settlement_mode ?? settlementHint ?? "platform_treasury")
  const settlementMode = settlementRaw === "destination" ? "destination" : "platform_treasury"
  const pastDue = stringList(c.requirements_past_due)
  const due = stringList(c.requirements_due)
  const requirements = Array.from(new Set((pastDue.length ? pastDue : due).map(friendlyRequirement)))
  const connectAvailable = c.available !== false && c.code !== "connect_not_configured"

  let kind: ConnectStateKind
  if (!connectAvailable) kind = "unavailable"
  else if (status === "rejected") kind = "rejected"
  else if (status === "disabled") kind = "disabled"
  else if (
    pastDue.length > 0 ||
    status === "restricted" ||
    status === "requirements_due" ||
    status === "verification_required"
  ) {
    kind = "verification"
  } else if (status === "active" && c.payouts_enabled !== false) kind = "active"
  else if (status === "pending" || status === "pending_verification") kind = "review"
  else kind = "not_started"

  const canCashout =
    settlementMode === "platform_treasury" ? kind !== "disabled" && kind !== "rejected" : kind === "active" && pastDue.length === 0

  return {
    kind,
    requirements,
    settlementMode,
    instantEnabled: Boolean(c.instant_payouts_enabled),
    canCashout,
    canOnboard: connectAvailable && kind !== "active" && kind !== "rejected",
  }
}

export function connectStateCopy(state: OrganizerConnectState): {
  badge: string
  message: string
  tone: StatusTone
} {
  switch (state.kind) {
    case "active":
      return { badge: "Payouts active", message: "Cash outs go to your connected bank account.", tone: "paid" }
    case "review":
      return { badge: "Under review", message: "We’re reviewing your payout details. This usually takes a day or two.", tone: "review" }
    case "verification":
      return { badge: "Verification needed", message: "A few details are still needed before payouts can be turned on.", tone: "due" }
    case "rejected":
      return { badge: "Not approved", message: "This payout account couldn’t be approved. Contact LNKup support for help.", tone: "neutral" }
    case "disabled":
      return { badge: "Payouts paused", message: "Payouts are temporarily paused on your account.", tone: "neutral" }
    case "not_started":
      return { badge: "Finish payout setup", message: "Add your bank details to receive vendor booth fees.", tone: "due" }
    case "unavailable":
      return {
        badge: "Paid by LNKup",
        message: "LNKup sends your cash outs to you directly. Request a cash out when funds are available.",
        tone: "neutral",
      }
    default: {
      const exhaustive: never = state.kind
      return exhaustive
    }
  }
}

export function payoutHistoryStatusLabel(status: OrganizerPayoutStatus): string {
  switch (status) {
    case "requested":
      return "Requested"
    case "approved":
      return "Approved"
    case "processing":
      return "On the way"
    case "paid":
      return "Paid"
    case "failed":
      return "Failed"
    case "cancelled":
      return "Cancelled"
    case "unknown":
      return "Status unavailable"
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}

const PAYOUT_STATUSES: OrganizerPayoutStatus[] = [
  "requested",
  "approved",
  "processing",
  "paid",
  "failed",
  "cancelled",
]

export function mapOrganizerPayouts(raw: unknown): OrganizerPayout[] {
  const list = Array.isArray(raw)
    ? raw
    : Array.isArray((raw as { items?: unknown[] } | null)?.items)
      ? (raw as { items: unknown[] }).items
      : []
  return list.flatMap((item) => {
    if (!item || typeof item !== "object") return []
    const row = item as Record<string, unknown>
    if (row.id == null) return []
    const statusRaw = String(row.status ?? "").toLowerCase() as OrganizerPayoutStatus
    const status = PAYOUT_STATUSES.includes(statusRaw) ? statusRaw : "unknown"
    const amount = pickNumber(row.amount) ?? 0
    return [
      {
        id: String(row.id),
        amount,
        netAmount: pickNumber(row.net_amount) ?? amount,
        feeAmount: pickNumber(row.fee_amount) ?? 0,
        type: row.payout_type === "instant" ? "instant" : "standard",
        status,
        failureReason: row.failure_reason ? String(row.failure_reason) : undefined,
        createdAt: row.created_at ? String(row.created_at) : undefined,
        paidAt: row.paid_at ? String(row.paid_at) : undefined,
        canCancel: status === "requested" || status === "approved",
      },
    ]
  })
}

/** Human-readable activity status for Money list subtitles. */
export function activityStatusLabel(
  status?: OrganizerFinancialActivityItem["status"],
): string | undefined {
  switch (status) {
    case "completed":
      return "Completed"
    case "pending":
      return "Pending"
    case "failed":
      return "Failed"
    default:
      return undefined
  }
}

function parseAmountFromText(text: string): number | undefined {
  const match = text.match(/\$\s*([0-9]+(?:\.[0-9]{1,2})?)/)
  if (!match) return undefined
  const n = Number(match[1])
  return Number.isFinite(n) ? n : undefined
}

function softenActivityLabel(label: string, typeRaw: string): string {
  if (typeRaw.includes("settled") || /organizer net|destination/i.test(label)) {
    return "Booth payment settled"
  }
  return label
}

export function mapOrganizerFinancialSnapshot(data: unknown): OrganizerFinancialSnapshot {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const wallet = (d.wallet && typeof d.wallet === "object" ? d.wallet : d.balances) as
    | Record<string, unknown>
    | undefined
  const display = (d.display_balances && typeof d.display_balances === "object"
    ? d.display_balances
    : {}) as Record<string, unknown>
  const connect = (d.connect && typeof d.connect === "object"
    ? d.connect
    : d.connect_status) as Record<string, unknown> | undefined
  const stats = (d.stats && typeof d.stats === "object" ? d.stats : d.counts) as
    | Record<string, unknown>
    | undefined

  let payoutStatus: OrganizerFinancialSnapshot["payoutStatus"] = "unknown"
  const connectStatus = String(connect?.status ?? connect?.payouts_status ?? "").toLowerCase()
  if (connectStatus.includes("active") || connect?.payouts_enabled) {
    payoutStatus = "active"
  } else if (connectStatus.includes("pending")) {
    payoutStatus = "pending"
  } else if (connect && Object.keys(connect).length > 0) {
    payoutStatus = "setup_required"
  }

  const hasDashboardRevenue =
    pickNumber(d.collected, d.vendor_revenue, d.gross_revenue) !== undefined
  const hasDisplayBalances = Object.keys(display).length > 0
  const hasWalletBalances = Boolean(wallet && Object.keys(wallet).length > 0)

  const collectedToDate = pickNumber(d.collected, d.vendor_revenue, d.gross_revenue)
  // Money tab Pending sits next to Available → prefer Stripe/wallet pending, then AR.
  const pendingPayments = pickNumber(
    display.pending,
    wallet?.pending,
    d.pending_balance,
    d.wallet_pending,
    d.pending_revenue,
    d.outstanding,
  )
  const availableBalance = pickNumber(
    display.available,
    wallet?.available,
    d.available_balance,
  )

  const stripeSynced =
    typeof display.stripe_synced === "boolean"
      ? display.stripe_synced
      : typeof (d.stripe_balance as { synced?: boolean } | undefined)?.synced === "boolean"
        ? Boolean((d.stripe_balance as { synced?: boolean }).synced)
        : undefined

  const money = readMoneyContract(d)
  if (money) {
    return applyMoneyContract(
      {
        paidVendorCount: pickNumber(stats?.paid, stats?.paid_vendors, d.paid_vendor_count, d.paid_vendors),
        paymentRequiredCount: pickNumber(
          stats?.payment_required,
          stats?.unpaid,
          stats?.awaiting_payment,
          d.payment_required_count,
          d.outstanding_vendors,
        ),
        currency: "USD",
        wordpressFinancialUrl: getOrganizerFinancialUrl(),
        categories: mapMoneyCategories(d.money_categories),
        connect: mapConnectState(connect, money.settlement_mode),
      },
      money,
    )
  }

  return {
    collectedToDate: collectedToDate ?? (hasDashboardRevenue ? 0 : undefined),
    pendingPayments:
      pendingPayments ?? (hasDisplayBalances || hasWalletBalances ? 0 : undefined),
    availableBalance:
      availableBalance ?? (hasDisplayBalances || hasWalletBalances ? 0 : undefined),
    pendingBalance: pickNumber(display.pending, wallet?.pending, d.pending_balance),
    reserveBalance: pickNumber(
      display.reserve,
      display.reserve_hold,
      wallet?.reserve,
      wallet?.reserve_hold,
      d.reserve_balance,
    ),
    paidVendorCount: pickNumber(
      stats?.paid,
      stats?.paid_vendors,
      d.paid_vendor_count,
      d.paid_vendors,
    ),
    paymentRequiredCount: pickNumber(
      stats?.payment_required,
      stats?.unpaid,
      stats?.awaiting_payment,
      d.payment_required_count,
      d.outstanding_vendors,
    ),
    payoutStatus,
    stripeSynced,
    balanceSource: display.balance_source
      ? String(display.balance_source)
      : d.balance_source
        ? String(d.balance_source)
        : undefined,
    connectSettlement: pickNumber(
      display.connect_settlement,
      wallet?.connect_settlement,
      d.connect_settlement,
    ),
    currency: normalizeCurrency(
      String(
        d.currency ||
          display.currency ||
          (typeof d.stripe_balance === "object" &&
          d.stripe_balance &&
          "currency" in d.stripe_balance
            ? (d.stripe_balance as { currency?: string }).currency
            : "") ||
          "USD",
      ),
    ),
    wordpressFinancialUrl: getOrganizerFinancialUrl(),
    categories: mapMoneyCategories(d.money_categories),
    connect: mapConnectState(connect, display.settlement_mode ?? wallet?.settlement_mode),
  }
}

/** Contract figures win over legacy fields; unavailable metrics stay undefined, never 0. */
function applyMoneyContract(
  base: Pick<
    OrganizerFinancialSnapshot,
    "paidVendorCount" | "paymentRequiredCount" | "currency" | "wordpressFinancialUrl" | "categories" | "connect"
  >,
  money: MoneyContract,
): OrganizerFinancialSnapshot {
  const m = money.metrics
  const categories = base.categories.map((category) => {
    const metric = m[CONTRACT_METRIC_FOR_CATEGORY[category.key] ?? ""]
    if (!metric) return category
    const dollars = contractDollars(metric)
    return dollars == null ? { ...category, amount: 0, unavailable: true } : { ...category, amount: dollars }
  })
  const payouts = money.payouts
  const eligible = payouts ? payouts.cash_out_reason !== "payouts_not_active" : base.connect.canCashout
  const payoutStatus: OrganizerFinancialSnapshot["payoutStatus"] = !payouts
    ? "unknown"
    : payouts.state === "active" || payouts.state === "manual"
      ? "active"
      : payouts.state === "verifying"
        ? "pending"
        : "setup_required"

  return {
    ...base,
    categories,
    collectedToDate: contractDollars(m.collected),
    pendingPayments: contractDollars(m.pending),
    availableBalance: contractDollars(m.available),
    pendingBalance: contractDollars(m.pending),
    reserveBalance: contractDollars(m.reserve),
    connectSettlement: contractDollars(m.lnkup_settled),
    stripeSynced: money.settlement_mode === "destination" ? m.available?.status === "ok" : undefined,
    balanceSource: m.available?.source,
    balanceUnavailable: m.available?.status === "unavailable",
    cashOutReason: payouts?.can_cash_out ? undefined : payouts?.cash_out_reason || undefined,
    lastSyncedAt: money.last_synced_at ?? undefined,
    payoutStatus,
    connect: { ...base.connect, canCashout: eligible },
  }
}

export function mapOrganizerActivityList(data: unknown): OrganizerFinancialActivityItem[] {
  const d = (data && typeof data === "object" ? data : {}) as Record<string, unknown>
  const items = (
    Array.isArray(data) ? data : (d.items ?? d.activities ?? d.entries ?? [])
  ) as Record<string, unknown>[]

  return items.map((item, index) => {
    const typeRaw = String(
      item.type ?? item.kind ?? item.entry_type ?? "unknown",
    ).toLowerCase()
    let type: OrganizerFinancialActivityItem["type"] = "unknown"
    if (typeRaw.includes("payout") || typeRaw.includes("cashout")) type = "payout"
    else if (typeRaw.includes("refund")) type = "refund"
    else if (typeRaw.includes("reserve")) type = "reserve"
    else if (typeRaw.includes("fee")) type = "fee"
    else if (
      typeRaw.includes("payment") ||
      typeRaw.includes("credit") ||
      typeRaw.includes("settlement")
    ) {
      type = "payment"
    }

    const meta =
      item.metadata && typeof item.metadata === "object"
        ? (item.metadata as Record<string, unknown>)
        : {}
    const statusRaw = String(item.status ?? meta.status ?? "").toLowerCase()
    let status: OrganizerFinancialActivityItem["status"] = "unknown"
    if (["pending", "completed", "failed"].includes(statusRaw)) {
      status = statusRaw as OrganizerFinancialActivityItem["status"]
    } else if (statusRaw === "paid" || statusRaw === "succeeded") {
      status = "completed"
    } else if (
      typeRaw.includes("settled") ||
      typeRaw.includes("payment_received") ||
      typeRaw.includes("received")
    ) {
      status = "completed"
    }

    const rawLabel = String(
      item.label ?? item.title ?? item.message ?? item.note ?? item.description ?? "Activity",
    )
    const label = softenActivityLabel(rawLabel, typeRaw)
    let amount = pickNumber(item.amount, meta.amount)
    if (amount == null) {
      amount = parseAmountFromText(rawLabel) ?? parseAmountFromText(String(item.message ?? ""))
    }

    const eventIdRaw = item.event_id ?? meta.event_id
    return {
      id: String(item.id ?? item.entry_id ?? `org-activity-${index}`),
      type,
      label,
      amount,
      currency: normalizeCurrency(String(item.currency ?? "USD")),
      status,
      eventName: item.event_name
        ? String(item.event_name)
        : item.event_title
          ? String(item.event_title)
          : meta.event_name
            ? String(meta.event_name)
            : undefined,
      eventId: eventIdRaw != null && String(eventIdRaw) !== "0" ? String(eventIdRaw) : undefined,
      createdAt: String(item.created_at ?? item.date ?? "") || undefined,
    }
  })
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
    eventName:
      eventName ??
      (d.event_name ? String(d.event_name) : d.title ? String(d.title) : undefined),
    collected:
      pickNumber(
        d.collected_revenue,
        d.collected,
        stats.collected_revenue,
        stats.collected,
        stats.gross_revenue,
      ) ?? 0,
    pending:
      pickNumber(
        d.pending_revenue,
        d.pending,
        stats.pending_revenue,
        stats.pending,
        stats.outstanding,
        d.outstanding,
      ) ?? 0,
    paymentRequiredCount: pickNumber(
      stats.payment_required,
      stats.payment_required_count,
      d.payment_required_count,
    ),
    paidVendorCount: pickNumber(
      stats.paid,
      stats.paid_vendors,
      stats.paid_vendor_count,
      d.paid_vendor_count,
    ),
    unpaidVendorCount: pickNumber(stats.unpaid, stats.awaiting_payment),
    currency: normalizeCurrency(String(d.currency ?? "USD")),
  }
}

type OrganizerMoneyResult = {
  summary: OrganizerFinancialSnapshot
  activity: OrganizerFinancialActivityItem[]
}

const MONEY_CACHE_PREFIX = "organizer.money:"

/** Money tab payload, shared with Home's money snapshot. Invalidated after cash out. */
export async function fetchOrganizerMoney(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<OrganizerMoneyResult> {
  if (!isLiveApiEnabled()) {
    return {
      summary: { ...MOCK_ORGANIZER_MONEY },
      activity: MOCK_ORGANIZER_ACTIVITY.map((item) => ({ ...item })),
    }
  }

  if (!auth) {
    throw new Error("Sign in as an organizer to continue.")
  }

  return cachedRequest(`${MONEY_CACHE_PREFIX}${auth.username}`, () => loadOrganizerMoney(auth), options)
}

export function invalidateOrganizerMoney(): void {
  invalidateCache(MONEY_CACHE_PREFIX)
  invalidateCache(PAYOUTS_CACHE_PREFIX)
}

async function loadOrganizerMoney(auth: WpAuth): Promise<OrganizerMoneyResult> {
  const [balancesSettled, dashboardSettled, activitySettled] = await Promise.all([
    wpFetchJson<unknown>("/financial/organizer-balances", { auth }).then(
      (value) => ({ ok: true as const, value }),
      (reason: unknown) => ({ ok: false as const, reason }),
    ),
    wpFetchJson<unknown>("/financial/dashboard", {
      auth,
      query: { role: "organizer" },
    }).then(
      (value) => ({ ok: true as const, value }),
      (reason: unknown) => ({ ok: false as const, reason }),
    ),
    wpFetchJson<unknown>("/financial/activity", {
      auth,
      query: { role: "organizer" },
    })
      .catch(() =>
        wpFetchJson<unknown>("/financial/organizer-wallet/entries", { auth }),
      )
      .then(
        (value) => ({ ok: true as const, value }),
        () => ({ ok: false as const, value: {} }),
      ),
  ])

  if (!balancesSettled.ok && !dashboardSettled.ok) {
    throw balancesSettled.reason instanceof Error
      ? balancesSettled.reason
      : new Error("Could not load organizer money.")
  }

  const balances = balancesSettled.ok ? balancesSettled.value : {}
  const dashboard = dashboardSettled.ok ? dashboardSettled.value : {}
  const activityRaw = activitySettled.ok ? activitySettled.value : {}

  const merged = {
    ...(balances && typeof balances === "object" ? balances : {}),
    ...(dashboard && typeof dashboard === "object" ? dashboard : {}),
  }

  const activity = mapOrganizerActivityList(activityRaw).slice(0, 20)
  // Collected comes only from the server ledger; never re-derived from the activity feed.
  const summary = mapOrganizerFinancialSnapshot(merged)

  return {
    summary,
    activity,
  }
}

/** One event's collected/outstanding booth fees from the financial ledger. */
export async function fetchOrganizerEventMoney(
  eventId: string,
  eventName: string | undefined,
  auth?: WpAuth,
): Promise<OrganizerEventMoneySummary> {
  if (!isLiveApiEnabled()) {
    return MOCK_ORGANIZER_MONEY_EVENTS.find((item) => item.eventId === eventId) ?? mapOrganizerEventMoney(eventId, {}, eventName)
  }
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  const financials = await wpFetchJson(`/financial/events/${eventId}/financials`, { auth })
  return mapOrganizerEventMoney(eventId, financials, eventName)
}

export async function fetchOrganizerMoneyEvents(
  auth?: WpAuth,
): Promise<OrganizerEventMoneySummary[]> {
  if (!isLiveApiEnabled()) {
    return MOCK_ORGANIZER_MONEY_EVENTS.map((item) => ({ ...item }))
  }

  if (!auth) return []

  const inviteRaw = await wpFetchJson<{ items?: unknown[] } | unknown[]>(
    "/crm/events/invite-options?include_all=1",
    { auth },
  ).catch(() => [])

  const inviteList = Array.isArray(inviteRaw)
    ? inviteRaw
    : Array.isArray((inviteRaw as { items?: unknown[] })?.items)
      ? (inviteRaw as { items: unknown[] }).items
      : []

  // Drafts never take booth fees, so they must not crowd real events out of the capped list.
  const baseEvents = inviteList
    .map((item) => mapInviteOptionToEventLite(item as never))
    .filter((event) => event.id && event.publishStatus !== "draft")
    .slice(0, 8)

  const summaries = await Promise.all(
    baseEvents.map(async (event) => {
      try {
        const financials = await wpFetchJson(`/financial/events/${event.id}/financials`, {
          auth,
        })
        return mapOrganizerEventMoney(event.id, financials, event.title)
      } catch {
        return null
      }
    }),
  )
  return summaries.filter((item): item is OrganizerEventMoneySummary => item != null && hasEventMoney(item))
}

function hasEventMoney(item: OrganizerEventMoneySummary): boolean {
  return (
    (item.collected ?? 0) > 0 ||
    (item.pending ?? 0) > 0 ||
    (item.paidVendorCount ?? 0) > 0 ||
    (item.paymentRequiredCount ?? 0) > 0
  )
}

const PAYOUTS_CACHE_PREFIX = "organizer.payouts:"

/** Cash out history, newest first as returned by WordPress. */
export async function fetchOrganizerPayouts(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<OrganizerPayout[]> {
  if (!isLiveApiEnabled()) return []
  if (!auth) throw new Error("Sign in as an organizer to continue.")
  return cachedRequest(
    `${PAYOUTS_CACHE_PREFIX}${auth.username}`,
    async () => mapOrganizerPayouts(await wpFetchJson<unknown>("/financial/organizer-payouts", { auth })),
    options,
  )
}

/** Cancel a requested cash out; the server restores the balance and rejects ones already processing. */
export async function cancelOrganizerPayout(auth: WpAuth | undefined, payoutId: string): Promise<void> {
  if (!isLiveApiEnabled()) {
    throw new WpFetchError("Cancelling a cash out needs a live LNKup connection.", "connect_fixture", 400)
  }
  if (!auth) throw new WpFetchError("Sign in to manage cash outs.", "auth_required", 401)
  try {
    await wpFetchJson(`/financial/organizer-payouts/${payoutId}/cancel`, {
      auth,
      method: "POST",
      body: {},
    })
  } finally {
    invalidateOrganizerMoney()
  }
}

type ConnectOnboardingResponse = {
  success?: boolean
  url?: string
  message?: string
  code?: string
}

/**
 * Create a Stripe Connect Account Link via WordPress Financial OS.
 * Passes allowlisted HTTPS bridge return/refresh URLs so Stripe returns to the app.
 */
export async function fetchConnectOnboardingLink(auth?: WpAuth): Promise<{ url: string }> {
  if (!isLiveApiEnabled()) {
    throw new WpFetchError(
      "Payout setup needs a live LNKup connection.",
      "connect_fixture",
      400,
    )
  }
  if (!auth) {
    throw new WpFetchError("Sign in to set up payouts.", "auth_required", 401)
  }

  const raw = await wpFetchJson<ConnectOnboardingResponse>("/financial/connect/onboarding-link", {
    auth,
    method: "POST",
    body: {
      return_url: getConnectAppBridgeUrl("return"),
      refresh_url: getConnectAppBridgeUrl("refresh"),
    },
  })

  if (!raw?.success || !raw.url) {
    throw new WpFetchError(
      String(raw?.message || "Could not start payout setup."),
      String(raw?.code || "connect_error"),
      400,
    )
  }

  return { url: String(raw.url) }
}

/** Sync Connect account from Stripe and return status payload. */
export async function refreshConnectStatus(auth?: WpAuth): Promise<unknown> {
  if (!isLiveApiEnabled() || !auth) return null
  return wpFetchJson<unknown>("/financial/connect/refresh", {
    auth,
    method: "POST",
    body: {},
  })
}

type ConnectLoginResponse = {
  success?: boolean
  url?: string
  message?: string
  code?: string
}

/** Open Stripe Express dashboard via Financial OS login link. */
export async function fetchConnectLoginLink(auth?: WpAuth): Promise<{ url: string }> {
  if (!isLiveApiEnabled()) {
    throw new WpFetchError(
      "Stripe Express needs a live LNKup connection.",
      "connect_fixture",
      400,
    )
  }
  if (!auth) {
    throw new WpFetchError("Sign in to open Stripe Express.", "auth_required", 401)
  }

  const raw = await wpFetchJson<ConnectLoginResponse>("/financial/connect/login-link", {
    auth,
    method: "POST",
    body: {},
  })

  if (!raw?.url) {
    throw new WpFetchError(
      String(raw?.message || "Could not open Stripe Express."),
      String(raw?.code || "connect_error"),
      400,
    )
  }

  return { url: String(raw.url) }
}

export async function requestOrganizerCashout(
  auth: WpAuth | undefined,
  amount: number,
  type: "standard" | "instant" = "standard",
  clientToken?: string,
): Promise<{ payoutId?: string; message?: string }> {
  if (!isLiveApiEnabled()) {
    return { payoutId: "demo-payout", message: "Cash out requested (demo)." }
  }
  if (!auth) {
    throw new WpFetchError("Sign in to cash out.", "auth_required", 401)
  }
  if (!Number.isFinite(amount) || amount <= 0) {
    throw new WpFetchError("Enter a cash out amount greater than zero.", "invalid_amount", 400)
  }

  const raw = await wpFetchJson<{ payout_id?: number | string; message?: string }>(
    "/financial/organizer-wallet/cashout",
    {
      auth,
      method: "POST",
      body: { amount, type, ...(clientToken ? { client_token: clientToken } : {}) },
    },
  )
  invalidateOrganizerMoney()

  return {
    payoutId: raw.payout_id != null ? String(raw.payout_id) : undefined,
    message: raw.message,
  }
}

