import { NextResponse } from "next/server"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import { isLiveApiEnabled } from "@/lib/api/config"

export async function POST() {
  try {
    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        code: "ORGDEMO123",
        share_url: "https://thelnkup.com/sign-up/?org_ref=ORGDEMO123&interest=organizer",
        share_url_organizer:
          "https://thelnkup.com/sign-up/?org_ref=ORGDEMO123&interest=organizer",
        share_url_vendor: "https://thelnkup.com/sign-up/?org_ref=ORGDEMO123&interest=vendor",
        program: "organizer_recruit",
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to create an invite code.")
    }

    const data = await wpFetchJson("/referrals/organizer-recruit/code", {
      method: "POST",
      auth: toWpAuth(session),
      body: {},
    })
    return NextResponse.json(data)
  } catch (error) {
    return toErrorResponse(error, {
      context: "referrals/organizer-recruit/code",
      fallbackMessage: "We couldn't create an invite code.",
      fallbackCode: "organizer_recruit_code_failed",
    })
  }
}
