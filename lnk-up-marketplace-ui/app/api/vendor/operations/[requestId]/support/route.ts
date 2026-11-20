import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import {
  buildSupportHandoff,
  mapWpSupportItem,
  mockSupportHistory,
  supportTopicToWpCategory,
  type VendorSupportSubmitResult,
  type VendorSupportTopic,
} from "@/lib/api/vendor-support"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ requestId: string }> }

export async function GET(_request: Request, context: RouteContext) {
  try {
    const { requestId } = await context.params

    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: mockSupportHistory(requestId) })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view support history.")
    }

    const auth = toWpAuth(session)
    const data = await wpFetchJson<{ items?: unknown[] }>(
      `/operations/applications/${requestId}/support`,
      { auth },
    )

    const items = (data.items ?? []).map((item) =>
      mapWpSupportItem(requestId, item as Parameters<typeof mapWpSupportItem>[1]),
    )

    return NextResponse.json({ items })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/operations/support",
      fallbackMessage: "We couldn't load support history. Please try again.",
      fallbackCode: "support_failed",
      forbiddenMessage: "You don't have access to support for this event.",
    })
  }
}

export async function POST(request: Request, context: RouteContext) {
  try {
    const { requestId } = await context.params
    const body = (await request.json()) as {
      topic?: VendorSupportTopic
      message?: string
      priority?: string
    }

    const message = (body.message ?? "").trim()
    if (!message) {
      return NextResponse.json(
        { code: "validation_error", message: "Please enter a message." },
        { status: 400 },
      )
    }

    const topic = body.topic ?? "general"

    if (!isLiveApiEnabled()) {
      const item = {
        id: `sup-${Date.now()}`,
        requestId,
        topic,
        message,
        status: "open" as const,
        createdAt: new Date().toLocaleDateString("en-US", {
          month: "short",
          day: "numeric",
          year: "numeric",
        }),
      }
      const result: VendorSupportSubmitResult = { status: "submitted", item }
      return NextResponse.json(result)
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to submit a support request.")
    }

    const auth = toWpAuth(session)

    try {
      const data = await wpFetchJson<{ id?: number }>("/operations/support", {
        method: "POST",
        auth,
        body: {
          application_id: requestId,
          category: supportTopicToWpCategory(topic),
          message,
          priority: body.priority ?? "normal",
        },
      })

      const item = mapWpSupportItem(requestId, {
        id: data.id,
        application_id: Number(requestId),
        category: supportTopicToWpCategory(topic),
        message,
        status: "open",
        created_at: new Date().toISOString(),
      })

      return NextResponse.json({ status: "submitted", item } satisfies VendorSupportSubmitResult)
    } catch (error) {
      if (error instanceof WpFetchError && error.status === 404) {
        return NextResponse.json(buildSupportHandoff())
      }
      throw error
    }
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/operations/support",
      fallbackMessage: "We couldn't submit your support request. Please try again.",
      fallbackCode: "support_submit_failed",
      forbiddenMessage: "You don't have access to submit support for this event.",
    })
  }
}
