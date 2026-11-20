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
  retryable?: boolean
  status?: number
}

export class WpFetchError extends Error {
  code: string
  status: number
  data?: Record<string, unknown>

  constructor(message: string, code: string, status: number, data?: Record<string, unknown>) {
    super(message)
    this.name = "WpFetchError"
    this.code = code
    this.status = status
    this.data = data
  }
}

export function normalizeErrorCode(code?: string): AppErrorCode {
  const c = (code ?? "").toLowerCase()
  if (c === "auth_required" || c === "not_authenticated" || c === "not_logged_in" || c === "rest_forbidden") {
    return "auth_required"
  }
  if (c === "forbidden") return "forbidden"
  if (c === "not_found" || c === "rest_no_route") return "not_found"
  if (c === "marketplace_closed") return "marketplace_closed"
  if (c === "invalid_response" || c === "wp_error") return "invalid_response"
  if (c === "validation_error" || c === "invalid_action") return "validation_error"
  if (c === "wordpress_unavailable" || c === "unavailable" || c === "network_error") {
    return "wordpress_unavailable"
  }
  return "unknown"
}

const GENERIC_SERVER_MESSAGE = /lnkup request failed|invalid response|wordpress request failed|waf|hosting|server proxy|internal server error|critical error/i

/**
 * Server messages written for people (validation, conflicts, permissions) are shown as-is;
 * transport failures, timeouts, 5xx and generic fallbacks get plain-language copy instead.
 */
function friendlyMessage(error: WpFetchError, code: AppErrorCode): string {
  const raw = (error.message || "").trim()
  if (error.code === "network_error" || error.status === 0) {
    return "You appear to be offline or LNKup can't be reached. Check your connection and try again."
  }
  if (error.status === 408) {
    return "LNKup took too long to respond. Try again in a moment."
  }
  if (error.status >= 500 || !raw || GENERIC_SERVER_MESSAGE.test(raw)) {
    return defaultMessage(code)
  }
  return raw
}

export function appErrorFromWp(error: unknown): AppErrorShape {
  if (error instanceof WpFetchError) {
    const code = error.status >= 500 ? "wordpress_unavailable" : normalizeErrorCode(error.code)
    return {
      code,
      title: titleForCode(code),
      message: friendlyMessage(error, code),
      retryable: code === "wordpress_unavailable" || code === "invalid_response" || code === "unknown",
      status: error.status,
    }
  }

  if (error instanceof Error) {
    return {
      code: "wordpress_unavailable",
      title: "Service unavailable",
      message: "We couldn't reach LNKup. Try again in a moment.",
      retryable: true,
    }
  }

  return {
    code: "unknown",
    title: "Something went wrong",
    message: "Please try again.",
    retryable: true,
  }
}

/**
 * Shape errors from POST /auth/login (website password).
 */
export function loginErrorFromWp(error: unknown): AppErrorShape {
  if (error instanceof WpFetchError && (error.status === 401 || error.status === 403)) {
    const wpMessage = (error.message || "").trim()
    const looksGeneric =
      !wpMessage ||
      /wordpress request failed|lnkup request failed|invalid response|rest_forbidden|not logged in|application password/i.test(
        wpMessage,
      )

    return {
      code: "auth_required",
      title: "Couldn't sign in",
      message: looksGeneric
        ? "Invalid username or password. Use the same password as thelnkup.com."
        : wpMessage,
      retryable: false,
      status: error.status,
    }
  }

  if (error instanceof WpFetchError && error.status === 404) {
    return {
      code: "wordpress_unavailable",
      title: "Couldn't sign in",
      message:
        "Sign-in is not available on the server yet. Deploy the LNKup Marketplace plugin update, then try again.",
      retryable: true,
      status: 404,
    }
  }

  const shaped = appErrorFromWp(error)
  if (shaped.code === "auth_required") {
    return {
      ...shaped,
      title: "Couldn't sign in",
      message: shaped.message || "Invalid username or password. Use the same password as thelnkup.com.",
    }
  }
  return shaped
}

/**
 * Shape errors from POST /auth/register.
 */
export function registerErrorFromWp(error: unknown): AppErrorShape {
  if (error instanceof WpFetchError && error.status === 404) {
    return {
      code: "wordpress_unavailable",
      title: "Couldn't create account",
      message:
        "Account creation is not available on the server yet. Deploy the LNKup Marketplace plugin update, then try again.",
      retryable: true,
      status: 404,
    }
  }

  const shaped = loginErrorFromWp(error)
  if (shaped.title === "Couldn't sign in") {
    return { ...shaped, title: "Couldn't create account" }
  }
  return shaped
}

function titleForCode(code: AppErrorCode): string {
  switch (code) {
    case "auth_required":
      return "Sign in to continue"
    case "forbidden":
      return "Access restricted"
    case "not_found":
      return "Not found"
    case "marketplace_closed":
      return "Applications not open yet"
    case "wordpress_unavailable":
      return "Service unavailable"
    case "invalid_response":
      return "Something went wrong"
    case "validation_error":
      return "Check your input"
    default:
      return "Something went wrong"
  }
}

function defaultMessage(code: AppErrorCode): string {
  switch (code) {
    case "auth_required":
      return "Use your LNKup account to continue."
    case "forbidden":
      return "You don't have access to this section."
    case "not_found":
      return "We couldn't find what you're looking for."
    case "marketplace_closed":
      return "Applications are not open for this event yet."
    case "wordpress_unavailable":
      return "We couldn't reach LNKup. Try again in a moment."
    case "invalid_response":
      return "We received an unexpected response. Please try again."
    case "validation_error":
      return "Please review the form and try again."
    default:
      return "Please try again."
  }
}
