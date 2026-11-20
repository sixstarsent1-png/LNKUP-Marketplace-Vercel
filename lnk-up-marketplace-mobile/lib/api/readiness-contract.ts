export type ReadinessTier = "ready" | "on_track" | "at_risk" | "unknown"
export type ReadinessRiskBucket = "green" | "amber" | "red"
export type DocumentUrgency = "none" | "expiring_soon" | "expired"

export function mapReadinessTier(params: {
  profileCompletion?: number
  documentsCompletion?: number
  requiredBlockers: number
}): ReadinessTier {
  const profile = params.profileCompletion ?? 0
  const docs = params.documentsCompletion ?? 100
  if (params.requiredBlockers > 0) return "at_risk"
  if (profile >= 90 && docs >= 90) return "ready"
  if (profile >= 60) return "on_track"
  return "at_risk"
}

export function mapRiskBucket(tier: ReadinessTier): ReadinessRiskBucket {
  if (tier === "ready") return "green"
  if (tier === "on_track") return "amber"
  return "red"
}

export function mapDocumentUrgencyFromStatus(status?: string): DocumentUrgency {
  const normalized = String(status ?? "").toLowerCase()
  if (normalized === "expired" || normalized === "rejected") return "expired"
  if (normalized === "expiring" || normalized === "expiring_soon") return "expiring_soon"
  return "none"
}

export function mapDocumentUrgencyFromDate(
  expiresAt?: string,
  warningDays = 30,
): DocumentUrgency {
  if (!expiresAt) return "none"
  const date = Date.parse(expiresAt)
  if (Number.isNaN(date)) return "none"
  const diffDays = (date - Date.now()) / (1000 * 60 * 60 * 24)
  if (diffDays < 0) return "expired"
  if (diffDays <= warningDays) return "expiring_soon"
  return "none"
}

