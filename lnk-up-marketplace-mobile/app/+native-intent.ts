function firstParam(value: string | null): string {
  return (value ?? "").trim()
}

/**
 * Website profile links open the in-app storefront when the app is installed.
 * Paths stay off /vendors so they do not collide with the organizer Vendors tab.
 */
export function redirectSystemPath({ path }: { path: string; initial: boolean }): string {
  try {
    const url = new URL(path, "https://thelnkup.com")
    const pathname = url.pathname.replace(/\/+$/, "") || "/"
    const vendor = firstParam(url.searchParams.get("vendor"))
    if ((pathname === "/vendors" || pathname === "/storefront/vendor") && vendor) {
      return `/storefront/vendor?vendor=${encodeURIComponent(vendor)}`
    }
    const organizer = firstParam(url.searchParams.get("organizer"))
    if ((pathname === "/organizers" || pathname === "/storefront/organizer") && organizer) {
      return `/storefront/organizer?organizer=${encodeURIComponent(organizer)}`
    }
    if (pathname === "/opportunities") {
      const apply = firstParam(url.searchParams.get("apply"))
      if (/^\d+$/.test(apply)) return `/opportunity/${apply}`
      return "/(vendor)/find"
    }
    return path
  } catch {
    return path
  }
}
