import { authRequiredResponse } from "@/lib/api/normalize-wp-error"
import { getSession } from "@/lib/auth/session"

export async function requireAdminSession() {
  const session = await getSession()
  if (!session) {
    return { ok: false as const, response: authRequiredResponse("Sign in as an admin to continue.") }
  }

  const hasAdminAccess =
    session.canAccessAdmin === true ||
    (session.roles ?? []).includes("admin")

  if (!hasAdminAccess) {
    return {
      ok: false as const,
      response: Response.json(
        { code: "forbidden", message: "This section is for admins." },
        { status: 403 },
      ),
    }
  }

  return { ok: true as const, session }
}

