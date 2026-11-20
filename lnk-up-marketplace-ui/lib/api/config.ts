export function isLiveApiEnabled(): boolean {
  return process.env.NEXT_PUBLIC_LNKUP_LIVE_API === "true"
}

export function getWpBaseUrl(): string {
  const url = process.env.WORDPRESS_API_URL?.trim()
  if (!url) {
    throw new Error("WORDPRESS_API_URL is not configured")
  }
  return url.replace(/\/$/, "")
}

export function getWpJsonBase(): string {
  return `${getWpBaseUrl()}/wp-json/lnkup/v1`
}

export function getPublicWpSiteUrl(): string {
  return process.env.NEXT_PUBLIC_WORDPRESS_SITE_URL?.trim().replace(/\/$/, "") || ""
}

/** Public vendor storefront for sharing. */
export function getVendorPublicProfileUrl(slug: string): string {
  const base = getPublicWpSiteUrl() || "https://thelnkup.com"
  const clean = slug.trim()
  if (!clean) return `${base}/vendors/`
  return `${base}/vendors/?vendor=${encodeURIComponent(clean)}`
}

/** Public opportunity apply URL for organizers sharing an event. */
export function getOpportunityApplyUrl(opportunityId: string | number): string {
  const base = getPublicWpSiteUrl() || "https://thelnkup.com"
  const id = String(opportunityId).trim()
  if (!id) return `${base}/opportunities/`
  return `${base}/opportunities/?apply=${encodeURIComponent(id)}`
}

/** Next.js app base URL for Stripe checkout return (no trailing slash). */
export function getAppBaseUrl(): string {
  const explicit = process.env.NEXT_PUBLIC_APP_URL?.trim().replace(/\/$/, "")
  if (explicit) return explicit
  const vercel = process.env.VERCEL_URL?.trim()
  if (vercel) return `https://${vercel.replace(/^https?:\/\//, "")}`
  return ""
}

/** Server-only: validated app base URL for payment return handoff. */
export function getPaymentReturnAppBaseUrl(): string {
  const base = getAppBaseUrl()
  if (!base) return ""
  if (!isAllowedAppBaseUrl(base)) return ""
  return base
}

export function isAllowedAppBaseUrl(url: string): boolean {
  try {
    const host = new URL(url).hostname.toLowerCase()
    const allowed = (process.env.LNKUP_APP_URL_ALLOWLIST ?? "")
      .split(",")
      .map((h) => h.trim().toLowerCase())
      .filter(Boolean)
    if (allowed.length === 0) {
      return (
        host === "localhost" ||
        host.endsWith(".vercel.app") ||
        host.endsWith("lnkup.com") ||
        host.endsWith("thelnkup.com")
      )
    }
    return allowed.some((pattern) => host === pattern || host.endsWith(`.${pattern}`))
  } catch {
    return false
  }
}

export function buildVendorPaymentReturnPath(
  applicationId: string,
  payment: "success" | "cancelled" | "processing",
): string {
  const params = new URLSearchParams({ applicationId, payment })
  return `/vendor/requests?${params.toString()}`
}

export function getVendorDashboardPaymentsUrl(opts?: { finTab?: string; disputePayment?: string }): string {
  const base = getPublicWpSiteUrl()
  if (!base) return ""
  const params = new URLSearchParams({ view: "payments" })
  if (opts?.finTab) params.set("fin_tab", opts.finTab)
  if (opts?.disputePayment) params.set("dispute_payment", opts.disputePayment)
  return `${base}/vendor-dashboard/?${params.toString()}`
}

export function getOrganizerDashboardUrl(): string {
  const base = getPublicWpSiteUrl()
  if (!base) return ""
  return `${base}/organizer-dashboard/`
}

export function getOrganizerFinancialUrl(): string {
  const base = getOrganizerDashboardUrl()
  if (!base) return ""
  return `${base}?view=financial`
}

export type WpRegistrationOptions = {
  role?: "vendor" | "organizer"
  redirect?: string
}

/**
 * WordPress branded signup URL (`/sign-up/`).
 * Pass a string for legacy redirect-only callers (defaults to vendor role).
 */
export function getWpRegistrationUrl(
  options?: string | WpRegistrationOptions,
): string {
  const base =
    getPublicWpSiteUrl() ||
    process.env.WORDPRESS_API_URL?.trim().replace(/\/$/, "") ||
    "https://thelnkup.com"

  const opts: WpRegistrationOptions =
    typeof options === "string" ? { redirect: options, role: "vendor" } : options ?? {}
  const role = opts.role === "organizer" ? "organizer" : "vendor"
  const params = new URLSearchParams()
  params.set("role", role)
  if (opts.redirect) {
    params.set("redirect", opts.redirect)
  }
  return `${base}/sign-up/?${params.toString()}`
}

export function getVendorLoginUrl(options?: {
  redirect?: string
  intent?: "vendor"
}): string {
  const params = new URLSearchParams()
  if (options?.intent) params.set("intent", options.intent)
  if (options?.redirect) params.set("redirect", options.redirect)
  const qs = params.toString()
  return qs ? `/login?${qs}` : "/login"
}

export function getSessionSecret(): string {
  const secret = process.env.SESSION_SECRET?.trim()
  if (!secret || secret.length < 16) {
    throw new Error("SESSION_SECRET must be set (min 16 characters)")
  }
  return secret
}

export function assertServerConfig(): void {
  if (!process.env.WORDPRESS_API_URL) {
    throw new Error("WORDPRESS_API_URL is not configured")
  }
  getSessionSecret()
}
