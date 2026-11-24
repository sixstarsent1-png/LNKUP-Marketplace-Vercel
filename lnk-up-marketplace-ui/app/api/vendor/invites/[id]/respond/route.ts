import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { opportunityFromApplyUrl } from "@/lib/api/vendor-invites"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function POST(request: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to respond to invitations.")
    }
    if (!session.canAccessVendor) {
      return NextResponse.json({ code: "forbidden", message: "Invitations are for vendor accounts." }, { status: 403 })
    }
    const { id } = await params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "invalid_invite", message: "That invitation could not be found." }, { status: 400 })
    }
    const body = (await request.json().catch(() => ({}))) as { accept?: boolean }
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ ok: true, opportunityId: null })
    }
    const data = await wpFetchJson<{ success?: boolean; apply_url?: string }>(`/vendor/invites/${id}/respond`, {
      method: "POST",
      auth: toWpAuth(session),
      body: { accept: body.accept === true },
    })
    return NextResponse.json({ ok: data.success !== false, opportunityId: opportunityFromApplyUrl(data.apply_url) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/invites/respond",
      fallbackMessage: "We couldn't update this invitation.",
      fallbackCode: "vendor_invite_respond_failed",
      forbiddenMessage: "This invitation belongs to another account.",
    })
  }
}
