export type AppErrorCode =
  | "auth_required"
  | "forbidden"
  | "not_found"
  | "marketplace_closed"
  | "wordpress_unavailable"
  | "invalid_response"
  | "validation_error"
  | "unknown"

export type AppErrorShape = {
  code: AppErrorCode
  title: string
  message: string
  actionLabel?: string
  actionHref?: string
  retryable?: boolean
  status?: number
}

export type BffErrorBody = {
  code: string
  message: string
  data?: Record<string, unknown>
}

/** Map legacy BFF codes to AppErrorCode for clients. */
export function normalizeErrorCode(code?: string): AppErrorCode {
  const c = (code ?? "").toLowerCase()
  if (c === "auth_required" || c === "not_authenticated" || c === "not_logged_in") {
    return "auth_required"
  }
  if (c === "forbidden") return "forbidden"
  if (c === "not_found") return "not_found"
  if (c === "marketplace_closed") return "marketplace_closed"
  if (c === "invalid_response" || c === "wp_error") return "invalid_response"
  if (c === "validation_error" || c === "invalid_action" || c === "reason_required") {
    return "validation_error"
  }
  if (c === "wordpress_unavailable" || c === "unavailable") return "wordpress_unavailable"
  return "unknown"
}

export function appErrorFromBff(body: BffErrorBody, status?: number): AppErrorShape {
  const code = normalizeErrorCode(body.code)
  const defaults: Record<AppErrorCode, { title: string; message: string; retryable?: boolean }> = {
    auth_required: {
      title: "Sign in to continue",
      message: "Use your LNKup account to continue.",
      retryable: false,
    },
    forbidden: {
      title: "Access restricted",
      message: "You don't have access to this section.",
      retryable: false,
    },
    not_found: {
      title: "Not found",
      message: "We couldn't find what you're looking for.",
      retryable: false,
    },
    marketplace_closed: {
      title: "Applications not open yet",
      message: body.message || "Applications are not open for this event yet.",
      retryable: false,
    },
    wordpress_unavailable: {
      title: "Service unavailable",
      message: "We couldn't reach LNKup. Try again in a moment.",
      retryable: true,
    },
    invalid_response: {
      title: "Something went wrong",
      message: "We received an unexpected response. Please try again.",
      retryable: true,
    },
    validation_error: {
      title: "Check your input",
      message: body.message || "Please review the form and try again.",
      retryable: false,
    },
    unknown: {
      title: "Something went wrong",
      message: body.message || "We couldn't load this section.",
      retryable: true,
    },
  }

  const base = defaults[code]
  return {
    code,
    title: base.title,
    message: body.message || base.message,
    retryable: base.retryable,
    status,
    actionLabel: code === "auth_required" ? "Sign in" : undefined,
    actionHref: code === "auth_required" ? "/login" : undefined,
  }
}
