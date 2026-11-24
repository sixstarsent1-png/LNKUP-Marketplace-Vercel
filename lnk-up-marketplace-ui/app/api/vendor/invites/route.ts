import { NextResponse } from "next/server"
import { isLiveApiEnabled } from "@/lib/api/config"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { mapVendorInvite, type WpVendorInvite } from "@/lib/api/vendor-invites"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET(request: Request) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view invitations.")
    }
    if (!session.canAccessVendor) {
      return NextResponse.json({ code: "forbidden", message: "Invitations are for vendor accounts." }, { status: 403 })
    }
    if (!isLiveApiEnabled()) {
      return NextResponse.json({ items: [] })
    }
    const scope = new URL(request.url).searchParams.get("scope") === "all" ? "all" : "open"
    const data = await wpFetchJson<{ items?: WpVendorInvite[] }>("/vendor/invites", { auth: toWpAuth(session), query: { scope } })
    return NextResponse.json({ items: (data.items ?? []).map(mapVendorInvite).filter((invite) => invite.id > 0) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/invites",
      fallbackMessage: "We couldn't load your invitations.",
      fallbackCode: "vendor_invites_failed",
    })
  }
}
