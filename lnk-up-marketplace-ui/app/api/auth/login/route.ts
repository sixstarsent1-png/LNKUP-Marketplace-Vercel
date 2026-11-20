import { NextResponse } from "next/server"
import { WpFetchError, wpValidateAuth } from "@/lib/api/wp-fetch"
import { toErrorResponse } from "@/lib/api/normalize-wp-error"
import { fetchWpUserProfile } from "@/lib/auth/app-user"
import { setSession } from "@/lib/auth/session"

export async function POST(request: Request) {
  try {
    const body = await request.json()
    const username = String(body.username ?? "").trim()
    const appPassword = String(body.appPassword ?? body.app_password ?? "").trim()

    if (!username || !appPassword) {
      return NextResponse.json(
        { code: "validation_error", message: "Username and application password are required." },
        { status: 400 },
      )
    }

    const user = await wpValidateAuth({ username, appPassword })
    const profile = await fetchWpUserProfile({ username, appPassword })

    await setSession({
      username,
      appPassword,
      displayName: user.name,
      userId: user.id,
      roles: profile.roles,
      primaryRole: profile.primaryRole,
      canAccessVendor: profile.canAccessVendor,
      canAccessOrganizer: profile.canAccessOrganizer,
      canAccessAdmin: profile.canAccessAdmin,
    })

    return NextResponse.json({
      ok: true,
      user: {
        id: user.id,
        name: user.name,
        slug: user.slug,
        roles: profile.roles,
        primaryRole: profile.primaryRole,
        canAccessVendor: profile.canAccessVendor,
        canAccessOrganizer: profile.canAccessOrganizer,
        canAccessAdmin: profile.canAccessAdmin,
      },
    })
  } catch (error) {
    if (error instanceof WpFetchError) {
      const status = error.status === 401 || error.status === 403 ? 401 : error.status
      return NextResponse.json(
        { code: "auth_required", message: "Invalid username or application password." },
        { status: status || 401 },
      )
    }
    return toErrorResponse(error, {
      context: "auth/login",
      fallbackMessage: "Login failed. Please try again.",
      fallbackCode: "login_failed",
    })
  }
}
