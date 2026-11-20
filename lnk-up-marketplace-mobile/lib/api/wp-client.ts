import { getApiTimeoutMs, getWpBaseUrl, getWpJsonBase } from "@/lib/api/config"
import { encodeBase64 } from "@/lib/api/base64"
import { WpFetchError } from "@/lib/api/errors"

export type WpAuth = {
  username: string
  /** Legacy Basic auth (Application Password). Prefer token. */
  appPassword: string
  /** Bearer from POST /lnkup/v1/auth/login (website password). */
  token?: string
}

export type WpApiErrorBody = {
  code?: string
  message?: string
  data?: Record<string, unknown>
}

type UnauthorizedHandler = () => void

let unauthorizedHandler: UnauthorizedHandler | null = null
let unauthorizedNotifying = false

/** Register a handler for authenticated 401 responses (clear session + login). */
export function setUnauthorizedHandler(handler: UnauthorizedHandler | null) {
  unauthorizedHandler = handler
}

function notifyUnauthorized(hadAuth: boolean, error: WpFetchError) {
  if (!hadAuth || error.status !== 401 || !unauthorizedHandler || unauthorizedNotifying) return
  unauthorizedNotifying = true
  try {
    unauthorizedHandler()
  } finally {
    // Allow a later genuine expiry after the user signs in again.
    setTimeout(() => {
      unauthorizedNotifying = false
    }, 1500)
  }
}

function basicAuthHeader(auth: WpAuth): string {
  return `Basic ${encodeBase64(`${auth.username}:${auth.appPassword}`)}`
}

function authorizationHeader(auth: WpAuth): string {
  if (auth.token) {
    return `Bearer ${auth.token}`
  }
  return basicAuthHeader(auth)
}

function hasAuthCredentials(auth?: WpAuth): boolean {
  return Boolean(auth?.token || (auth?.username && auth?.appPassword))
}

function parseWpError(status: number, body: unknown): WpFetchError {
  if (body && typeof body === "object" && "message" in body) {
    const err = body as WpApiErrorBody
    return new WpFetchError(
      String(err.message || "LNKup request failed"),
      String(err.code || "wp_error"),
      status,
      typeof err.data === "object" ? err.data : undefined,
    )
  }
  return new WpFetchError("LNKup request failed", "wp_error", status)
}

function throwParsed(status: number, body: unknown): never {
  throw parseWpError(status, body)
}

export async function wpFetchJson<T>(
  path: string,
  options: {
    method?: string
    query?: Record<string, string | number | boolean | undefined>
    body?: unknown
    auth?: WpAuth
    namespace?: "lnkup" | "wp"
    timeoutMs?: number
    /** Caller cancellation; an aborted request rejects with an AbortError (see isAbortError). */
    signal?: AbortSignal
  } = {},
): Promise<T> {
  const namespace = options.namespace ?? "lnkup"
  const base = namespace === "wp" ? `${getWpBaseUrl()}/wp-json/wp/v2` : getWpJsonBase()
  const url = new URL(`${base}${path.startsWith("/") ? path : `/${path}`}`)
  const hadAuth = hasAuthCredentials(options.auth)

  if (options.query) {
    Object.entries(options.query).forEach(([key, value]) => {
      if (value !== undefined && value !== "") {
        url.searchParams.set(key, String(value))
      }
    })
  }

  const headers: Record<string, string> = {
    Accept: "application/json",
  }

  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json"
  }

  if (hasAuthCredentials(options.auth) && options.auth) {
    headers.Authorization = authorizationHeader(options.auth)
  }

  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? getApiTimeoutMs())
  const forwardAbort = () => controller.abort()
  if (options.signal?.aborted) controller.abort()
  options.signal?.addEventListener("abort", forwardAbort)

  try {
    const response = await fetch(url.toString(), {
      method: options.method ?? (options.body !== undefined ? "POST" : "GET"),
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
      signal: controller.signal,
    })

    const contentType = response.headers.get("content-type") || ""
    const isJson = contentType.includes("application/json")
    const payload = isJson ? await response.json().catch(() => null) : null

    if (!response.ok || !isJson) {
      if (!isJson && response.status === 403) {
        throw new WpFetchError(
          "LNKup couldn't accept this request from your network. Try again on another connection.",
          "wordpress_unavailable",
          response.status,
        )
      }
      throwParsed(response.status, payload ?? { message: "Invalid response from LNKup" })
    }

    return payload as T
  } catch (error) {
    if (error instanceof WpFetchError) {
      notifyUnauthorized(hadAuth, error)
      throw error
    }
    if (error instanceof Error && error.name === "AbortError") {
      if (options.signal?.aborted) throw error
      throw new WpFetchError("Request timed out", "wordpress_unavailable", 408)
    }
    throw new WpFetchError(
      error instanceof Error ? error.message : "Network request failed",
      "network_error",
      0,
    )
  } finally {
    clearTimeout(timeout)
    options.signal?.removeEventListener("abort", forwardAbort)
  }
}

/** True when a request was cancelled by the caller (screen left, newer search started). */
export function isAbortError(error: unknown): boolean {
  return error instanceof Error && error.name === "AbortError"
}

/**
 * POST multipart FormData to WordPress (do not set Content-Type — boundary is set by fetch).
 */
export async function wpFetchFormData<T>(
  path: string,
  formData: FormData,
  options: {
    auth?: WpAuth
    namespace?: "lnkup" | "wp"
    timeoutMs?: number
  } = {},
): Promise<T> {
  const namespace = options.namespace ?? "lnkup"
  const base = namespace === "wp" ? `${getWpBaseUrl()}/wp-json/wp/v2` : getWpJsonBase()
  const url = new URL(`${base}${path.startsWith("/") ? path : `/${path}`}`)
  const hadAuth = hasAuthCredentials(options.auth)

  const headers: Record<string, string> = {
    Accept: "application/json",
  }

  if (hasAuthCredentials(options.auth) && options.auth) {
    headers.Authorization = authorizationHeader(options.auth)
  }

  const controller = new AbortController()
  const timeout = setTimeout(() => controller.abort(), options.timeoutMs ?? Math.max(30_000, getApiTimeoutMs()))

  try {
    const response = await fetch(url.toString(), {
      method: "POST",
      headers,
      body: formData,
      signal: controller.signal,
    })

    const contentType = response.headers.get("content-type") || ""
    const isJson = contentType.includes("application/json")
    const payload = isJson ? await response.json().catch(() => null) : null

    if (!response.ok || !isJson) {
      if (!isJson && response.status === 403) {
        throw new WpFetchError(
          "LNKup couldn't accept this request from your network. Try again on another connection.",
          "wordpress_unavailable",
          response.status,
        )
      }
      throwParsed(response.status, payload ?? { message: "Invalid response from LNKup" })
    }

    return payload as T
  } catch (error) {
    if (error instanceof WpFetchError) {
      notifyUnauthorized(hadAuth, error)
      throw error
    }
    if (error instanceof Error && error.name === "AbortError") {
      throw new WpFetchError("Request timed out", "wordpress_unavailable", 408)
    }
    throw new WpFetchError(
      error instanceof Error ? error.message : "Network request failed",
      "network_error",
      0,
    )
  } finally {
    clearTimeout(timeout)
  }
}

/**
 * Download a binary file from WordPress REST with Basic auth (documents, etc.).
 */
export async function wpDownloadFile(
  path: string,
  destFileUri: string,
  options: {
    auth?: WpAuth
    namespace?: "lnkup" | "wp"
  } = {},
): Promise<{ uri: string; mimeType?: string; status: number }> {
  try {
    const FileSystem = await import("expo-file-system/legacy")
    const namespace = options.namespace ?? "lnkup"
    const base = namespace === "wp" ? `${getWpBaseUrl()}/wp-json/wp/v2` : getWpJsonBase()
    const url = `${base}${path.startsWith("/") ? path : `/${path}`}`
    const hadAuth = hasAuthCredentials(options.auth)

    const headers: Record<string, string> = {}
    if (hasAuthCredentials(options.auth) && options.auth) {
      headers.Authorization = authorizationHeader(options.auth)
    }

    const result = await FileSystem.downloadAsync(url, destFileUri, { headers })
    if (result.status < 200 || result.status >= 300) {
      const error = new WpFetchError(
        result.status === 401 || result.status === 403
          ? "You don't have access to this file."
          : "Could not download file.",
        "download_failed",
        result.status,
      )
      notifyUnauthorized(hadAuth, error)
      throw error
    }
    return {
      uri: result.uri,
      mimeType: result.headers?.["Content-Type"] || result.headers?.["content-type"],
      status: result.status,
    }
  } catch (error) {
    if (error instanceof WpFetchError) throw error
    throw new WpFetchError(
      error instanceof Error ? error.message : "Download failed",
      "network_error",
      0,
    )
  }
}
