import { NextResponse } from "next/server"
import { sessionToAppUser } from "@/lib/auth/app-user"
import { getSession } from "@/lib/auth/session"

export async function GET() {
  const session = await getSession()
  if (!session) {
    return NextResponse.json({ authenticated: false })
  }

  const user = sessionToAppUser(session)

  return NextResponse.json({
    authenticated: true,
    user: {
      id: user.id ? Number(user.id) : session.userId,
      name: user.name ?? session.username,
      username: session.username,
      email: user.email,
      roles: user.roles,
      primaryRole: user.primaryRole,
      canAccessVendor: user.canAccessVendor,
      canAccessOrganizer: user.canAccessOrganizer,
      canAccessAdmin: user.canAccessAdmin,
    },
  })
}
