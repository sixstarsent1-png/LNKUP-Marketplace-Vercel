import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import { cachedRequest, invalidateCache } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * Organizer Mobile OS 2.0 read models (`LNKup_Organizer_Mobile_REST`). Each fetcher returns null
 * when the server predates the route so screens can fall back to their previous calls.
 */

export type OrgEventMedia = {
  url: string
  mediumUrl: string
  fit: "cover" | "contain"
  background: string
  alt: string
}

export type OrgChronology = "upcoming" | "today" | "completed" | "undated"

export type OrgEventCard = {
  id: string
  title: string
  date: string
  dateLabel: string
  daysUntil: number | null
  venue: string
  place: string
  publication: "live" | "draft"
  publicationLabel: string
  chronology: OrgChronology
  chronologyLabel: string
  applicationsOpen: boolean
  applications: {
    total: number
    pending: number
    infoRequested: number
    held: number
    confirmed: number
    waitlisted: number
    declined: number
  }
  capacity: { total: number; confirmed: number; held: number; open: number; waitlisted: number; boothTypes: number }
  tourId: string | null
  tourTitle: string
  publicUrl: string
  media: OrgEventMedia | null
  fallbackUrl: string
  money?: { collectedGross: number; outstanding: number; paidBookings: number }
}

export type OrgSetupStage = { key: string; title: string; status: "complete" | "in_progress" | "not_started"; missing: string[] }

export type OrgSetup = {
  percent: number
  completed: number
  total: number
  isActivated: boolean
  next: { stage: string; title: string; label: string; missing: string[] } | null
  stages: OrgSetupStage[]
  share: { eventId: string; eventTitle: string; applicationUrl: string } | null
}

export type OrgQueueKey = "review" | "payment" | "documents" | "booths" | "packet"

export type OrgQueueGroup = {
  key: OrgQueueKey
  label: string
  count: number
  rows: { appId: string | null; eventId: string; title: string; meta: string }[]
}

export type OrgKpi = { value: number; label: string }

export type OrgRecruitmentGap = {
  eventId: string
  eventTitle: string
  dateLabel: string
  opportunityId: string
  boothType: string
  category: string
  open: number
  total: number
}

export type OrgHomeLifecycle = "new" | "setup" | "event_day" | "active"

export type OrgHomeSummary = {
  lifecycle: OrgHomeLifecycle
  setup: OrgSetup
  kpis: {
    collected: OrgKpi
    awaitingReview: OrgKpi
    approvedUnpaid: OrgKpi
    upcomingEvents: OrgKpi
    confirmed: OrgKpi
  }
  events: {
    counts: { total: number; live: number; draft: number; upcoming: number; today: number; completed: number }
    today: OrgEventCard[]
    next: OrgEventCard | null
    upcoming: OrgEventCard[]
  }
  recruitment: OrgRecruitmentGap[]
  queue: OrgQueueGroup[]
  money: {
    collected: number
    refunds: number
    netEarnings: number
    available: number | null
    pending: number | null
    label: string
    payoutsReady: boolean | null
  }
}

export type CheckinReason =
  | "ready"
  | "already_checked_in"
  | "payment_pending"
  | "documents"
  | "invalid_booking"
  | "wrong_event"
  | "event_completed"
  | "forbidden"
  | "not_found"
  | "conflict"

export type CheckinRosterRow = {
  applicationId: string
  vendorName: string
  category: string
  booth: string
  reason: CheckinReason
  eligible: boolean
  checkedIn: boolean
  checkedInAt: string
  opsStatus: string
  actions: string[]
}

export type CheckinPopulations = {
  booked: number
  expected: number
  checkedIn: number
  waiting: number
  noShow: number
  invalid: number
  blocked: { paymentPending: number; documents: number }
  arrivalPct: number
}

export type CheckinSummary = CheckinPopulations & {
  eventId: string
  title: string
  date: string
  chronology: OrgChronology
  rows: CheckinRosterRow[]
  generatedAt: string
}

export type OpsTask = { key: string; label: string; detail: string; done: boolean; canAct: boolean; owner: "organizer" | "vendor" }

export type ReadinessVendor = {
  applicationId: string
  businessName: string
  percent: number
  tier: string
  tierLabel: string
  statusLabel: string
  blocker: string
}

export type OpsMode = "pre_event" | "event_day" | "post_event"

export type OperationsSummary = {
  event: OrgEventCard
  mode: OpsMode
  myRole: string
  can: Record<string, boolean>
  lifecycle: { stage: string; stageLabel: string; progress: number }
  checkin: CheckinPopulations
  booths: { total: number; assigned: number; open: number; confirmed: number; unplaced: number }
  packet: {
    prepared: [number, number]
    published: string
    stage: string
    accessible: [number, number]
    viewed: [number, number]
    acknowledged: [number, number]
  }
  readiness: { percent: number; readyCount: number; atRiskCount: number; vendorCount: number; vendors: ReadinessVendor[] }
  logistics: { loadinWindows: number; parkingAreas: number }
  incidents: { open: number; total: number }
  staff: { userId: string; name: string; role: string; roleLabel: string }[]
  tasks: OpsTask[]
}

export type InviteStatus = "sent" | "opened" | "accepted" | "declined" | "expired" | "cancelled"

export type OrgInvite = {
  id: string
  vendorUserId: string
  vendorProfileId: string | null
  businessName: string
  logoUrl: string
  status: InviteStatus
  eventId: string | null
  eventTitle: string
  eventDate: string
  opportunityTitle: string
  message: string
  sentAt: string
  expiresAt: string
  remindedAt: string
  reminderCount: number
  respondedAt: string
  canRemind: boolean
  canCancel: boolean
  applicationId: string | null
  applicationStatus: string
}

export type InviteOverview = {
  items: OrgInvite[]
  counts: { sent: number; awaiting: number; accepted: number; declined: number; expired: number; cancelled: number; applied: number }
}

export type PayoutPreview = {
  type: "standard" | "instant"
  amount: number
  available: number | null
  feeAmount: number
  feeRate: number
  providerFeeNote: string
  estimatedNet: number
  netIsEstimate: boolean
  method: "connect" | "lnkup"
  destinationSummary: string
  timingNote: string
  canSubmit: boolean
  problem: string
}

export type ScoreFactor = {
  key: string
  label: string
  explanation: string
  value: number
  weightPct: number | null
  estimated: boolean
  action: { label: string; target: string } | null
}

export type EventScore = { eventId: string; score: number; label: string; labelKey: string; isNew: boolean; factors: ScoreFactor[] }

export type ApplicationReview = {
  applicationId: string
  status: string
  submittedAt: string
  message: string
  setupNotes: string
  vendor: {
    profileId: string | null
    businessName: string
    logoUrl: string
    category: string
    place: string
    verified: boolean
    completedEvents: number
    rating: number
    reviewCount: number
    reputation: string
  }
  event: { id: string; title: string; date: string; place: string; boothType: string; open: number; unlimited: boolean }
  fee: number
  paymentDueAt: string
  answers: { question: string; answer: string }[]
  documents: { code: string; items: { slug: string; label: string; status: string }[] }
  actions: { approve: boolean; waitlist: boolean; reject: boolean; requestInfo: boolean }
  approveNote: string
}

type Wp = Record<string, unknown>

const num = (v: unknown, fallback = 0): number => {
  const n = Number(v)
  return Number.isFinite(n) ? n : fallback
}
const str = (v: unknown): string => (v == null ? "" : String(v))
const idOrNull = (v: unknown): string | null => (num(v) > 0 ? String(num(v)) : null)
const pair = (v: unknown): [number, number] => (Array.isArray(v) ? [num(v[0]), num(v[1])] : [0, 0])
const obj = (v: unknown): Wp => (v && typeof v === "object" ? (v as Wp) : {})

function mapMedia(raw: unknown): OrgEventMedia | null {
  const m = obj(raw)
  const url = str(m.large_url) || str(m.url)
  if (!url) return null
  return {
    url,
    mediumUrl: str(m.medium_url) || url,
    fit: m.fit === "contain" ? "contain" : "cover",
    background: str(m.background),
    alt: str(m.alt),
  }
}

const CHRONOLOGIES: readonly OrgChronology[] = ["upcoming", "today", "completed", "undated"]

export function mapEventCard(raw: unknown): OrgEventCard | null {
  const r = obj(raw)
  if (r.id == null) return null
  const apps = obj(r.applications)
  const cap = obj(r.capacity)
  const money = r.money ? obj(r.money) : null
  const chronology = CHRONOLOGIES.includes(r.chronology as OrgChronology) ? (r.chronology as OrgChronology) : "undated"
  return {
    id: String(r.id),
    title: str(r.title),
    date: str(r.date),
    dateLabel: str(r.date_label),
    daysUntil: r.days_until == null ? null : num(r.days_until),
    venue: str(r.venue),
    place: str(r.place),
    publication: r.publication === "live" ? "live" : "draft",
    publicationLabel: str(r.publication_label),
    chronology,
    chronologyLabel: str(r.chronology_label),
    applicationsOpen: Boolean(r.applications_open),
    applications: {
      total: num(apps.total),
      pending: num(apps.pending),
      infoRequested: num(apps.info_requested),
      held: num(apps.held),
      confirmed: num(apps.confirmed),
      waitlisted: num(apps.waitlisted),
      declined: num(apps.declined),
    },
    capacity: {
      total: num(cap.total),
      confirmed: num(cap.confirmed),
      held: num(cap.held),
      open: num(cap.open),
      waitlisted: num(cap.waitlisted),
      boothTypes: num(cap.booth_types),
    },
    tourId: idOrNull(r.tour_id),
    tourTitle: str(r.tour_title),
    publicUrl: str(r.public_url),
    media: mapMedia(r.media),
    fallbackUrl: str(r.fallback_url),
    money: money
      ? { collectedGross: num(money.collected_gross), outstanding: num(money.outstanding), paidBookings: num(money.paid_bookings) }
      : undefined,
  }
}

const cards = (v: unknown): OrgEventCard[] =>
  (Array.isArray(v) ? v : []).map(mapEventCard).filter((c): c is OrgEventCard => c !== null)

const QUEUE_ORDER: readonly OrgQueueKey[] = ["review", "payment", "documents", "booths", "packet"]
const SETUP_STATUSES = ["complete", "in_progress", "not_started"] as const
const LIFECYCLES: readonly OrgHomeLifecycle[] = ["new", "setup", "event_day", "active"]

const kpi = (v: unknown): OrgKpi => ({ value: num(obj(v).value), label: str(obj(v).label) })
const nullableMoney = (v: unknown): number | null => (v == null ? null : num(v))

export function mapHomeSummary(raw: unknown): OrgHomeSummary {
  const r = obj(raw)
  const setup = obj(r.setup)
  const next = setup.next ? obj(setup.next) : null
  const share = setup.share ? obj(setup.share) : null
  const events = obj(r.events)
  const counts = obj(events.counts)
  const queue = obj(r.queue)
  const money = obj(r.money)
  const kpis = obj(r.kpis)
  return {
    lifecycle: LIFECYCLES.includes(r.lifecycle as OrgHomeLifecycle) ? (r.lifecycle as OrgHomeLifecycle) : "active",
    setup: {
      percent: num(setup.percent),
      completed: num(setup.completed),
      total: num(setup.total),
      isActivated: Boolean(setup.is_activated),
      next: next
        ? { stage: str(next.stage), title: str(next.title), label: str(next.label), missing: (Array.isArray(next.missing) ? next.missing : []).map(str) }
        : null,
      stages: (Array.isArray(setup.stages) ? setup.stages : []).map((s) => {
        const st = obj(s)
        const status = SETUP_STATUSES.find((v) => v === st.status) ?? "not_started"
        return { key: str(st.key), title: str(st.title), status, missing: (Array.isArray(st.missing) ? st.missing : []).map(str) }
      }),
      share: share ? { eventId: str(share.event_id), eventTitle: str(share.event_title), applicationUrl: str(share.application_url) } : null,
    },
    kpis: {
      collected: kpi(kpis.collected),
      awaitingReview: kpi(kpis.awaiting_review),
      approvedUnpaid: kpi(kpis.approved_unpaid),
      upcomingEvents: kpi(kpis.upcoming_events),
      confirmed: kpi(kpis.confirmed),
    },
    events: {
      counts: {
        total: num(counts.total),
        live: num(counts.live),
        draft: num(counts.draft),
        upcoming: num(counts.upcoming),
        today: num(counts.today),
        completed: num(counts.completed),
      },
      today: cards(events.today),
      next: mapEventCard(events.next),
      upcoming: cards(events.upcoming),
    },
    recruitment: (Array.isArray(r.recruitment) ? r.recruitment : []).map((g) => {
      const gap = obj(g)
      return {
        eventId: str(gap.event_id),
        eventTitle: str(gap.event_title),
        dateLabel: str(gap.date_label),
        opportunityId: str(gap.opportunity_id),
        boothType: str(gap.booth_type),
        category: str(gap.category),
        open: num(gap.open),
        total: num(gap.total),
      }
    }),
    queue: QUEUE_ORDER.filter((key) => queue[key]).map((key) => {
      const g = obj(queue[key])
      return {
        key,
        label: str(g.label),
        count: num(g.count),
        rows: (Array.isArray(g.rows) ? g.rows : []).map((row) => {
          const x = obj(row)
          return { appId: idOrNull(x.app_id), eventId: str(x.event_id), title: str(x.title), meta: str(x.meta) }
        }),
      }
    }),
    money: {
      collected: num(money.collected),
      refunds: num(money.refunds),
      netEarnings: num(money.net_earnings),
      available: nullableMoney(money.available),
      pending: nullableMoney(money.pending),
      label: str(money.label),
      payoutsReady: money.payouts_ready == null ? null : Boolean(money.payouts_ready),
    },
  }
}

export function mapCheckinPopulations(raw: unknown): CheckinPopulations {
  const r = obj(raw)
  const blocked = obj(r.blocked)
  return {
    booked: num(r.booked),
    expected: num(r.expected),
    checkedIn: num(r.checked_in),
    waiting: num(r.waiting),
    noShow: num(r.no_show),
    invalid: num(r.invalid),
    blocked: { paymentPending: num(blocked.payment_pending), documents: num(blocked.documents) },
    arrivalPct: num(r.arrival_pct),
  }
}

export function mapCheckinSummary(raw: unknown): CheckinSummary {
  const r = obj(raw)
  const chronology = CHRONOLOGIES.includes(r.chronology as OrgChronology) ? (r.chronology as OrgChronology) : "undated"
  return {
    ...mapCheckinPopulations(r),
    eventId: str(r.event_id),
    title: str(r.title),
    date: str(r.date),
    chronology,
    generatedAt: str(r.generated_at),
    rows: (Array.isArray(r.rows) ? r.rows : []).map((row) => {
      const x = obj(row)
      return {
        applicationId: str(x.application_id),
        vendorName: str(x.vendor_name),
        category: str(x.category),
        booth: str(x.booth),
        reason: (str(x.reason) || "not_found") as CheckinReason,
        eligible: Boolean(x.eligible),
        checkedIn: Boolean(x.checked_in),
        checkedInAt: str(x.checked_in_at),
        opsStatus: str(x.ops_status),
        actions: (Array.isArray(x.actions) ? x.actions : []).map(str),
      }
    }),
  }
}

export function mapOperationsSummary(raw: unknown): OperationsSummary | null {
  const r = obj(raw)
  const event = mapEventCard(r.event)
  if (!event) return null
  const lifecycle = obj(r.lifecycle)
  const booths = obj(r.booths)
  const packet = obj(r.packet)
  const logistics = obj(r.logistics)
  const incidents = obj(r.incidents)
  const readiness = obj(r.readiness)
  const can = obj(r.can)
  const mode: OpsMode = r.mode === "event_day" || r.mode === "post_event" ? r.mode : "pre_event"
  return {
    event,
    mode,
    myRole: str(r.my_role),
    can: Object.fromEntries(Object.entries(can).map(([k, v]) => [k, Boolean(v)])),
    lifecycle: { stage: str(lifecycle.stage), stageLabel: str(lifecycle.stage_label), progress: num(lifecycle.progress) },
    checkin: mapCheckinPopulations(r.checkin),
    booths: {
      total: num(booths.total),
      assigned: num(booths.assigned),
      open: num(booths.open),
      confirmed: num(booths.confirmed),
      unplaced: num(booths.unplaced),
    },
    packet: {
      prepared: pair(packet.prepared),
      published: str(packet.published),
      stage: str(packet.stage) || "draft",
      accessible: pair(packet.accessible),
      viewed: pair(packet.viewed),
      acknowledged: pair(packet.acknowledged),
    },
    readiness: {
      percent: num(readiness.percent),
      readyCount: num(readiness.ready_count),
      atRiskCount: num(readiness.at_risk_count),
      vendorCount: num(readiness.vendor_count),
      vendors: (Array.isArray(readiness.vendors) ? readiness.vendors : []).map((v) => {
        const x = obj(v)
        return {
          applicationId: str(x.application_id),
          businessName: str(x.business_name),
          percent: num(x.percent),
          tier: str(x.tier),
          tierLabel: str(x.tier_label),
          statusLabel: str(x.status_label),
          blocker: str(x.blocker),
        }
      }),
    },
    logistics: { loadinWindows: num(logistics.loadin_windows), parkingAreas: num(logistics.parking_areas) },
    incidents: { open: num(incidents.open), total: num(incidents.total) },
    staff: (Array.isArray(r.staff) ? r.staff : []).map((s) => {
      const x = obj(s)
      return { userId: str(x.user_id), name: str(x.name), role: str(x.role), roleLabel: str(x.role_label) }
    }),
    tasks: (Array.isArray(r.tasks) ? r.tasks : []).map((t) => {
      const x = obj(t)
      return {
        key: str(x.key),
        label: str(x.label),
        detail: str(x.detail),
        done: Boolean(x.done),
        canAct: Boolean(x.can_act),
        owner: x.owner === "vendor" ? "vendor" : "organizer",
      }
    }),
  }
}

const INVITE_STATUSES: readonly InviteStatus[] = ["sent", "opened", "accepted", "declined", "expired", "cancelled"]

export function mapInvite(raw: unknown): OrgInvite {
  const x = obj(raw)
  return {
    id: str(x.id),
    vendorUserId: str(x.vendor_user_id),
    vendorProfileId: idOrNull(x.vendor_profile_id),
    businessName: str(x.business_name),
    logoUrl: str(x.logo_url),
    status: INVITE_STATUSES.find((s) => s === x.status) ?? "sent",
    eventId: idOrNull(x.event_id),
    eventTitle: str(x.event_title),
    eventDate: str(x.event_date),
    opportunityTitle: str(x.opportunity_title),
    message: str(x.message),
    sentAt: str(x.sent_at),
    expiresAt: str(x.expires_at),
    remindedAt: str(x.reminded_at),
    reminderCount: num(x.reminder_count),
    respondedAt: str(x.responded_at),
    canRemind: Boolean(x.can_remind),
    canCancel: Boolean(x.can_cancel),
    applicationId: idOrNull(x.application_id),
    applicationStatus: str(x.application_status),
  }
}

export function mapInviteOverview(raw: unknown): InviteOverview {
  const r = obj(raw)
  const c = obj(r.counts)
  return {
    items: (Array.isArray(r.items) ? r.items : []).map(mapInvite),
    counts: {
      sent: num(c.sent),
      awaiting: num(c.awaiting),
      accepted: num(c.accepted),
      declined: num(c.declined),
      expired: num(c.expired),
      cancelled: num(c.cancelled),
      applied: num(c.applied),
    },
  }
}

export function mapPayoutPreview(raw: unknown): PayoutPreview {
  const r = obj(raw)
  return {
    type: r.type === "instant" ? "instant" : "standard",
    amount: num(r.amount),
    available: r.available == null ? null : num(r.available),
    feeAmount: num(r.fee_amount),
    feeRate: num(r.fee_rate),
    providerFeeNote: str(r.provider_fee_note),
    estimatedNet: num(r.estimated_net),
    netIsEstimate: Boolean(r.net_is_estimate),
    method: r.method === "connect" ? "connect" : "lnkup",
    destinationSummary: str(r.destination_summary),
    timingNote: str(r.timing_note),
    canSubmit: Boolean(r.can_submit),
    problem: str(r.problem),
  }
}

export function mapEventScore(raw: unknown): EventScore {
  const r = obj(raw)
  return {
    eventId: str(r.event_id),
    score: num(r.score),
    label: str(r.label),
    labelKey: str(r.label_key),
    isNew: Boolean(r.is_new),
    factors: (Array.isArray(r.factors) ? r.factors : []).map((f) => {
      const x = obj(f)
      const action = x.action ? obj(x.action) : null
      return {
        key: str(x.key),
        label: str(x.label),
        explanation: str(x.explanation),
        value: num(x.value),
        weightPct: x.weight_pct == null ? null : num(x.weight_pct),
        estimated: Boolean(x.estimated),
        action: action ? { label: str(action.label), target: str(action.target) } : null,
      }
    }),
  }
}

export function mapApplicationReview(raw: unknown): ApplicationReview {
  const r = obj(raw)
  const v = obj(r.vendor)
  const e = obj(r.event)
  const d = obj(r.documents)
  const a = obj(r.actions)
  return {
    applicationId: str(r.application_id),
    status: str(r.status),
    submittedAt: str(r.submitted_at),
    message: str(r.message),
    setupNotes: str(r.setup_notes),
    vendor: {
      profileId: idOrNull(v.profile_id),
      businessName: str(v.business_name) || "Vendor",
      logoUrl: str(v.logo_url),
      category: str(v.category),
      place: str(v.place),
      verified: Boolean(v.verified),
      completedEvents: num(v.completed_events),
      rating: num(v.rating),
      reviewCount: num(v.review_count),
      reputation: str(v.reputation),
    },
    event: {
      id: str(e.id),
      title: str(e.title),
      date: str(e.date),
      place: str(e.place),
      boothType: str(e.booth_type),
      open: num(e.open),
      unlimited: Boolean(e.unlimited),
    },
    fee: num(r.fee),
    paymentDueAt: str(r.payment_due_at),
    answers: (Array.isArray(r.answers) ? r.answers : []).map((x) => ({ question: str(obj(x).question), answer: str(obj(x).answer) })),
    documents: {
      code: str(d.code),
      items: (Array.isArray(d.items) ? d.items : []).map((x) => ({ slug: str(obj(x).slug), label: str(obj(x).label), status: str(obj(x).status) })),
    },
    actions: {
      approve: Boolean(a.approve),
      waitlist: Boolean(a.waitlist),
      reject: Boolean(a.reject),
      requestInfo: Boolean(a.request_info),
    },
    approveNote: str(r.approve_note),
  }
}

function isMissingRoute(error: unknown): boolean {
  return error instanceof WpFetchError && (error.status === 404 || error.code === "rest_no_route")
}

async function optional<T>(load: () => Promise<T>): Promise<T | null> {
  if (!isLiveApiEnabled()) return null
  try {
    return await load()
  } catch (error) {
    if (isMissingRoute(error)) return null
    throw error
  }
}

export const ORG_MOBILE_CACHE_PREFIX = "org-mobile:"

export function fetchHomeSummary(auth: WpAuth, force = false): Promise<OrgHomeSummary | null> {
  return cachedRequest(
    `${ORG_MOBILE_CACHE_PREFIX}home:${auth.username}`,
    () => optional(async () => mapHomeSummary(await wpFetchJson<unknown>("/organizer/home-summary", { auth }))),
    { ttlMs: 30_000, force },
  )
}

export type OrgEventFilters = {
  q?: string
  chronology?: "all" | "upcoming" | "in_progress" | "completed"
  publication?: "all" | "live" | "draft"
  applications?: "open" | "closed"
  tour?: string
}

export type OrgEventsResult = {
  events: OrgEventCard[]
  counts: { all: number; upcoming: number; inProgress: number; completed: number; draft: number; live: number }
}

export function fetchOrganizerEvents(auth: WpAuth, filters: OrgEventFilters = {}, force = false): Promise<OrgEventsResult | null> {
  const query: Record<string, string> = {}
  if (filters.q?.trim()) query.q = filters.q.trim()
  if (filters.chronology && filters.chronology !== "all") query.chronology = filters.chronology
  if (filters.publication && filters.publication !== "all") query.publication = filters.publication
  if (filters.applications) query.applications = filters.applications
  if (filters.tour) query.tour = filters.tour
  const key = `${ORG_MOBILE_CACHE_PREFIX}events:${auth.username}:${JSON.stringify(query)}`
  return cachedRequest(
    key,
    () =>
      optional(async () => {
        const res = obj(await wpFetchJson<unknown>("/organizer/mobile/events", { auth, query }))
        const c = obj(res.counts)
        return {
          events: cards(res.events),
          counts: {
            all: num(c.all),
            upcoming: num(c.upcoming),
            inProgress: num(c.in_progress),
            completed: num(c.completed),
            draft: num(c.draft),
            live: num(c.live),
          },
        }
      }),
    { ttlMs: 20_000, force },
  )
}

export function fetchOperationsSummary(eventId: string, auth: WpAuth, force = false): Promise<OperationsSummary | null> {
  return cachedRequest(
    `${ORG_MOBILE_CACHE_PREFIX}ops:${eventId}`,
    () => optional(async () => mapOperationsSummary(await wpFetchJson<unknown>(`/organizer/events/${eventId}/operations-summary`, { auth }))),
    { ttlMs: 15_000, force },
  )
}

export function fetchCheckinSummary(eventId: string, auth: WpAuth, force = false): Promise<CheckinSummary | null> {
  return cachedRequest(
    `${ORG_MOBILE_CACHE_PREFIX}checkin:${eventId}`,
    () => optional(async () => mapCheckinSummary(await wpFetchJson<unknown>(`/operations/events/${eventId}/checkin-summary`, { auth }))),
    { ttlMs: 10_000, force },
  )
}

export function fetchInviteOverview(auth: WpAuth, force = false): Promise<InviteOverview | null> {
  return cachedRequest(
    `${ORG_MOBILE_CACHE_PREFIX}invites:${auth.username}`,
    () => optional(async () => mapInviteOverview(await wpFetchJson<unknown>("/organizer/mobile/invites", { auth }))),
    { ttlMs: 20_000, force },
  )
}

export async function remindInvite(inviteId: string, auth: WpAuth): Promise<OrgInvite> {
  const res = obj(await wpFetchJson<unknown>(`/crm/invites/${inviteId}/remind`, { auth, method: "POST" }))
  invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}invites:`)
  return mapInvite(res.invite)
}

export async function cancelInvite(inviteId: string, auth: WpAuth): Promise<OrgInvite> {
  const res = obj(await wpFetchJson<unknown>(`/crm/invites/${inviteId}/cancel`, { auth, method: "POST" }))
  invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}invites:`)
  invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}home:`)
  return mapInvite(res.invite)
}

/** Read-only fee and eligibility check; never creates a payout. */
export async function fetchPayoutPreview(
  amount: number,
  type: "standard" | "instant",
  auth: WpAuth,
): Promise<PayoutPreview | null> {
  return optional(async () =>
    mapPayoutPreview(
      await wpFetchJson<unknown>("/financial/organizer-payouts/preview", { auth, query: { amount: amount.toFixed(2), type } }),
    ),
  )
}

export function fetchEventScore(eventId: string, auth: WpAuth): Promise<EventScore | null> {
  return cachedRequest(
    `${ORG_MOBILE_CACHE_PREFIX}score:${eventId}`,
    () => optional(async () => mapEventScore(await wpFetchJson<unknown>(`/organizer/events/${eventId}/score`, { auth }))),
    { ttlMs: 60_000 },
  )
}

/** Always fresh: decisions must be made on the current server state. */
export async function fetchApplicationReview(applicationId: string, auth: WpAuth): Promise<ApplicationReview | null> {
  return optional(async () => mapApplicationReview(await wpFetchJson<unknown>(`/organizer/applications/${applicationId}/review`, { auth })))
}

/** Drop organizer read models after a write that changes counts (review, check-in, publish). */
export function invalidateOrganizerMobile(eventId?: string): void {
  invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}home:`)
  invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}events:`)
  if (eventId) {
    invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}ops:${eventId}`)
    invalidateCache(`${ORG_MOBILE_CACHE_PREFIX}checkin:${eventId}`)
  }
}
