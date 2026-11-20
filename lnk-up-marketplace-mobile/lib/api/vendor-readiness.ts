import { isLiveApiEnabled } from "@/lib/api/config"
import { cachedRequest, invalidateCache, type CachedRequestOptions } from "@/lib/api/request-cache"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"
import {
  mapDocumentUrgencyFromDate,
  mapDocumentUrgencyFromStatus,
  mapReadinessTier,
  mapRiskBucket,
  type DocumentUrgency,
  type ReadinessRiskBucket,
  type ReadinessTier,
} from "@/lib/api/readiness-contract"
import { MOCK_VENDOR_READINESS } from "@/lib/data/fixtures"

export type VendorReadinessStatus = "new" | "incomplete" | "ready" | "verified" | "unknown"

export type VendorReadinessMissingItem = {
  id: string
  label: string
  actionHref?: string
  actionLabel?: string
  severity?: "required" | "recommended"
  type?: "document" | "profile" | "payment" | "operations" | "other"
  documentUrgency?: DocumentUrgency
}

export type VendorReadiness = {
  status: VendorReadinessStatus
  profileCompletion: number
  documentsCompletion?: number
  trustLevel?: string
  verifiedBadges?: string[]
  missingItems: VendorReadinessMissingItem[]
  nextAction?: {
    label: string
    href: string
  }
  headline: string
  readinessTier: ReadinessTier
  riskBucket: ReadinessRiskBucket
  blockers: VendorReadinessMissingItem[]
}

type WpVerificationVendor = {
  level?: string
  label?: string
  percent_complete?: number
  badges?: string[]
  missing_items?: string[] | Array<{ label?: string; title?: string }>
  next_action?: string | { label?: string; href?: string }
  required_documents?: Array<{ type?: string; label?: string; status?: string }>
}

type WpProfileStrength = {
  score?: number
  missing_items?: string[]
  recommended_actions?: Array<{ label?: string }>
}

function itemLabel(item: string | { label?: string; title?: string }): string {
  if (typeof item === "string") return item
  return item.label || item.title || ""
}

function mapStatus(level?: string, percent?: number): VendorReadinessStatus {
  switch (level) {
    case "top_rated_vendor":
    case "lnkup_verified":
      return "verified"
    case "documents_ready":
      return "ready"
    case "profile_complete":
      return "incomplete"
    case "basic_vendor":
      return (percent ?? 0) < 25 ? "new" : "incomplete"
    default:
      return "unknown"
  }
}

function headlineForStatus(status: VendorReadinessStatus, missingDocs: boolean): string {
  if (status === "verified") return "You're verified and ready to apply."
  if (status === "ready") return "You're ready to apply."
  if (missingDocs) return "Upload required documents before applying to some events."
  if (status === "new") return "Complete your profile so organizers can review you faster."
  if (status === "incomplete") return "Organizers can approve you faster when your profile is complete."
  return "One profile. More event opportunities."
}

function hrefForMissing(label: string): string {
  const lower = label.toLowerCase()
  if (
    lower.includes("document") ||
    lower.includes("insurance") ||
    lower.includes("permit") ||
    lower.includes("license") ||
    lower.includes("w9") ||
    lower.includes("w-9")
  ) {
    return "/(vendor)/documents"
  }
  return "/(vendor)/profile"
}

function mapMissingItems(
  verification?: WpVerificationVendor,
  strength?: WpProfileStrength,
): VendorReadinessMissingItem[] {
  const items: VendorReadinessMissingItem[] = []
  const seen = new Set<string>()

  const add = (label: string, severity: "required" | "recommended" = "required") => {
    const key = label.toLowerCase()
    if (!label || seen.has(key)) return
    seen.add(key)
    items.push({
      id: `missing-${items.length}`,
      label,
      actionHref: hrefForMissing(label),
      actionLabel: severity === "required" ? "Complete" : "Add",
      severity,
      type: label.toLowerCase().includes("document") || label.toLowerCase().includes("insurance")
        ? "document"
        : "profile",
      documentUrgency: label.toLowerCase().includes("expir") ? "expiring_soon" : "none",
    })
  }

  for (const item of verification?.missing_items ?? []) {
    add(itemLabel(item), "required")
  }

  for (const doc of verification?.required_documents ?? []) {
    const urgencyByDate = mapDocumentUrgencyFromDate((doc as { expires_at?: string }).expires_at)
    const urgencyByStatus = mapDocumentUrgencyFromStatus(doc.status)
    const urgency = urgencyByDate === "none" ? urgencyByStatus : urgencyByDate
    if (doc.status && doc.status !== "approved" && doc.status !== "verified") {
      const label = doc.label || doc.type || "Required document"
      const key = label.toLowerCase()
      if (!seen.has(key)) {
        seen.add(key)
        items.push({
          id: `missing-${items.length}`,
          label,
          actionHref: "/(vendor)/documents",
          actionLabel: "Upload",
          severity: "required",
          type: "document",
          documentUrgency: urgency,
        })
      }
    }
  }

  for (const action of strength?.recommended_actions ?? []) {
    if (action.label) add(action.label, "recommended")
  }

  for (const missing of strength?.missing_items ?? []) {
    add(missing, "recommended")
  }

  return items.slice(0, 10)
}

function documentsCompletion(verification?: WpVerificationVendor): number | undefined {
  const docs = verification?.required_documents
  if (!docs?.length) return undefined
  const ready = docs.filter((d) => d.status === "approved" || d.status === "verified").length
  return Math.round((ready / docs.length) * 100)
}

function mapNextAction(
  verification?: WpVerificationVendor,
  missingItems?: VendorReadinessMissingItem[],
): VendorReadiness["nextAction"] | undefined {
  const raw = verification?.next_action
  if (raw && typeof raw === "object" && raw.label) {
    return {
      label: raw.label,
      href: raw.href?.includes("document") ? "/(vendor)/documents" : "/(vendor)/profile",
    }
  }
  if (typeof raw === "string" && raw) {
    return { label: raw, href: hrefForMissing(raw) }
  }
  const first = missingItems?.[0]
  if (first) {
    return {
      label: first.actionLabel ? `${first.actionLabel}: ${first.label}` : first.label,
      href: first.actionHref || "/(vendor)/profile",
    }
  }
  return undefined
}

function mapVendorReadiness(
  verification: WpVerificationVendor,
  strength?: WpProfileStrength,
): VendorReadiness {
  const profileCompletion = strength?.score ?? verification.percent_complete ?? 0
  const missingItems = mapMissingItems(verification, strength)
  const missingDocs = missingItems.some(
    (m) =>
      m.severity === "required" &&
      (m.label.toLowerCase().includes("document") ||
        m.label.toLowerCase().includes("insurance") ||
        m.label.toLowerCase().includes("permit")),
  )
  const status = mapStatus(verification.level, profileCompletion)
  const requiredBlockers = missingItems.filter((item) => item.severity === "required").length
  const readinessTier = mapReadinessTier({
    profileCompletion,
    documentsCompletion: documentsCompletion(verification),
    requiredBlockers,
  })
  const riskBucket = mapRiskBucket(readinessTier)

  return {
    status,
    profileCompletion,
    documentsCompletion: documentsCompletion(verification),
    trustLevel: verification.label || verification.level,
    verifiedBadges: verification.badges,
    missingItems,
    nextAction: mapNextAction(verification, missingItems),
    headline: headlineForStatus(status, missingDocs),
    readinessTier,
    riskBucket,
    blockers: missingItems.filter((item) => item.severity === "required"),
  }
}

const READINESS_CACHE_PREFIX = "vendor.readiness:"

/** Shared by Home, Profile and the notification composer; dropped after document or profile writes. */
export function invalidateVendorReadiness(): void {
  invalidateCache(READINESS_CACHE_PREFIX)
}

export async function fetchVendorReadiness(
  auth?: WpAuth,
  options: CachedRequestOptions = {},
): Promise<VendorReadiness> {
  if (!isLiveApiEnabled()) return loadVendorReadiness(auth)
  return cachedRequest(
    `${READINESS_CACHE_PREFIX}${auth?.username ?? "anon"}`,
    () => loadVendorReadiness(auth),
    { ttlMs: 30_000, ...options },
  )
}

async function loadVendorReadiness(auth?: WpAuth): Promise<VendorReadiness> {
  if (!isLiveApiEnabled()) {
    const mock = { ...MOCK_VENDOR_READINESS } as VendorReadiness
    return {
      ...mock,
      readinessTier: mock.readinessTier ?? "on_track",
      riskBucket: mock.riskBucket ?? "amber",
      blockers: mock.blockers ?? mock.missingItems.filter((item) => item.severity === "required"),
    }
  }

  const verification = await wpFetchJson<WpVerificationVendor>("/verification/vendor", { auth })

  let strength: WpProfileStrength | undefined
  try {
    strength = await wpFetchJson<WpProfileStrength>("/growth/profile-strength", { auth })
  } catch {
    strength = undefined
  }

  return mapVendorReadiness(verification, strength)
}
