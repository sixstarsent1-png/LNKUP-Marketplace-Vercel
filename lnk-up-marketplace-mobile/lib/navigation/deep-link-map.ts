/**
 * Maps a website URL (universal / app link) to an in-app route.
 *
 * - Profile links open the in-app storefront. Paths stay off /vendors so they do not collide with
 *   the organizer Vendors tab.
 * - Slug URLs (/events/{slug}, /tours/{slug}, /markets/{city}[/{category}]) and tracked links
 *   (/l/{code}) go through the /link screen, which asks WordPress to resolve them.
 */

import type { ResolvedLink } from "@/lib/api/deep-links"
import { isOrganizerRole, isVendorRole } from "@/lib/auth/roles"

const SLUG = /^[a-z0-9][a-z0-9-]{0,190}$/
const LINK_CODE = /^[A-Za-z0-9]{4,16}$/
const RESOLVED_PREFIXES = new Set(["events", "lnkup_event", "tours", "series", "markets"])

function firstParam(value: string | null): string {
  return (value ?? "").trim()
}

export function mapSystemPath(path: string): string {
  try {
    const url = new URL(path, "https://thelnkup.com")
    const pathname = url.pathname.replace(/\/+$/, "") || "/"
    const segments = pathname.split("/").filter(Boolean)

    const vendor = firstParam(url.searchParams.get("vendor"))
    if ((pathname === "/vendors" || pathname === "/storefront/vendor") && vendor) {
      return `/storefront/vendor?vendor=${encodeURIComponent(vendor)}`
    }
    const organizer = firstParam(url.searchParams.get("organizer"))
    if ((pathname === "/organizers" || pathname === "/storefront/organizer") && organizer) {
      return `/storefront/organizer?organizer=${encodeURIComponent(organizer)}`
    }
    if (segments.length === 2 && segments[0] === "vendors" && SLUG.test(segments[1])) {
      return `/storefront/vendor?vendor=${encodeURIComponent(segments[1])}`
    }
    if (segments.length === 2 && segments[0] === "organizers" && SLUG.test(segments[1])) {
      return `/storefront/organizer?organizer=${encodeURIComponent(segments[1])}`
    }
    if (pathname === "/opportunities") {
      const apply = firstParam(url.searchParams.get("apply"))
      if (/^\d+$/.test(apply)) return `/opportunity/${apply}`
      return "/(vendor)/find"
    }
    if (segments.length === 2 && segments[0] === "l" && LINK_CODE.test(segments[1])) {
      return `/link?path=${encodeURIComponent(`/l/${segments[1]}/`)}`
    }
    if (RESOLVED_PREFIXES.has(segments[0] ?? "") && segments.length >= 2 && segments.length <= 3 && segments.slice(1).every((s) => SLUG.test(s))) {
      return `/link?path=${encodeURIComponent(`${pathname}/`)}`
    }
    return path
  } catch {
    return path
  }
}

/** App route for a link WordPress resolved. Feed links open Find for vendors and guests. */
export function hrefForResolved(link: ResolvedLink, roles: string[] | undefined): string {
  switch (link.route) {
    case "event":
      return `/event/${link.id}`
    case "opportunity":
      return `/opportunity/${link.id}`
    case "tour":
      return `/tour/${link.id}`
    case "organizer":
      return `/storefront/organizer?organizer=${encodeURIComponent(link.params.organizer ?? "")}`
    case "signup":
      return roles?.length ? "/" : `/signup?role=${link.params.role === "organizer" ? "organizer" : "vendor"}`
    case "feed": {
      if (isOrganizerRole(roles) && !isVendorRole(roles)) return "/"
      const query = new URLSearchParams()
      for (const key of ["city", "state", "category"] as const) {
        if (link.params[key]) query.set(key, link.params[key])
      }
      const qs = query.toString()
      return qs ? `/(vendor)/find?${qs}` : "/(vendor)/find"
    }
    default: {
      const unhandled: never = link.route
      return unhandled
    }
  }
}
