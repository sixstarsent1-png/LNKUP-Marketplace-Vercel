import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { requireAdminSession } from "@/lib/admin/access"
import { getMockVerificationItems, handoffUrls } from "@/lib/admin/queues"
import { toWpAuth } from "@/lib/auth/session"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

type Params = { params: Promise<{ id: string }> }

export async function GET(_request: Request, { params }: Params) {
  if (!isOpsAnalyticsEnabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const access = await requireAdminSession()
  if (!access.ok) return access.response

  try {
    const { id } = await params
    const documentId = Number(id)
    if (!documentId) {
      return NextResponse.json({ code: "not_found", message: "Document not found" }, { status: 404 })
    }

    const handoff = handoffUrls()

    if (!isLiveApiEnabled()) {
      const item = getMockVerificationItems().find((row) => row.documentId === documentId)
      if (!item) {
        return NextResponse.json({ code: "not_found", message: "Document not found" }, { status: 404 })
      }
      return NextResponse.json({
        code: "ok",
        item,
        actions: ["approve", "reject"],
        handoffUrl: handoff.verificationUrl,
      })
    }

    const wpAuth = toWpAuth(access.session)
    const queue = await wpFetchJson<{ items?: Array<Record<string, unknown>> }>(
      "/verification/admin/queue",
      { auth: wpAuth },
    ).catch(() => null)

    const raw = (queue?.items ?? []).find(
      (row) => Number(row.document_id ?? row.id ?? 0) === documentId,
    )

    if (!raw) {
      return NextResponse.json(
        {
          code: "handoff_required",
          message: "Open WordPress Verification for this document.",
          handoffUrl: `${handoff.verificationUrl}&tab=documents`,
        },
        { status: 404 },
      )
    }

    return NextResponse.json({
      code: "ok",
      item: {
        id: documentId,
        documentId,
        vendorUserId: raw.vendor_user_id ? Number(raw.vendor_user_id) : undefined,
        vendorProfileId: raw.vendor_profile_id ? Number(raw.vendor_profile_id) : undefined,
        vendorName: String(raw.vendor_name ?? "Vendor"),
        documentType: String(raw.document_type ?? ""),
        documentTypeLabel: String(raw.document_type_label ?? raw.document_type ?? "Document"),
        status: String(raw.status ?? "pending"),
        uploadedAt: raw.uploaded_at ? String(raw.uploaded_at) : undefined,
      },
      actions: ["approve", "reject"],
      handoffUrl: `${handoff.verificationUrl}&tab=documents`,
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "admin_verification_detail",
      fallbackMessage: "We couldn't load this verification item.",
      forbiddenMessage: "This section is for admins.",
    })
  }
}
