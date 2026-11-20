import Link from "next/link"
import { isLiveApiEnabled } from "@/lib/api/config"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Badge } from "@/components/ui/badge"

const ROUTE_CHECKLIST = [
  { href: "/vendor", label: "Vendor home" },
  { href: "/vendor/find", label: "Find events" },
  { href: "/vendor/requests", label: "Vendor requests" },
  { href: "/vendor/profile", label: "Vendor profile" },
  { href: "/vendor/favorites", label: "Favorites" },
  { href: "/vendor/wallet", label: "Wallet" },
  { href: "/organizer", label: "Organizer home" },
  { href: "/organizer/events", label: "Organizer events" },
  { href: "/organizer/vendors", label: "Organizer vendors" },
  { href: "/organizer/money", label: "Organizer money" },
  { href: "/login", label: "Login" },
  { href: "/offline", label: "Offline page" },
]

async function fetchHealth() {
  const base = process.env.PLAYWRIGHT_APP_URL || process.env.VERCEL_URL
    ? `https://${process.env.VERCEL_URL}`
    : "http://localhost:3000"

  try {
    const res = await fetch(`${base}/api/qa/health`, { cache: "no-store" })
    if (!res.ok) return null
    return res.json()
  } catch {
    return null
  }
}

export default async function QaPage() {
  const envFlags = {
    liveApi: isLiveApiEnabled(),
    wordpressApiUrl: Boolean(process.env.WORDPRESS_API_URL),
    publicWpSite: Boolean(process.env.NEXT_PUBLIC_WORDPRESS_SITE_URL),
  }

  const health = await fetchHealth()

  return (
    <div className="mx-auto max-w-3xl px-4 py-8">
      <PageHeader
        title="Launch QA dashboard"
        subtitle="Route checks, environment flags, and BFF health probes."
      />

      <div className="mt-8 flex flex-col gap-6">
        <Card>
          <CardHeader>
            <CardTitle>Launch analytics</CardTitle>
          </CardHeader>
          <CardContent>
            <Link
              href="/ops/analytics"
              className="text-sm font-medium text-foreground underline underline-offset-2"
            >
              Open launch command center
            </Link>
            <p className="mt-2 text-sm text-muted-foreground">
              Funnel, vendor activation, search insights, and BFF health (requires ENABLE_QA_TOOLS or
              ENABLE_OPS_ANALYTICS).
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Environment</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-2 text-sm">
            <div className="flex items-center justify-between">
              <span>NEXT_PUBLIC_LNKUP_LIVE_API</span>
              <Badge variant={envFlags.liveApi ? "default" : "secondary"}>
                {envFlags.liveApi ? "true" : "false"}
              </Badge>
            </div>
            <div className="flex items-center justify-between">
              <span>WORDPRESS_API_URL</span>
              <Badge variant={envFlags.wordpressApiUrl ? "default" : "destructive"}>
                {envFlags.wordpressApiUrl ? "set" : "missing"}
              </Badge>
            </div>
            <div className="flex items-center justify-between">
              <span>NEXT_PUBLIC_WORDPRESS_SITE_URL</span>
              <Badge variant={envFlags.publicWpSite ? "default" : "destructive"}>
                {envFlags.publicWpSite ? "set" : "missing"}
              </Badge>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Route checklist</CardTitle>
          </CardHeader>
          <CardContent>
            <ul className="grid gap-2 text-sm sm:grid-cols-2">
              {ROUTE_CHECKLIST.map((route) => (
                <li key={route.href}>
                  <Link href={route.href} className="text-foreground underline underline-offset-2">
                    {route.label}
                  </Link>
                  <span className="ml-2 text-muted-foreground">({route.href})</span>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>BFF health</CardTitle>
          </CardHeader>
          <CardContent>
            {health ? (
              <ul className="flex flex-col gap-2 text-sm">
                {(health.checks as { name: string; status: string; detail?: string }[]).map(
                  (check) => (
                    <li key={check.name} className="flex items-center justify-between gap-4">
                      <span>{check.name}</span>
                      <Badge variant={check.status === "pass" ? "default" : "destructive"}>
                        {check.status}
                      </Badge>
                    </li>
                  ),
                )}
              </ul>
            ) : (
              <p className="text-sm text-muted-foreground">
                Health probe unavailable. Ensure the app is running and ENABLE_QA_TOOLS=true.
              </p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
