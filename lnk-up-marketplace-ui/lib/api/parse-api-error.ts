import { appErrorFromBff, normalizeErrorCode, type AppErrorShape, type BffErrorBody } from "@/lib/api/app-error"

export async function parseApiResponse<T>(response: Response): Promise<{
  data: T | null
  error: AppErrorShape | null
}> {
  const contentType = response.headers.get("content-type") || ""
  const isJson = contentType.includes("application/json")

  if (!isJson) {
    return {
      data: null,
      error: {
        code: "invalid_response",
        title: "Something went wrong",
        message: "We couldn't load this section.",
        retryable: true,
        status: response.status,
      },
    }
  }

  let body: BffErrorBody & Record<string, unknown>
  try {
    body = (await response.json()) as BffErrorBody & Record<string, unknown>
  } catch {
    return {
      data: null,
      error: {
        code: "invalid_response",
        title: "Something went wrong",
        message: "We couldn't read the server response.",
        retryable: true,
        status: response.status,
      },
    }
  }

  if (!response.ok) {
    return {
      data: null,
      error: appErrorFromBff(
        { code: body.code ?? "unknown", message: body.message ?? "Request failed" },
        response.status,
      ),
    }
  }

  return { data: body as T, error: null }
}

export function isAuthRequired(error: AppErrorShape | null): boolean {
  return error?.code === "auth_required" || normalizeErrorCode(error?.code) === "auth_required"
}

export function isForbidden(error: AppErrorShape | null): boolean {
  return error?.code === "forbidden"
}
