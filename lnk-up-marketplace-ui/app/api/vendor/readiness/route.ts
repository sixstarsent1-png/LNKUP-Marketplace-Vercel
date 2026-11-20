import { NextResponse } from "next/server"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { mapVendorReadiness } from "@/lib/api/vendor-readiness"
import { mapProfileChecklist } from "@/lib/api/profile-checklist"
import type { WpProfileStrength, WpVerificationVendor } from "@/lib/api/types"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

export async function GET() {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your readiness details.")
    }

    const auth = toWpAuth(session)
    const verification = await wpFetchJson<WpVerificationVendor>("/verification/vendor", { auth })

    let strength: WpProfileStrength | undefined
    try {
      strength = await wpFetchJson<WpProfileStrength>("/growth/profile-strength", { auth })
    } catch {
      strength = undefined
    }

    const readiness = mapVendorReadiness(verification, strength)
    return NextResponse.json({
      code: "ok",
      ...readiness,
      profileChecklist: mapProfileChecklist(strength),
    })
  } catch (error) {
    if (error instanceof WpFetchError && error.status === 404) {
      return NextResponse.json(
        { code: "no_profile", message: "Complete your vendor profile to get started." },
        { status: 404 },
      )
    }
    return toErrorResponse(error, {
      context: "vendor/readiness",
      fallbackMessage: "We couldn't load your readiness details.",
      fallbackCode: "readiness_failed",
    })
  }
}
