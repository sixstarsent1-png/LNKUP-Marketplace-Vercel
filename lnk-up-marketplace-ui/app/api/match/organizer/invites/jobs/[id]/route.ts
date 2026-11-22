import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { mapInviteJob, type WpInviteJobResponse } from "@/lib/api/match"

export async function GET(_request: Request, { params }: { params: Promise<{ id: string }> }) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view invite progress.")
    }
    const { id } = await params
    if (!/^\d+$/.test(id)) {
      return NextResponse.json({ code: "invalid_job", message: "Invite job not found." }, { status: 404 })
    }
    const data = await wpFetchJson<WpInviteJobResponse>(`/match/organizer/invites/jobs/${id}`, {
      auth: toWpAuth(session),
    })
    return NextResponse.json({ job: mapInviteJob(data) })
  } catch (error) {
    return toErrorResponse(error, {
      context: "match/organizer/invite-job",
      fallbackMessage: "We couldn't load invite progress.",
      fallbackCode: "match_invite_job_failed",
    })
  }
}
