import { NextResponse } from "next/server"
import { WpFetchError } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"

const IDEMPOTENCY_KEY = /^[A-Za-z0-9_-]{8,64}$/

/**
 * Apply-flow errors keep WordPress's code, message and data for 4xx responses so the UI can show
 * the exact reason (duplicate, previously_declined, lnkup_answers_required with fields, price change,
 * draft conflict). 401 and 5xx fall back to the generic BFF error shape.
 */
export function applyFlowErrorResponse(error: unknown, context: string, fallbackMessage: string): NextResponse {
  if (error instanceof WpFetchError && error.status >= 400 && error.status < 500 && error.status !== 401) {
    return NextResponse.json(
      { code: error.code, message: error.message, data: error.data ?? null },
      { status: error.status },
    )
  }
  return toErrorResponse(error, { context, fallbackMessage, fallbackCode: "apply_failed" })
}

/** Idempotency-Key from the browser request, forwarded only when well-formed. */
export function idempotencyHeaders(request: Request): Record<string, string> {
  const key = request.headers.get("idempotency-key") ?? ""
  return IDEMPOTENCY_KEY.test(key) ? { "Idempotency-Key": key } : {}
}

/** Positive integer route param, or null. */
export function positiveId(raw: string): number | null {
  return /^\d+$/.test(raw) && Number(raw) > 0 ? Number(raw) : null
}

export function invalidIdResponse(): NextResponse {
  return NextResponse.json({ code: "validation_error", message: "Invalid ID" }, { status: 400 })
}

/** Request JSON body as an object (never throws). */
export async function jsonBody(request: Request): Promise<Record<string, unknown>> {
  const body = (await request.json().catch(() => null)) as unknown
  return body && typeof body === "object" && !Array.isArray(body) ? (body as Record<string, unknown>) : {}
}
