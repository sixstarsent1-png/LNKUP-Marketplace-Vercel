import {
  getOrganizerCreateUrl,
  getOrganizerDashboardUrl,
  getOrganizerFinancialUrl,
  isLiveApiEnabled,
} from "@/lib/api/config"
import { withFixtureFallback } from "@/lib/api/fixture-honesty"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import {
  fetchOrganizerMoney,
  type OrganizerFinancialSnapshot,
} from "@/lib/api/organizer-financial"
import {
  mapOrganizerDiagnostics,
  mapOrganizerExperiments,
  mapOrganizerRecommendations,
  prioritizeOrganizerRecommendations,
  type OrganizerFunnelDiagnostic,
  type OrganizerExperimentSnapshot,
  type OrganizerRecommendation,
} from "@/lib/api/organizer-intelligence"
import { MOCK_ORGANIZER_HOME } from "@/lib/data/fixtures"

export type OrganizerChecklistItem = {
  id: string
  key: string
  label: string
  complete: boolean
  description?: string
  ctaLabel?: string
  ctaUrl?: string
  eventId?: string
}

export type OrganizerHomeSummary = {
  stats: {
    events: number
    openOpportunities: number
    vendorRequests: number
    approvedVendors: number
    paymentRequired: number
    paidVendors: number
  }
  attentionItems: Array<{
    id: string
    label: string
    description?: string
    severity?: "info" | "warning" | "urgent"
  }>
  launchChecklist: OrganizerChecklistItem[]
  financial?: OrganizerFinancialSnapshot
  intelligence: {
    diagnostics: OrganizerFunnelDiagnostic[]
    experiments: OrganizerExperimentSnapshot[]
    recommendations: OrganizerRecommendation[]
  }
  wordpressUrl: string
}

function checklistEventId(item: Record<string, unknown>): string | undefined {
  const raw = item.event_id ?? item.eventId
  const id = Number(raw)
  if (Number.isFinite(id) && id > 0) return String(id)
  const url = typeof item.cta_url === "string" ? item.cta_url : typeof item.ctaUrl === "string" ? item.ctaUrl : ""
  const match = url.match(/[?&]event=(\d+)/)
  return match?.[1]
}

export function mapOrganizerHomeSummary(
  intel: Record<string, unknown>,
  widgets: Record<string, unknown>,
  checklistRaw: unknown,
  financial?: OrganizerFinancialSnapshot,
): OrganizerHomeSummary {
  const pending = Number(widgets.pending_applications ?? 0) || 0
  const approved = Number(intel.approved_vendors ?? 0) || 0
  const paid = Number(intel.paid_vendors ?? 0) || 0
  const paymentRequired = Math.max(0, approved - paid)

  const checklistSource = Array.isArray(checklistRaw)
    ? checklistRaw
    : ((checklistRaw as { items?: unknown[] })?.items ?? [])

  const launchChecklist = (checklistSource as Record<string, unknown>[]).map((item, index) => {
    const key = String(item.key ?? item.id ?? `check-${index}`)
    return {
      id: String(item.id ?? key),
      key,
      label: String(item.label ?? item.title ?? "Task"),
      description: typeof item.description === "string" ? item.description : undefined,
      complete: Boolean(
        item.complete ??
          item.done ??
          (item.status === "complete" || item.status === "done"),
      ),
      ctaLabel:
        typeof item.cta_label === "string"
          ? item.cta_label
          : typeof item.ctaLabel === "string"
            ? item.ctaLabel
            : undefined,
      ctaUrl:
        typeof item.cta_url === "string"
          ? item.cta_url
          : typeof item.ctaUrl === "string"
            ? item.ctaUrl
            : undefined,
      eventId: checklistEventId(item),
    }
  })

  const attentionItems: OrganizerHomeSummary["attentionItems"] = []
  if (pending > 0) {
    attentionItems.push({
      id: "pending-requests",
      label: `${pending} vendor request${pending === 1 ? "" : "s"} awaiting review`,
      description: "Review applications to keep your vendor row on track.",
      severity: "warning",
    })
  }
  if (paymentRequired > 0) {
    attentionItems.push({
      id: "payment-required",
      label: `${paymentRequired} approved vendor${paymentRequired === 1 ? "" : "s"} still need to pay`,
      severity: "info",
    })
  }

  const diagnostics = mapOrganizerDiagnostics(intel.funnel_diagnostics)
  const experiments = mapOrganizerExperiments(intel.experiment_snapshots)
  const recommendations = prioritizeOrganizerRecommendations(
    mapOrganizerRecommendations(intel.recommendations),
  )

  return {
    stats: {
      events: Number(intel.total_events ?? 0) || 0,
      openOpportunities: Number(intel.active_opportunities ?? 0) || 0,
      /** Pending applications awaiting review (not lifetime total). */
      vendorRequests: pending,
      approvedVendors: approved,
      paymentRequired,
      paidVendors: paid,
    },
    attentionItems,
    launchChecklist,
    financial,
    intelligence: {
      diagnostics,
      experiments,
      recommendations,
    },
    wordpressUrl: getOrganizerDashboardUrl(),
  }
}

/**
 * Replace review/payment counts with live application statuses. The intelligence summary is
 * cached server-side for 15 minutes and its "payment due" was approved − paid arithmetic, which
 * disagreed with Money right after a review.
 */
export function applyLiveRequestCounts(
  summary: OrganizerHomeSummary,
  requests: Array<{ status: string }>,
): OrganizerHomeSummary {
  const count = (statuses: string[]) => requests.filter((r) => statuses.includes(r.status)).length
  const pending = count(["submitted", "under_review"])
  const paymentRequired = count(["approved", "payment_required"])
  const paid = count(["paid", "completed"])
  const attentionItems: OrganizerHomeSummary["attentionItems"] = summary.attentionItems.filter(
    (item) => item.id !== "pending-requests" && item.id !== "payment-required",
  )
  if (pending > 0) {
    attentionItems.unshift({
      id: "pending-requests",
      label: `${pending} vendor request${pending === 1 ? "" : "s"} awaiting review`,
      description: "Review applications to keep your vendor row on track.",
      severity: "warning",
    })
  }
  if (paymentRequired > 0) {
    attentionItems.push({
      id: "payment-required",
      label: `${paymentRequired} approved vendor${paymentRequired === 1 ? "" : "s"} still need to pay`,
      severity: "info",
    })
  }
  return {
    ...summary,
    stats: {
      ...summary.stats,
      vendorRequests: pending,
      paymentRequired,
      approvedVendors: paymentRequired + paid,
      paidVendors: paid,
    },
    attentionItems,
  }
}

function buildMockOrganizerHome(): OrganizerHomeSummary {
  return {
    ...MOCK_ORGANIZER_HOME,
    launchChecklist: MOCK_ORGANIZER_HOME.launchChecklist.map((i) => ({ ...i })),
    attentionItems: MOCK_ORGANIZER_HOME.attentionItems.map((i) => ({ ...i })),
    intelligence: {
      diagnostics: MOCK_ORGANIZER_HOME.intelligence.diagnostics.map((item) => ({ ...item })),
      experiments: MOCK_ORGANIZER_HOME.intelligence.experiments.map((item) => ({
        ...item,
        segment: { ...item.segment },
        guardrails: item.guardrails.map((guardrail) => ({ ...guardrail })),
      })),
      recommendations: MOCK_ORGANIZER_HOME.intelligence.recommendations.map((item) => ({
        ...item,
        segment: { ...item.segment },
        decision: { ...item.decision },
      })),
    },
    financial: MOCK_ORGANIZER_HOME.financial
      ? { ...MOCK_ORGANIZER_HOME.financial }
      : undefined,
  }
}

const HOME_CACHE_PREFIX = "organizer.home:"

/** Dropped after publishing, profile saves and request reviews so the checklist and counts move. */
export function invalidateOrganizerHome(): void {
  invalidateCache(HOME_CACHE_PREFIX)
}

/** Shared by Home and the notification composer; concurrent callers reuse one request. */
export async function fetchOrganizerHome(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<OrganizerHomeSummary> {
  if (!isLiveApiEnabled() || !auth) return loadOrganizerHome(auth)
  return cachedRequest(`${HOME_CACHE_PREFIX}${auth.username}`, () => loadOrganizerHome(auth, options.force), options)
}

async function loadOrganizerHome(auth?: WpAuth, force = false): Promise<OrganizerHomeSummary> {
  return withFixtureFallback(
    "organizer.home",
    async () => {
      if (!auth) {
        throw new Error("Sign in as an organizer to continue.")
      }

      const [intelSettled, widgetsSettled, checklist, moneySettled] = await Promise.all([
        wpFetchJson<Record<string, unknown>>("/intelligence/organizer/summary", { auth }).then(
          (value) => ({ ok: true as const, value }),
          (reason: unknown) => ({ ok: false as const, reason }),
        ),
        wpFetchJson<Record<string, unknown>>("/crm/dashboard/widgets", { auth }).then(
          (value) => ({ ok: true as const, value }),
          (reason: unknown) => ({ ok: false as const, reason }),
        ),
        wpFetchJson<unknown>("/crm/launch-checklist", { auth }).catch(() => []),
        fetchOrganizerMoney(auth, { force }).then(
          (value) => ({ ok: true as const, value }),
          () => ({ ok: false as const, value: null }),
        ),
      ])

      if (!intelSettled.ok && !widgetsSettled.ok) {
        throw intelSettled.reason instanceof Error
          ? intelSettled.reason
          : new Error("Could not load organizer home.")
      }

      const intel = intelSettled.ok ? intelSettled.value : {}
      const widgets = widgetsSettled.ok ? widgetsSettled.value : {}
      const financial = moneySettled.ok ? moneySettled.value.summary : undefined

      return mapOrganizerHomeSummary(intel, widgets, checklist, financial)
    },
    () => buildMockOrganizerHome(),
  )
}

export { getOrganizerCreateUrl, getOrganizerFinancialUrl }
