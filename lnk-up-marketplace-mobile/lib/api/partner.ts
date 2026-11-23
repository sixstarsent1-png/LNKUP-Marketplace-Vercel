import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * Partner home client (City Connector, affiliate, strategic partner) over the existing
 * `lnkup/v1/connector/*` routes. WordPress decides terms, attribution and balances; this file only
 * reshapes them. Estimated earnings are never mixed into payable balances.
 */

export type PartnerProgram = "city_connector" | "affiliate" | "strategic_partner"

export type PartnerTerms = {
  enabled: boolean
  termsPending: boolean
  sharePercent: number
  shareCap: number
  termMonths: number
  holdDays: number
  minimumPayout: number
  basis: string
  basisLabel: string
  vendorReferralsRewarded: boolean
  termsUrl: string
  supportUrl: string
}

export type PartnerLinks = {
  organizer: string
  vendor: string
  general: string
  cityPage: string
  code: string
  city: string
}

export type PartnerBalances = {
  pendingHold: number
  awaitingApproval: number
  onHold: number
  approved: number
  paid: number
  reversed: number
  /** Projection from attributed booth GMV. Never payable. */
  estimated: number
}

export type PartnerDashboard = {
  program: PartnerProgram
  programLabel: string
  title: string
  organization: string
  accountStatus: string
  code: string
  links: PartnerLinks
  terms: PartnerTerms
  stats: {
    vendorSignups: number
    organizerSignups: number
    opportunities: number
    paidBookings: number
    activated: number
  }
  balances: PartnerBalances
}

export type PartnerReferralMilestone =
  | "registered"
  | "profile_completed"
  | "verified"
  | "opportunity_published"
  | "first_payment"
  | "activated"
  | "reward_pending"
  | "reward_paid"

export type PartnerReferral = {
  id: number
  kind: "organizer" | "vendor"
  displayName: string
  city: string
  referralDate: string
  milestone: string
  estimatedEarnings: number
  approvedEarnings: number
  remainingTerm: string
  rewardEligible: boolean
}

export type PartnerReferrals = {
  organizers: PartnerReferral[]
  vendors: PartnerReferral[]
  counts: { clicks: number; registrations: number; opportunities: number; activated: number; bookings: number }
  vendorReferralsRewarded: boolean
}

export type PartnerLedgerStatus =
  | "pending_hold"
  | "awaiting_approval"
  | "on_hold"
  | "approved"
  | "paid"
  | "reversed"
  | "expired"

export type PartnerLedgerRow = {
  id: number
  eventType: string
  netAmount: number
  status: PartnerLedgerStatus | string
  relatedName: string
  createdAt: string
  availableAt: string
  paidAt: string
}

export type PartnerEarnings = {
  terms: PartnerTerms
  balances: PartnerBalances
  ledger: PartnerLedgerRow[]
  ledgerTotal: number
  ledgerPage: number
  ledgerHasMore: boolean
  nextPayout: string
}

export type PartnerLead = {
  id: number
  name: string
  city: string
  state: string
  category: string
  stageLabel: string
  outcomeLabel: string
  submittedAt: string
}

export type PartnerLeads = {
  enabled: boolean
  items: PartnerLead[]
  total: number
  page: number
  perPage: number
  dailyLimit: number
  submittedToday: number
}

export type PartnerLeadInput = {
  name: string
  email?: string
  website?: string
  instagram?: string
  city?: string
  state?: string
  eventType?: string
  reason?: string
}

export type PartnerLeadResult = {
  status: "submitted" | "already_in_pipeline" | "not_accepted" | string
  message: string
}

export const MILESTONE_LABELS: Record<PartnerReferralMilestone, string> = {
  registered: "Registered",
  profile_completed: "Profile completed",
  verified: "Verified",
  opportunity_published: "Opportunity published",
  first_payment: "First payment",
  activated: "Activated",
  reward_pending: "Commission pending",
  reward_paid: "Commission paid",
}

export const LEDGER_STATUS_LABELS: Record<PartnerLedgerStatus, string> = {
  pending_hold: "Pending hold",
  awaiting_approval: "Awaiting approval",
  on_hold: "Under review",
  approved: "Approved",
  paid: "Paid",
  reversed: "Reversed",
  expired: "Expired",
}

export function milestoneLabel(milestone: string): string {
  return MILESTONE_LABELS[milestone as PartnerReferralMilestone] ?? "Registered"
}

export function ledgerStatusLabel(status: string): string {
  return LEDGER_STATUS_LABELS[status as PartnerLedgerStatus] ?? "Pending"
}

/** Recorded but not yet payable: refund hold plus LNKup review. */
export function pendingTotal(b: PartnerBalances): number {
  return round2(b.pendingHold + b.awaitingApproval + b.onHold)
}

/** One-line commission summary; never claims a rate while terms are pending. */
export function termsSummary(terms: PartnerTerms): string {
  if (terms.termsPending) return "Your commission terms are being finalized. Referrals are tracked in the meantime."
  if (!terms.enabled) return "Commissions are paused right now. Referrals are still tracked."
  // basis_label is a full phrase from the commission rule ("10% of the booth price on qualifying bookings").
  const rate = terms.basisLabel || `${terms.sharePercent}% of the booth price on qualifying bookings`
  return `${rate}, for ${terms.termMonths} months after each referral joins. Payable after a ${terms.holdDays}-day hold and LNKup approval.`
}

/** Mirrors the server rule so the lead form can explain it before a round trip. */
export function leadFormError(form: PartnerLeadInput): string | null {
  if (!form.name.trim()) return "Add the organizer or business name."
  if (![form.email, form.website, form.instagram].some((v) => (v ?? "").trim())) {
    return "Add a public website, Instagram or business email so LNKup can verify the organizer."
  }
  return null
}

// ---------------------------------------------------------------- Normalizers.

type Raw = Record<string, unknown>

function obj(value: unknown): Raw {
  return value && typeof value === "object" && !Array.isArray(value) ? (value as Raw) : {}
}

function num(value: unknown): number {
  const n = typeof value === "number" ? value : Number.parseFloat(String(value ?? ""))
  return Number.isFinite(n) ? n : 0
}

function str(value: unknown): string {
  return typeof value === "string" ? value : value == null ? "" : String(value)
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

const PROGRAMS = new Set<PartnerProgram>(["city_connector", "affiliate", "strategic_partner"])

export function normalizeTerms(raw: unknown): PartnerTerms {
  const t = obj(raw)
  return {
    enabled: t.enabled === true,
    termsPending: t.terms_pending === true,
    sharePercent: num(t.share_percent),
    shareCap: num(t.share_cap),
    termMonths: Math.max(0, Math.round(num(t.term_months))),
    holdDays: Math.max(0, Math.round(num(t.hold_days))),
    minimumPayout: num(t.minimum_payout),
    basis: str(t.basis) || "booth_gmv",
    basisLabel: str(t.basis_label),
    vendorReferralsRewarded: t.vendor_referrals_rewarded === true,
    termsUrl: str(t.terms_url),
    supportUrl: str(t.support_url),
  }
}

export function normalizeBalances(raw: unknown, estimated?: unknown): PartnerBalances {
  const b = obj(raw)
  return {
    pendingHold: num(b.pending_hold),
    awaitingApproval: num(b.awaiting_approval),
    onHold: num(b.on_hold),
    approved: num(b.approved),
    paid: num(b.paid),
    reversed: num(b.reversed),
    estimated: num(estimated ?? b.estimated),
  }
}

export function normalizeDashboard(raw: unknown): PartnerDashboard {
  const d = obj(raw)
  const links = obj(d.links)
  const stats = obj(d.stats)
  const program = str(d.program) as PartnerProgram
  return {
    program: PROGRAMS.has(program) ? program : "city_connector",
    programLabel: str(d.program_label) || "City Connector",
    title: str(d.title) || "Partner dashboard",
    organization: str(d.organization),
    accountStatus: str(d.account_status),
    code: str(d.code) || str(links.code),
    links: {
      organizer: str(links.organizer),
      vendor: str(links.vendor),
      general: str(links.general),
      cityPage: str(links.city_page),
      code: str(links.code) || str(d.code),
      city: str(links.city),
    },
    terms: normalizeTerms(d.terms),
    stats: {
      vendorSignups: num(stats.vendor_signups),
      organizerSignups: num(stats.organizer_signups),
      opportunities: num(stats.opportunities),
      paidBookings: num(stats.paid_bookings),
      activated: num(stats.activated),
    },
    balances: normalizeBalances(d.earnings),
  }
}

function normalizeReferral(raw: unknown): PartnerReferral | null {
  const r = obj(raw)
  const id = num(r.id)
  if (!id) return null
  return {
    id,
    kind: r.kind === "vendor" ? "vendor" : "organizer",
    displayName: str(r.display_name) || (r.kind === "vendor" ? "Vendor" : "Organizer"),
    city: str(r.city),
    referralDate: str(r.referral_date),
    milestone: str(r.milestone) || "registered",
    estimatedEarnings: num(r.estimated_earnings),
    approvedEarnings: num(r.approved_earnings),
    remainingTerm: str(r.remaining_term),
    rewardEligible: r.reward_eligible === true,
  }
}

export function normalizeReferrals(raw: unknown): PartnerReferrals {
  const f = obj(raw)
  const counts = obj(f.counts)
  const list = (value: unknown) =>
    (Array.isArray(value) ? value : []).map(normalizeReferral).filter((r): r is PartnerReferral => r !== null)
  return {
    organizers: list(f.organizers),
    vendors: list(f.vendors),
    counts: {
      clicks: num(counts.clicks),
      registrations: num(counts.registrations),
      opportunities: num(counts.opportunities),
      activated: num(counts.activated),
      bookings: num(counts.bookings),
    },
    vendorReferralsRewarded: f.vendor_referrals_rewarded === true,
  }
}

export function normalizeEarnings(raw: unknown): PartnerEarnings {
  const e = obj(raw)
  const ledger = (Array.isArray(e.ledger) ? e.ledger : [])
    .map((row): PartnerLedgerRow | null => {
      const r = obj(row)
      const id = num(r.id)
      if (!id) return null
      return {
        id,
        eventType: str(r.event_type),
        netAmount: num(r.net_amount),
        status: str(r.display_status) || "pending_hold",
        relatedName: str(r.related_name),
        createdAt: str(r.created_at),
        availableAt: str(r.available_at),
        paidAt: str(r.paid_at),
      }
    })
    .filter((r): r is PartnerLedgerRow => r !== null)
  return {
    terms: normalizeTerms(e.terms),
    balances: normalizeBalances(e.balances),
    ledger,
    ledgerTotal: num(e.ledger_total) || ledger.length,
    ledgerPage: Math.max(1, num(e.ledger_page)),
    ledgerHasMore: e.ledger_has_more === true,
    nextPayout: str(e.next_payout),
  }
}

export function normalizeLeads(raw: unknown): PartnerLeads {
  const l = obj(raw)
  const items = (Array.isArray(l.items) ? l.items : [])
    .map((row): PartnerLead | null => {
      const r = obj(row)
      const id = num(r.id)
      if (!id) return null
      return {
        id,
        name: str(r.name) || "Organizer",
        city: str(r.city),
        state: str(r.state),
        category: str(r.category),
        stageLabel: str(r.stage_label),
        outcomeLabel: str(r.outcome_label),
        submittedAt: str(r.submitted_at),
      }
    })
    .filter((r): r is PartnerLead => r !== null)
  return {
    enabled: l.enabled !== false,
    items,
    total: num(l.total) || items.length,
    page: Math.max(1, num(l.page)),
    perPage: Math.max(1, num(l.per_page) || 20),
    dailyLimit: num(l.daily_limit),
    submittedToday: num(l.submitted_today),
  }
}

// ---------------------------------------------------------------- Mocks (demo mode).

const MOCK_TERMS = {
  enabled: true,
  terms_pending: false,
  share_percent: 10,
  share_cap: 0,
  term_months: 12,
  hold_days: 30,
  minimum_payout: 50,
  basis: "booth_gmv",
  basis_label: "10% of the booth price on qualifying bookings",
  vendor_referrals_rewarded: false,
  terms_url: "",
  support_url: "",
}

const MOCK_BALANCES = { pending_hold: 18, awaiting_approval: 0, on_hold: 0, approved: 25, paid: 40, reversed: 0, expired: 0 }

const MOCK_DASHBOARD = {
  program: "city_connector",
  program_label: "City Connector",
  title: "City Connector",
  organization: "",
  account_status: "active",
  code: "DEMOCITY",
  links: {
    organizer: "https://thelnkup.com/sign-up/?ref=DEMOCITY&role=organizer",
    vendor: "https://thelnkup.com/sign-up/?ref=DEMOCITY&role=vendor",
    general: "https://thelnkup.com/sign-up/?ref=DEMOCITY",
    code: "DEMOCITY",
    city: "Charlotte",
  },
  terms: MOCK_TERMS,
  stats: { vendor_signups: 3, organizer_signups: 2, opportunities: 1, paid_bookings: 4, activated: 1 },
  earnings: { ...MOCK_BALANCES, estimated: 60 },
}

const MOCK_FUNNEL = {
  organizers: [
    {
      id: 1,
      kind: "organizer",
      display_name: "Demo Night Market",
      city: "Charlotte",
      referral_date: "2026-09-14 10:00:00",
      milestone: "activated",
      estimated_earnings: 42,
      approved_earnings: 25,
      remaining_term: "9 months left",
      reward_eligible: true,
    },
    {
      id: 2,
      kind: "organizer",
      display_name: "Southside Pop-Up",
      city: "Charlotte",
      referral_date: "2026-11-02 15:30:00",
      milestone: "registered",
      estimated_earnings: 0,
      approved_earnings: 0,
      remaining_term: "12 months left",
      reward_eligible: false,
    },
  ],
  vendors: [
    {
      id: 3,
      kind: "vendor",
      display_name: "Queen City Candles",
      city: "Charlotte",
      referral_date: "2026-10-20 09:00:00",
      milestone: "first_payment",
      estimated_earnings: 0,
      approved_earnings: 0,
      remaining_term: "",
      reward_eligible: false,
    },
  ],
  counts: { clicks: 38, registrations: 2, opportunities: 1, activated: 1, bookings: 4 },
  vendor_referrals_rewarded: false,
}

const MOCK_EARNINGS = {
  terms: MOCK_TERMS,
  balances: MOCK_BALANCES,
  ledger: [
    { id: 11, event_type: "connector_booking_fee", net_amount: 18, display_status: "pending_hold", related_name: "Demo Night Market", created_at: "2026-11-12 12:00:00", available_at: "2026-12-12 12:00:00", paid_at: "" },
    { id: 10, event_type: "connector_booking_fee", net_amount: 25, display_status: "approved", related_name: "Demo Night Market", created_at: "2026-10-05 12:00:00", available_at: "2026-11-04 12:00:00", paid_at: "" },
    { id: 9, event_type: "connector_booking_fee", net_amount: 40, display_status: "paid", related_name: "Demo Night Market", created_at: "2026-09-20 12:00:00", available_at: "2026-10-20 12:00:00", paid_at: "2026-10-25 12:00:00" },
  ],
  next_payout: "Dec 12, 2026",
}

const MOCK_LEADS = {
  enabled: true,
  items: [
    { id: 21, name: "Plaza Midwood Makers Fair", city: "Charlotte", state: "NC", category: "Market", stage_label: "Contacted", outcome_label: "", submitted_at: "2026-11-10 10:00:00" },
  ],
  total: 1,
  page: 1,
  per_page: 20,
  daily_limit: 10,
  submitted_today: 0,
}

// ---------------------------------------------------------------- Requests.

export async function fetchPartnerDashboard(auth: WpAuth | undefined): Promise<PartnerDashboard> {
  if (!isLiveApiEnabled()) return normalizeDashboard(MOCK_DASHBOARD)
  return normalizeDashboard(await wpFetchJson<unknown>("/connector/dashboard", { auth }))
}

export async function fetchPartnerReferrals(auth: WpAuth | undefined): Promise<PartnerReferrals> {
  if (!isLiveApiEnabled()) return normalizeReferrals(MOCK_FUNNEL)
  return normalizeReferrals(await wpFetchJson<unknown>("/connector/funnel", { auth }))
}

export async function fetchPartnerEarnings(auth: WpAuth | undefined, page = 1): Promise<PartnerEarnings> {
  if (!isLiveApiEnabled()) return normalizeEarnings(MOCK_EARNINGS)
  return normalizeEarnings(await wpFetchJson<unknown>("/connector/earnings", { auth, query: { page } }))
}

export async function fetchPartnerLeads(auth: WpAuth | undefined, page = 1): Promise<PartnerLeads> {
  if (!isLiveApiEnabled()) return normalizeLeads(MOCK_LEADS)
  return normalizeLeads(await wpFetchJson<unknown>("/connector/leads", { auth, query: { page, per_page: 20 } }))
}

export async function submitPartnerLead(auth: WpAuth | undefined, input: PartnerLeadInput): Promise<PartnerLeadResult> {
  if (!isLiveApiEnabled()) {
    return { status: "submitted", message: "Thanks! LNKup will review this organizer. A lead is not an acquired organizer until they register and activate." }
  }
  const res = await wpFetchJson<Raw>("/connector/leads", {
    method: "POST",
    auth,
    body: {
      name: input.name,
      email: input.email ?? "",
      website: input.website ?? "",
      instagram: input.instagram ?? "",
      city: input.city ?? "",
      state: input.state ?? "",
      event_type: input.eventType ?? "",
      reason: input.reason ?? "",
      source: "mobile_app",
    },
  })
  return { status: str(res.status) || "submitted", message: str(res.message) }
}
