import type { WpProfileStrength, WpVerificationVendor } from "@/lib/api/types"
import {
  mapDocumentUrgencyFromDate,
  mapDocumentUrgencyFromStatus,
  mapReadinessTier,
  mapRiskBucket,
  type DocumentUrgency,
  type ReadinessRiskBucket,
  type ReadinessTier,
} from "@/lib/api/readiness-contract"

export type VendorReadinessStatus = "new" | "incomplete" | "ready" | "verified" | "unknown"

export type VendorReadinessMissingItem = {
  id: string
  label: string
  description?: string
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
  raw?: unknown
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
  if (lower.includes("document") || lower.includes("insurance") || lower.includes("permit") || lower.includes("license") || lower.includes("w9") || lower.includes("w-9")) {
    return "/vendor/documents"
  }
  if (lower.includes("photo")) return "/vendor/profile"
  return "/vendor/profile"
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
          actionHref: "/vendor/documents",
          actionLabel: "Upload",
          severity: "required",
          type: "document",
          documentUrgency: urgency,
        })
      }
    }
  }

  for (const action of strength?.recommended_actions ?? []) {
    if (action.label) {
      add(action.label, "recommended")
    }
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
      href: raw.href || "/vendor/profile",
    }
  }
  if (typeof raw === "string" && raw) {
    return { label: raw, href: hrefForMissing(raw) }
  }
  const first = missingItems?.[0]
  if (first) {
    return {
      label: first.actionLabel ? `${first.actionLabel}: ${first.label}` : first.label,
      href: first.actionHref || "/vendor/profile",
    }
  }
  return undefined
}

export function mapVendorReadiness(
  verification: WpVerificationVendor,
  strength?: WpProfileStrength,
): VendorReadiness {
  const profileCompletion = strength?.score ?? verification.percent_complete ?? 0
  const docsCompletion = documentsCompletion(verification)
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
    documentsCompletion: docsCompletion,
    requiredBlockers,
  })
  const riskBucket = mapRiskBucket(readinessTier)

  return {
    status,
    profileCompletion,
    documentsCompletion: docsCompletion,
    trustLevel: verification.label || verification.level,
    verifiedBadges: verification.badges,
    missingItems,
    nextAction: mapNextAction(verification, missingItems),
    headline: headlineForStatus(status, missingDocs),
    readinessTier,
    riskBucket,
    blockers: missingItems.filter((item) => item.severity === "required"),
    raw: { verification, strength },
  }
}

export const mockVendorReadiness: VendorReadiness = {
  status: "incomplete",
  profileCompletion: 78,
  documentsCompletion: 60,
  trustLevel: "Profile in progress",
  verifiedBadges: [],
  missingItems: [
    {
      id: "coi",
      label: "Upload Certificate of Insurance",
      actionHref: "/vendor/documents",
      actionLabel: "Upload",
      severity: "required",
    },
    {
      id: "photos",
      label: "Add at least 3 photos",
      actionHref: "/vendor/profile",
      actionLabel: "Add",
      severity: "recommended",
    },
  ],
  nextAction: {
    label: "Upload insurance",
    href: "/vendor/documents",
  },
  headline: "Upload required documents before applying to some events.",
  readinessTier: "at_risk",
  riskBucket: "red",
  blockers: [
    {
      id: "coi",
      label: "Upload Certificate of Insurance",
      actionHref: "/vendor/documents",
      actionLabel: "Upload",
      severity: "required",
      type: "document",
      documentUrgency: "expiring_soon",
    },
  ],
}
