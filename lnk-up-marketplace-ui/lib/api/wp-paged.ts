import { type WpAuth, wpFetchJson } from "@/lib/api/wp-fetch"

const PAGE_SIZE = 100

/**
 * Read every page of a WordPress list route that returns a JSON array and accepts
 * `page` / `per_page`. Stops at the first short page or after `maxPages`, and reports
 * `truncated` when the cap was hit so the UI never presents a partial list as complete.
 */
export async function wpFetchAllPages<T>(
  path: string,
  options: { auth: WpAuth; query?: Record<string, string | number | boolean | undefined>; maxPages?: number },
): Promise<{ items: T[]; truncated: boolean }> {
  const maxPages = options.maxPages ?? 5
  const items: T[] = []
  for (let page = 1; page <= maxPages; page += 1) {
    const rows = await wpFetchJson<T[]>(path, {
      auth: options.auth,
      query: { ...options.query, per_page: PAGE_SIZE, page },
    })
    const list = Array.isArray(rows) ? rows : []
    items.push(...list)
    if (list.length < PAGE_SIZE) return { items, truncated: false }
  }
  return { items, truncated: true }
}
