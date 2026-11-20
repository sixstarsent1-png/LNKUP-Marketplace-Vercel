import { NextResponse } from "next/server"

type HealthCheck = {
  name: string
  status: "pass" | "fail"
  detail?: string
}

function qaDisabled() {
  return process.env.ENABLE_QA_TOOLS !== "true"
}

async function probe(path: string, expectStatus: number): Promise<HealthCheck> {
  const origin =
    process.env.VERCEL_URL && !process.env.VERCEL_URL.startsWith("http")
      ? `https://${process.env.VERCEL_URL}`
      : process.env.PLAYWRIGHT_APP_URL || "http://127.0.0.1:3000"

  try {
    const res = await fetch(`${origin}${path}`, { cache: "no-store" })
    const pass = res.status === expectStatus
    return {
      name: `${path} → ${expectStatus}`,
      status: pass ? "pass" : "fail",
      detail: `got ${res.status}`,
    }
  } catch (error) {
    return {
      name: `${path} → ${expectStatus}`,
      status: "fail",
      detail: error instanceof Error ? error.message : "fetch failed",
    }
  }
}

export async function GET() {
  if (qaDisabled()) {
    return NextResponse.json({ code: "not_found", message: "Not found" }, { status: 404 })
  }

  const checks = await Promise.all([
    probe("/api/vendor/requests", 401),
    probe("/api/organizer/home", 401),
    probe("/api/marketplace/search", 200),
    probe("/api/auth/me", 200),
  ])

  const allPass = checks.every((c) => c.status === "pass")

  return NextResponse.json({
    ok: allPass,
    checks,
    timestamp: new Date().toISOString(),
  })
}
