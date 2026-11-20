import { NextResponse } from "next/server"
import type { AppErrorShape, BffErrorBody } from "@/lib/api/app-error"
import { WpFetchError } from "@/lib/api/wp-fetch"

export type ErrorResponseOptions = {
  context?: string
  fallbackMessage?: string
  fallbackCode?: string
  /** When true, pass through safe WP message for apply-style flows */
  exposeMessage?: boolean
  /** Include error.data in JSON body */
  includeData?: boolean
  forbiddenMessage?: string
}

function logBffError(context: string | undefined, error: unknown, requestId: string) {
  const tag = context ? `[${context}]` : "[bff]"
  if (error instanceof WpFetchError) {
    console.error(tag, requestId, error.code, error.status, error.message.slice(0, 200))
    return
  }
  if (error instanceof Error) {
    console.error(tag, requestId, error.message.slice(0, 200))
    return
  }
  console.error(tag, requestId, "unknown_error")
}

export function mapAuthError(
  error: unknown,
  options?: { forbiddenMessage?: string; notFoundMessage?: string },
): { status: number; body: BffErrorBody } | null {
  if (!(error instanceof WpFetchError)) return null

  if (error.status === 401) {
    return {
      status: 401,
      body: { code: "auth_required", message: "Sign in to continue." },
    }
  }
  if (error.status === 403) {
    return {
      status: 403,
      body: {
        code: "forbidden",
        message: options?.forbiddenMessage ?? "You don't have access to this section.",
      },
    }
  }
  if (error.status === 404) {
    return {
      status: 404,
      body: {
        code: "not_found",
        message: options?.notFoundMessage ?? "We couldn't find that resource.",
      },
    }
  }
  return null
}

export function normalizeWpError(error: unknown, options: ErrorResponseOptions = {}): AppErrorShape {
  const auth = mapAuthError(error, {
    forbiddenMessage: options.forbiddenMessage,
  })
  if (auth) {
    return {
      code: auth.body.code as AppErrorShape["code"],
      title: auth.status === 401 ? "Sign in to continue" : "Access restricted",
      message: auth.body.message,
      status: auth.status,
      retryable: false,
      actionLabel: auth.status === 401 ? "Sign in" : undefined,
      actionHref: auth.status === 401 ? "/login" : undefined,
    }
  }

  if (error instanceof WpFetchError) {
    const code =
      error.status >= 500
        ? "wordpress_unavailable"
        : error.code === "marketplace_closed"
          ? "marketplace_closed"
          : "unknown"

    return {
      code: code as AppErrorShape["code"],
      title: "Something went wrong",
      message:
        options.exposeMessage && error.message
          ? error.message
          : options.fallbackMessage ?? "We couldn't complete this request. Please try again.",
      status: error.status || 502,
      retryable: error.status >= 500,
    }
  }

  if (error instanceof DOMException && error.name === "TimeoutError") {
    return {
      code: "wordpress_unavailable",
      title: "Request timed out",
      message: "LNKup took too long to respond. Try again.",
      status: 504,
      retryable: true,
    }
  }

  return {
    code: "unknown",
    title: "Something went wrong",
    message: options.fallbackMessage ?? "We couldn't complete this request. Please try again.",
    status: 500,
    retryable: true,
  }
}

export function toErrorResponse(error: unknown, options: ErrorResponseOptions = {}): NextResponse {
  const requestId = crypto.randomUUID()
  logBffError(options.context, error, requestId)

  const auth = mapAuthError(error, {
    forbiddenMessage: options.forbiddenMessage,
  })
  if (auth) {
    return NextResponse.json(auth.body, { status: auth.status })
  }

  if (error instanceof WpFetchError) {
    const body: BffErrorBody = {
      code: error.code === "marketplace_closed" ? "marketplace_closed" : options.fallbackCode ?? error.code,
      message:
        options.exposeMessage && error.message
          ? error.message
          : options.fallbackMessage ?? "We couldn't complete this request. Please try again.",
    }
    if (options.includeData && error.data) {
      body.data = error.data
    }
    return NextResponse.json(body, { status: error.status || 502 })
  }

  const normalized = normalizeWpError(error, options)
  return NextResponse.json(
    {
      code: options.fallbackCode ?? normalized.code,
      message: normalized.message,
    },
    { status: normalized.status ?? 500 },
  )
}

export function authRequiredResponse(message = "Sign in to continue."): NextResponse {
  return NextResponse.json(
    { code: "auth_required", message },
    { status: 401 },
  )
}

/** @deprecated Use mapAuthError from normalize-wp-error */
export function organizerAuthError(error: unknown) {
  const mapped = mapAuthError(error, {
    forbiddenMessage: "This section is for organizers.",
    notFoundMessage: "We couldn't find that vendor request.",
  })
  if (!mapped) return null
  return { status: mapped.status as 401 | 403 | 404, body: mapped.body }
}

/** @deprecated Use mapAuthError from normalize-wp-error */
export function vendorRequestAuthError(error: unknown) {
  const mapped = mapAuthError(error, {
    forbiddenMessage: "You don't have access to this request.",
    notFoundMessage: "We couldn't find that vendor request.",
  })
  if (!mapped) return null
  return { status: mapped.status as 401 | 403 | 404, body: mapped.body }
}
