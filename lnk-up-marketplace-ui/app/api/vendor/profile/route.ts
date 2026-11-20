import { NextResponse } from "next/server"
import { getVendorPublicProfileUrl, isLiveApiEnabled } from "@/lib/api/config"
import { wpFetchJson } from "@/lib/api/wp-fetch"
import { authRequiredResponse, toErrorResponse } from "@/lib/api/normalize-wp-error"
import { getSession, toWpAuth } from "@/lib/auth/session"
import {
  mapVendorProfile,
  mockVendorProfileEdit,
  toWpVendorProfilePatch,
  type VendorProfileEdit,
  type WpVendorProfile,
} from "@/lib/api/vendor-profile"

function toResponse(profile: VendorProfileEdit) {
  return {
    ...profile,
    profile_id: profile.profileId,
    business_name: profile.businessName,
    contact_email: profile.contactEmail,
    contact_phone: profile.contactPhone,
    public_slug: profile.publicSlug,
    public_url: profile.publicUrl,
  }
}

export async function GET() {
  try {
    if (!isLiveApiEnabled()) {
      const mock = mockVendorProfileEdit()
      return NextResponse.json(toResponse(mock))
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to view your profile.")
    }

    const data = await wpFetchJson<WpVendorProfile>("/vendor/profile", {
      auth: toWpAuth(session),
    })
    const slug = data.public_slug || ""
    const profile = mapVendorProfile(data, slug ? getVendorPublicProfileUrl(slug) : "")
    return NextResponse.json(toResponse(profile))
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/profile",
      fallbackMessage: "Could not load vendor profile.",
      fallbackCode: "vendor_profile_failed",
    })
  }
}

export async function PATCH(request: Request) {
  try {
    const body = (await request.json().catch(() => ({}))) as Partial<VendorProfileEdit> & {
      business_name?: string
      contact_email?: string
      contact_phone?: string
    }
    const patch = toWpVendorProfilePatch({
      businessName: body.businessName ?? body.business_name,
      description: body.description,
      website: body.website,
      instagram: body.instagram,
      contactEmail: body.contactEmail ?? body.contact_email,
      contactPhone: body.contactPhone ?? body.contact_phone,
      city: body.city,
      state: body.state,
    })

    if (Object.keys(patch).length === 0) {
      return NextResponse.json(
        { code: "invalid_body", message: "No profile fields to update." },
        { status: 400 },
      )
    }

    if (!isLiveApiEnabled()) {
      return NextResponse.json({
        success: true,
        profile_id: 1,
        message: "Profile saved (demo).",
      })
    }

    const session = await getSession()
    if (!session) {
      return authRequiredResponse("Please sign in to update your profile.")
    }

    const result = await wpFetchJson<{ success?: boolean; profile_id?: number; message?: string }>(
      "/vendor/profile",
      {
        method: "POST",
        auth: toWpAuth(session),
        body: patch,
      },
    )

    return NextResponse.json({
      success: result.success !== false,
      profile_id: result.profile_id ?? 0,
      message: result.message ?? "Profile saved.",
    })
  } catch (error) {
    return toErrorResponse(error, {
      context: "vendor/profile/patch",
      fallbackMessage: "Could not save your profile.",
      fallbackCode: "vendor_profile_save_failed",
    })
  }
}

export async function POST(request: Request) {
  return PATCH(request)
}

export async function PUT(request: Request) {
  return PATCH(request)
}
