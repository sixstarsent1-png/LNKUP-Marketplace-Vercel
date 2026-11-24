import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type MobileAnalyticsEventName =
  | "organizer_experiment_viewed"
  | "organizer_experiment_recommendation_opened"
  | "organizer_experiment_recommendation_action_clicked"
  | "organizer_experiment_decision_recorded"
  | "tutorial_viewed"
  | "tutorial_step_clicked"
  | "tutorial_step_completed"
  | "tutorial_push_cta_clicked"
  | "map_view_opened"
  | "map_pin_clicked"
  | "deep_opportunity_opened"
  | "share_link_copied"
  | "apply_clicked"

const ALLOWED_EVENTS = new Set<MobileAnalyticsEventName>([
  "deep_opportunity_opened",
  "share_link_copied",
  "apply_clicked",
  "organizer_experiment_viewed",
  "organizer_experiment_recommendation_opened",
  "organizer_experiment_recommendation_action_clicked",
  "organizer_experiment_decision_recorded",
  "tutorial_viewed",
  "tutorial_step_clicked",
  "tutorial_step_completed",
  "tutorial_push_cta_clicked",
  "map_view_opened",
  "map_pin_clicked",
])

type AnalyticsMetadata = Record<string, string | number | boolean | null>

function sanitizeMetadata(metadata: AnalyticsMetadata): AnalyticsMetadata {
  const blocked = new Set([
    "password",
    "app_password",
    "token",
    "secret",
    "credential",
    "email",
    "phone",
    "document",
    "file",
    "lat",
    "lng",
    "latitude",
    "longitude",
  ])

  const safe: AnalyticsMetadata = {}
  for (const [key, value] of Object.entries(metadata)) {
    const normalized = key.toLowerCase()
    if (blocked.has(normalized)) continue
    if (normalized.includes("password") || normalized.includes("secret")) continue
    if (typeof value === "string" && value.length > 200) continue
    safe[key] = value
  }
  return safe
}

export async function trackMobileEvent(params: {
  event: MobileAnalyticsEventName
  source: string
  auth?: WpAuth
  metadata?: AnalyticsMetadata
}): Promise<void> {
  if (!ALLOWED_EVENTS.has(params.event)) return
  if (!isLiveApiEnabled()) return

  try {
    await wpFetchJson("/marketplace/track/event", {
      method: "POST",
      auth: params.auth,
      body: {
        event_type: params.event,
        data: sanitizeMetadata({
          ...(params.metadata ?? {}),
          source: params.source,
        }),
      },
    })
  } catch {
    // best-effort analytics only
  }
}
