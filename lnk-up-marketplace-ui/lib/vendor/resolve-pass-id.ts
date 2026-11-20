export type PassLookupRequest = {
  id: string
  eventId?: string
  eventName?: string
}

export function slugifyPassKey(value: string): string {
  return value
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9]+/g, "-")
    .replace(/^-+|-+$/g, "")
}

/**
 * Match a pass URL param to a vendor application.
 * Prefer application id, then event id, then event-name slug.
 */
export function matchVendorPassApplicationId(
  param: string,
  requests: PassLookupRequest[],
): string | null {
  const needle = param.trim()
  if (!needle) {
    return null
  }

  const byId = requests.find((item) => item.id === needle)
  if (byId) {
    return byId.id
  }

  const byEventId = requests.find((item) => item.eventId && String(item.eventId) === needle)
  if (byEventId) {
    return byEventId.id
  }

  const slug = slugifyPassKey(needle)
  if (!slug) {
    return null
  }

  const bySlug = requests.find((item) => slugifyPassKey(item.eventName ?? "") === slug)
  return bySlug?.id ?? null
}

export function resolveLivePassApplicationId(
  param: string,
  requests: PassLookupRequest[],
): string | null {
  const matched = matchVendorPassApplicationId(param, requests)
  if (matched) {
    return matched
  }
  if (/^\d+$/.test(param) || param.startsWith("req-")) {
    return param
  }
  return null
}
