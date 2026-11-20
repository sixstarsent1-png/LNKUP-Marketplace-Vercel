import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  mapOrganizerRecruit,
  mockOrganizerRecruit,
  mockOrganizerRecruitUnavailable,
} from "@/lib/api/organizer-recruit-referrals"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { isLiveApiEnabled } from "@/lib/api/config"

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json(mockOrganizerRecruit)
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view Referral Invites.")
    }

    try {
      const data = await wpFetchJson("/referrals/organizer-recruit", {
        auth: toWpAuth(session),
      })
      return NextResponse.json(mapOrganizerRecruit(data as never))
    } catch {
      return NextResponse.json(mockOrganizerRecruitUnavailable)
    }
  } catch (error) {
    return toErrorResponse(error, {
      context: "referrals/organizer-recruit",
      fallbackMessage: "We couldn't load Referral Invites.",
      fallbackCode: "organizer_recruit_failed",
    })
  }
}
