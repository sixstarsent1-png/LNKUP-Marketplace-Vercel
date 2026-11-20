import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import { handoffUrls } from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type Params = { params: Promise<{ id: string }> }
type ActionBody = { action?: "approve" | "reject"; notes?: string }

export async function POST(request: Request, { params }: Params) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const access = await requireAdminSession()
  if (!access.ok) return access.response

  try {
    const { id } = await params
    const documentId = Number(id)
    const body = (await request.json().catch(() => ({}))) as ActionBody
    const action = body.action

    if (!documentId || !action || !["approve", "reject"].includes(action)) {
      return NextResponse.json(
        { code: "admin_action_failed", message: "Provide action: approve or reject." },
        { status: 400 },
      )
    }

    const handoff = handoffUrls()
    const status = action === "approve" ? "approved" : "rejected"

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        code: "ok",
        mocked: true,
        success: true,
        action,
        documentId,
        status,
        message: `Mock ${action} succeeded.`,
      })
    }

    const wpAuth = toWpAuth(access.session)
    await wpFetchJson("/verification/admin/manual", {
      method: "POST",
      auth: wpAuth,
      body: {
        action: "document",
        document_id: documentId,
        status,
        notes: body.notes ?? "",
      },
    })

    return NextResponse.json({
      code: "ok",
      success: true,
      action,
      documentId,
      status,
      handoffUrl: `${handoff.verificationUrl}&tab=documents`,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_verification_action",
      fallbackMessage: "We couldn't update this document.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
