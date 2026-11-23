import * as Linking from "expo-linking"

type Params = Record<string, string | string[] | undefined>

const STANDALONE_ROOTS = new Set(["pass", "apply", "booking", "opportunity", "storefront"])

/**
 * Rebuild the in-app href for the current screen from router segments (which keep route groups like
 * `(vendor)`) and global params (which fill `[eventId]`-style segments).
 */
export function currentHref(segments: string[], params: Params): string {
  const used = new Set<string>()
  const path = segments
    .filter((segment) => segment !== "index")
    .map((segment) => {
      const match = segment.match(/^\[(?:\.\.\.)?(\w+)\]$/)
      if (!match) return segment
      used.add(match[1])
      const value = params[match[1]]
      return encodeURIComponent(String(Array.isArray(value) ? value[0] : (value ?? "")))
    })
    .join("/")
  const query = Object.entries(params)
    .filter(([key, value]) => !used.has(key) && typeof value === "string" && value !== "")
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join("&")
  return `/${path}${query ? `?${query}` : ""}`
}

/**
 * Href from the URL that opened the app (deep link, universal link, or the browser address on web),
 * scoped to `group` when the path is one of that shell's screens.
 */
export function hrefFromLinkingUrl(url: string | null | undefined, group: "(vendor)" | "(organizer)" | "(partner)"): string | null {
  if (!url) return null
  let parsed: ReturnType<typeof Linking.parse>
  try {
    parsed = Linking.parse(url)
  } catch {
    return null
  }
  const path = (parsed.path ?? "").replace(/^\/+|\/+$/g, "")
  if (!path || path === "login") return null
  const query = Object.entries(parsed.queryParams ?? {})
    .filter(([, value]) => typeof value === "string" && value !== "")
    .map(([key, value]) => `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    .join("&")
  const first = path.split("/")[0]
  const scoped = first.startsWith("(") || STANDALONE_ROOTS.has(first) ? path : `${group}/${path}`
  return `/${scoped}${query ? `?${query}` : ""}`
}

/**
 * Where a signed-out shell should send the user back to after login. While a shell layout is
 * redirecting its child screen is not mounted, so segments stop at the group — fall back to the
 * URL the app was opened with.
 */
export function shellReturnTo(
  group: "(vendor)" | "(organizer)" | "(partner)",
  segments: string[],
  params: Params,
  linkingUrl: string | null | undefined,
): string {
  if (segments.length > 1) return currentHref(segments, params)
  return hrefFromLinkingUrl(linkingUrl, group) ?? `/${group}`
}
