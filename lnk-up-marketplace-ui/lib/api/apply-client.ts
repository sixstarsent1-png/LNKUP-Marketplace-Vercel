/** Browser helpers for the apply BFF routes (single apply, drafts, withdraw, tour apply). */

export type ApplyAnswerValue = string | boolean | number

export interface ApplyCallResult<T> {
  ok: boolean
  status: number
  code: string
  message: string
  data: T | null
  /** WordPress error data (e.g. `fields` for missing answers, `draft` on a conflict). */
  errorData: Record<string, unknown> | null
}

/** New idempotency key for one submit attempt (reused on retries of the same attempt). */
export function newIdempotencyKey(): string {
  return crypto.randomUUID().replace(/-/g, "")
}

/** JSON request that never throws for HTTP errors; network failures surface as status 0. */
export async function applyRequest<T>(
  url: string,
  init: { method?: string; body?: unknown; idempotencyKey?: string } = {},
): Promise<ApplyCallResult<T>> {
  try {
    const headers: Record<string, string> = {}
    if (init.body !== undefined) headers["Content-Type"] = "application/json"
    if (init.idempotencyKey) headers["Idempotency-Key"] = init.idempotencyKey
    const response = await fetch(url, {
      method: init.method ?? (init.body !== undefined ? "POST" : "GET"),
      headers,
      body: init.body !== undefined ? JSON.stringify(init.body) : undefined,
    })
    const isJson = (response.headers.get("content-type") || "").includes("application/json")
    const json = isJson ? ((await response.json().catch(() => null)) as Record<string, unknown> | null) : null
    if (!response.ok) {
      return {
        ok: false,
        status: response.status,
        code: String(json?.code ?? "request_failed"),
        message: String(json?.message ?? "Something went wrong. Please try again."),
        data: null,
        errorData: json?.data && typeof json.data === "object" ? (json.data as Record<string, unknown>) : null,
      }
    }
    return { ok: true, status: response.status, code: "", message: "", data: json as T, errorData: null }
  } catch {
    return {
      ok: false,
      status: 0,
      code: "network_error",
      message: "We couldn't reach LNKup. Check your connection and try again.",
      data: null,
      errorData: null,
    }
  }
}

/** Question ids WordPress reported as missing or invalid. */
export function invalidFieldIds(result: ApplyCallResult<unknown>): string[] {
  const fields = result.errorData?.fields
  return Array.isArray(fields) ? fields.map(String) : []
}

export function withdrawApplication(applicationId: number | string) {
  return applyRequest<{ application_id: number; status: string; status_label: string }>(
    `/api/applications/${encodeURIComponent(String(applicationId))}/withdraw`,
    { method: "POST", body: {} },
  )
}

/** Vendor statuses that can still be withdrawn (mirrors LNKup_Applications::WITHDRAWABLE). */
export const WITHDRAWABLE_STATUSES = [
  "submitted",
  "under_review",
  "info_requested",
  "waitlisted",
  "approved",
  "payment_required",
] as const

export function canWithdrawStatus(status: string | null | undefined, paymentInProgress = false): boolean {
  return !paymentInProgress && (WITHDRAWABLE_STATUSES as readonly string[]).includes(String(status ?? ""))
}
