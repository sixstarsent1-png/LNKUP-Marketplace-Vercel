import { getWpBaseUrl, getWpJsonBase } from "@/lib/api/config"
import type { WpApiError } from "@/lib/api/types"

export type WpAuth = {
  username: string
  appPassword: string
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

function basicAuthHeader(auth: WpAuth): string {
  const token = Buffer.from(`${auth.username}:${auth.appPassword}`).toString("base64")
  return `Basic ${token}`
}

function parseWpError(status: number, body: unknown): WpFetchError {
  if (body && typeof body === "object" && "message" in body) {
    const err = body as WpApiError
    return new WpFetchError(
      String(err.message || "LNKup request failed"),
      String(err.code || "wp_error"),
      status,
      typeof err.data === "object" ? (err.data as Record<string, unknown>) : undefined,
    )
  }
  return new WpFetchError("LNKup request failed", "wp_error", status)
}

export async function wpFetchJson<T>(
  path: string,
  options: {
    method?: string
    query?: Record<string, string | number | boolean | undefined>
    body?: unknown
    auth?: WpAuth
    namespace?: "lnkup" | "wp"
    /** Extra request headers (e.g. Idempotency-Key); Accept, Content-Type and Authorization always win. */
    headers?: Record<string, string>
  } = {},
): Promise<T> {
  const namespace = options.namespace ?? "lnkup"
  const base = namespace === "wp" ? `${getWpBaseUrl()}/wp-json/wp/v2` : getWpJsonBase()
  const url = new URL(`${base}${path.startsWith("/") ? path : `/${path}`}`)

  if (options.query) {
    Object.entries(options.query).forEach(([key, value]) => {
      if (value !== undefined && value !== "") {
        url.searchParams.set(key, String(value))
      }
    })
  }

  const headers: Record<string, string> = {
    ...(options.headers ?? {}),
    Accept: "application/json",
  }

  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json"
  }

  if (options.auth) {
    headers.Authorization = basicAuthHeader(options.auth)
  }

  const response = await fetch(url.toString(), {
    method: options.method ?? (options.body !== undefined ? "POST" : "GET"),
    headers,
    body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
    cache: "no-store",
    signal: AbortSignal.timeout(15_000),
  })

  const contentType = response.headers.get("content-type") || ""
  const isJson = contentType.includes("application/json")
  const payload = isJson ? await response.json().catch(() => null) : null

  if (!response.ok || !isJson) {
    throw parseWpError(response.status, payload ?? { message: "Invalid response from LNKup" })
  }

  return payload as T
}

export async function wpFetchMultipart<T>(
  path: string,
  formData: FormData,
  options: {
    auth?: WpAuth
    namespace?: "lnkup" | "wp"
  } = {},
): Promise<T> {
  const namespace = options.namespace ?? "lnkup"
  const base = namespace === "wp" ? `${getWpBaseUrl()}/wp-json/wp/v2` : getWpJsonBase()
  const url = new URL(`${base}${path.startsWith("/") ? path : `/${path}`}`)

  const headers: Record<string, string> = {
    Accept: "application/json",
  }

  if (options.auth) {
    headers.Authorization = basicAuthHeader(options.auth)
  }

  const response = await fetch(url.toString(), {
    method: "POST",
    headers,
    body: formData,
    cache: "no-store",
    signal: AbortSignal.timeout(30_000),
  })

  const contentType = response.headers.get("content-type") || ""
  const isJson = contentType.includes("application/json")
  const payload = isJson ? await response.json().catch(() => null) : null

  if (!response.ok || !isJson) {
    throw parseWpError(response.status, payload ?? { message: "Invalid response from LNKup" })
  }

  return payload as T
}

export async function wpValidateAuth(auth: WpAuth) {
  return wpFetchJson<{ id: number; name: string; slug: string }>("/users/me", {
    auth,
    namespace: "wp",
  })
}
