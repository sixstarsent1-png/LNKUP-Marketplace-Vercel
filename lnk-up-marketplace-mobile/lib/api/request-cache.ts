/**
 * Tiny in-memory request cache for read endpoints shared across tabs.
 *
 * - Concurrent callers share one in-flight promise (no duplicate requests on tab switch).
 * - Results are reused for `ttlMs`; `force` bypasses the cache (pull-to-refresh, payment return).
 * - Failures are never cached, so the next caller retries.
 * - Invalidation also drops an in-flight read: a read that started before a write must not be
 *   served (or stored) as the post-write value.
 * - Subscribers hear about fresh values so badges update without their own polling.
 */

type Entry<T> = {
  value?: T
  fetchedAt: number
  inflight?: Promise<T>
  generation: number
}

type Listener<T> = (value: T) => void

const entries = new Map<string, Entry<unknown>>()
const listeners = new Map<string, Set<Listener<unknown>>>()
let clearGeneration = 0

export type CachedRequestOptions = {
  ttlMs?: number
  force?: boolean
}

export async function cachedRequest<T>(
  key: string,
  fetcher: () => Promise<T>,
  { ttlMs = 15_000, force = false }: CachedRequestOptions = {},
): Promise<T> {
  const entry = (entries.get(key) as Entry<T> | undefined) ?? { fetchedAt: 0, generation: clearGeneration }
  if (entry.inflight && !force) return entry.inflight
  const fresh = entry.value !== undefined && Date.now() - entry.fetchedAt < ttlMs
  if (fresh && !force) return entry.value as T

  const generation = entry.generation + 1
  const inflight = fetcher()
    .then((value) => {
      const current = entries.get(key) as Entry<T> | undefined
      if (current?.generation === generation) {
        entries.set(key, { value, fetchedAt: Date.now(), generation })
        listeners.get(key)?.forEach((listener) => listener(value))
      }
      return value
    })
    .catch((err: unknown) => {
      const current = entries.get(key) as Entry<T> | undefined
      if (current?.generation === generation) {
        entries.set(key, { value: current.value, fetchedAt: current.fetchedAt, generation })
      }
      throw err
    })
  entries.set(key, { value: entry.value, fetchedAt: entry.fetchedAt, inflight, generation })
  return inflight
}

/** Drop cached values (and in-flight reads) whose key starts with `prefix` so the next read refetches. */
export function invalidateCache(prefix: string): void {
  for (const [key, entry] of entries) {
    if (key.startsWith(prefix)) {
      entries.set(key, { fetchedAt: 0, generation: entry.generation + 1 })
    }
  }
}

/** Clear everything (sign-out). In-flight reads from the old session are discarded. */
export function clearRequestCache(): void {
  for (const entry of entries.values()) {
    clearGeneration = Math.max(clearGeneration, entry.generation + 1)
  }
  entries.clear()
}

export function subscribeCache<T>(key: string, listener: Listener<T>): () => void {
  const set = listeners.get(key) ?? new Set<Listener<unknown>>()
  set.add(listener as Listener<unknown>)
  listeners.set(key, set)
  return () => {
    set.delete(listener as Listener<unknown>)
  }
}
