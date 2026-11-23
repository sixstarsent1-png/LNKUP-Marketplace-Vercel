import Constants from "expo-constants"

function readExtra(key: string): string | undefined {
  const extra = Constants.expoConfig?.extra as Record<string, string> | undefined
  return extra?.[key]
}

export function getWpBaseUrl(): string {
  const fromEnv = process.env.EXPO_PUBLIC_WORDPRESS_API_URL?.trim()
  const fromExtra = readExtra("wordpressApiUrl")?.trim()
  const base = fromEnv || fromExtra || "https://thelnkup.com"
  return base.replace(/\/$/, "")
}

export function getWpJsonBase(): string {
  return `${getWpBaseUrl()}/wp-json/lnkup/v1`
}

export function getVendorPaymentsUrl(opts?: { finTab?: string; disputePayment?: string }): string {
  const params = new URLSearchParams({ view: "payments" })
  if (opts?.finTab) params.set("fin_tab", opts.finTab)
  if (opts?.disputePayment) params.set("dispute_payment", opts.disputePayment)
  return `${getWpBaseUrl()}/vendor-dashboard/?${params.toString()}`
}

export function getOrganizerDashboardUrl(): string {
  return `${getWpBaseUrl()}/organizer-dashboard/`
}

/** Web Partner dashboard (City Connector, affiliate, strategic partner), optionally on one view. */
export function getPartnerDashboardUrl(view?: string): string {
  const base = `${getWpBaseUrl()}/connector-dashboard/`
  return view ? `${base}?view=${encodeURIComponent(view)}` : base
}

export function getOrganizerCreateUrl(): string {
  return `${getOrganizerDashboardUrl()}#create`
}

export function getOrganizerFinancialUrl(): string {
  return `${getWpBaseUrl()}/organizer-dashboard/?view=financial`
}

export function getVendorProfileUrl(): string {
  return `${getWpBaseUrl()}/vendor-profile/`
}

/** Public vendor storefront URL for sharing. */
export function getVendorPublicProfileUrl(slug: string): string {
  const clean = slug.trim().replace(/^\/+|\/+$/g, "")
  if (!clean) return `${getWpBaseUrl()}/vendors/`
  return `${getWpBaseUrl()}/vendors/?vendor=${encodeURIComponent(clean)}`
}

/** Public organizer storefront URL for sharing. */
export function getOrganizerPublicProfileUrl(slug: string): string {
  const clean = slug.trim().replace(/^\/+|\/+$/g, "")
  if (!clean) return `${getWpBaseUrl()}/organizers/`
  return `${getWpBaseUrl()}/organizers/?organizer=${encodeURIComponent(clean)}`
}

/** Public opportunity apply URL for organizers sharing an event. */
export function getOpportunityApplyUrl(opportunityId: string | number): string {
  const id = String(opportunityId).trim()
  if (!id) return `${getWpBaseUrl()}/opportunities/`
  return `${getWpBaseUrl()}/opportunities/?apply=${encodeURIComponent(id)}`
}

export function getWpRegistrationUrl(options?: {
  role?: "vendor" | "organizer"
  redirect?: string
}): string {
  const role = options?.role === "organizer" ? "organizer" : "vendor"
  const params = new URLSearchParams()
  params.set("role", role)
  if (options?.redirect) {
    params.set("redirect", options.redirect)
  }
  return `${getWpBaseUrl()}/sign-up/?${params.toString()}`
}

export function getVendorDashboardUrl(view?: string): string {
  const base = `${getWpBaseUrl()}/vendor-dashboard/`
  if (!view) return base
  return `${base}?view=${encodeURIComponent(view)}`
}

export function getPrivacyPolicyUrl(): string {
  return (readExtra("privacyPolicyUrl") || `${getWpBaseUrl()}/privacy`).replace(/\/$/, "")
}

export function getTermsOfServiceUrl(): string {
  return (readExtra("termsOfServiceUrl") || `${getWpBaseUrl()}/terms`).replace(/\/$/, "")
}

/** JSON request timeout. 15s in the app; local test servers can raise it via EXPO_PUBLIC_LNKUP_API_TIMEOUT_MS. */
export function getApiTimeoutMs(): number {
  const raw = Number(process.env.EXPO_PUBLIC_LNKUP_API_TIMEOUT_MS)
  return Number.isFinite(raw) && raw >= 5_000 ? raw : 15_000
}

export function isLiveApiEnabled(): boolean {
  const fromEnv = process.env.EXPO_PUBLIC_LNKUP_LIVE_API?.trim().toLowerCase()
  const fromExtra = readExtra("liveApi")?.trim().toLowerCase()
  const value = fromEnv || fromExtra || "false"
  return value === "true" || value === "1"
}
