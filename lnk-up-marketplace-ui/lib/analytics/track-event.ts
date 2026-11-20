import type { LnkupAnalyticsEvent } from "@/lib/analytics/lnkup-events"

export function trackLnkupEvent(payload: LnkupAnalyticsEvent): void {
  if (typeof window === "undefined") return

  try {
    void fetch("/api/analytics/track", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      keepalive: true,
    }).catch(() => {
      /* fail silently */
    })
  } catch {
    /* fail silently */
  }
}
