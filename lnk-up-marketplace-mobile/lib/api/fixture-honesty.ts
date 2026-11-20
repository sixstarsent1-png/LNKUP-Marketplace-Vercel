import { isLiveApiEnabled } from "@/lib/api/config"

export type ApiDataSource = "live" | "fixture"

type FixtureListener = (active: boolean) => void

let fixtureActive = false
const listeners = new Set<FixtureListener>()

/** Live API expected (production/preview channel) — demo banner applies here only. */
export function shouldShowFixtureHonesty(): boolean {
  return isLiveApiEnabled()
}

/** Call when live mode unexpectedly serves fixture/demo data (API error fallback). */
export function reportFixtureFallback(_scope?: string): void {
  if (!shouldShowFixtureHonesty()) return
  if (fixtureActive) return
  fixtureActive = true
  for (const listener of listeners) {
    listener(true)
  }
}

export function resetFixtureHonesty(): void {
  if (!fixtureActive) return
  fixtureActive = false
  for (const listener of listeners) {
    listener(false)
  }
}

export function subscribeFixtureHonesty(listener: FixtureListener): () => void {
  listeners.add(listener)
  listener(fixtureActive)
  return () => {
    listeners.delete(listener)
  }
}

export function isFixtureDataActive(): boolean {
  return fixtureActive && shouldShowFixtureHonesty()
}

/**
 * Live API: return live data and let failures reach the screen's error state — never substitute
 * sample data (a fake "payment due" row is worse than an honest error).
 * Development profile (live API off): serve fixtures only.
 */
export async function withFixtureFallback<T>(
  _scope: string,
  fetchLive: () => Promise<T>,
  fetchFixture: () => T | Promise<T>,
): Promise<T> {
  if (!isLiveApiEnabled()) {
    return await fetchFixture()
  }
  return await fetchLive()
}
