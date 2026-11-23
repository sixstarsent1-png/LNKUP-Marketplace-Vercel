import { type WpAuth, WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"

export type DecisionAction = "approve" | "waitlist" | "reject"

/**
 * Record an organizer decision through the review workspace route (per-event team permissions
 * and decision history). Sites without Organizer OS review answer `lnkup_feature_off`; those
 * use the legacy roster bulk route. Either way a refused decision throws, never reports success.
 */
export async function recordOrganizerDecision(
  auth: WpAuth,
  input: { applicationId: string; eventId: string; action: DecisionAction; reason?: string; internalNote?: string },
): Promise<{ via: "review" | "roster" }> {
  try {
    await wpFetchJson(`/applications/${encodeURIComponent(input.applicationId)}/decision`, {
      method: "POST",
      auth,
      body: { action: input.action, rejection_reason: input.reason ?? "" },
    })
    return { via: "review" }
  } catch (error) {
    if (!(error instanceof WpFetchError) || error.code !== "lnkup_feature_off") throw error
  }

  const payload: Record<string, unknown> = { application_ids: [Number(input.applicationId)], action: input.action }
  if (input.reason) payload.rejection_reason = input.reason
  if (input.internalNote) payload.internal_note = input.internalNote
  const result = await wpFetchJson<{ failed?: Array<{ message?: string }> }>(
    `/crm/events/${encodeURIComponent(input.eventId)}/roster/bulk`,
    { method: "POST", auth, body: payload },
  )
  const failure = result?.failed?.[0]
  if (failure) {
    throw new WpFetchError(failure.message || "This request could not be updated.", "review_refused", 409)
  }
  return { via: "roster" }
}
