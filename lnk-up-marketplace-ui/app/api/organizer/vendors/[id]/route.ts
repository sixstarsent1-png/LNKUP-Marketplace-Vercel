import { NextResponse } from "next/server"
import { mapOrganizerVendorTimeline, mapOrganizerVendorNotes } from "@/lib/api/organizer-crm"
import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import { mapOrganizerVendorProfile } from "@/lib/api/organizer-vendor-profile"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"

type RouteContext = { params: Promise<{ id: string }> }

type VendorProfileIntel = NonNullable<Parameters<typeof mapOrganizerVendorProfile>[2]>

export async function GET(request: Request, context: RouteContext) {
  try {
    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view vendor profiles.")
    }

    const { id } = await context.params
    const auth = toWpAuth(session)
    const url = new URL(request.url)
    const applicationId = url.searchParams.get("applicationId")

    const profile = await wpFetchJson<Record<string, unknown>>(`/crm/vendors/${id}`, { auth })

    let intel: VendorProfileIntel | undefined
    if (applicationId) {
      try {
        const intelData = await wpFetchJson<VendorProfileIntel & { profile?: { flags?: unknown[] } }>(
          `/crm/applications/${applicationId}/intel`,
          { auth },
        )
        intel = {
          ...intelData,
          flags: intelData.flags ?? intelData.profile?.flags,
        }
      } catch {
        intel = undefined
      }
    }

    const [timelineRaw, notesRaw] = await Promise.all([
      wpFetchJson<Record<string, unknown>>(`/crm/vendors/${id}/timeline`, { auth }).catch(() => ({ items: [] })),
      wpFetchJson<Record<string, unknown>>(`/crm/vendors/${id}/notes`, { auth }).catch(() => ({ items: [] })),
    ])

    return NextResponse.json({
      code: "ok",
      profile: mapOrganizerVendorProfile(id, profile as never, intel),
      timeline: mapOrganizerVendorTimeline(timelineRaw),
      notes: mapOrganizerVendorNotes(notesRaw),
    })
  } catch (error) {
    if (error instanceof WpFetchError && error.status === 404) {
      return NextResponse.json(
        { code: "not_found", message: "We couldn't load this vendor profile." },
        { status: 404 },
      )
    }
    return toErrorResponse(error, {
      context: "organizer/vendors",
      fallbackMessage: "We couldn't load this vendor profile. Please try again.",
      fallbackCode: "vendor_profile_failed",
      forbiddenMessage: "This section is for organizers.",
    })
  }
}
