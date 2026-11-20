"use client"

import { Suspense, useState, useEffect } from "react"
import Link from "next/link"
import { useRouter, useSearchParams } from "next/navigation"
import { Zap, Loader2 } from "lucide-react"
import { getPublicWpSiteUrl, getVendorLoginUrl, getWpRegistrationUrl } from "@/lib/api/config"
import { readLastUsedRole, resolvePostLoginPath, writeLastUsedRole } from "@/lib/auth/app-user"
import type { AppSessionUser } from "@/lib/auth/app-user"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Card, CardContent } from "@/components/ui/card"

function LoginForm() {
  const router = useRouter()
  const searchParams = useSearchParams()
  const intent = searchParams.get("intent")
  const browseFirst = searchParams.get("browse") === "1"
  const redirectParam = searchParams.get("redirect")
  const isOrganizerIntent = intent === "organizer" || (redirectParam?.startsWith("/organizer") ?? false)
  const defaultRedirect = isOrganizerIntent ? "/organizer" : intent === "vendor" ? "/vendor/onboarding" : "/vendor/find"
  const redirect = redirectParam || defaultRedirect
  const [username, setUsername] = useState("")
  const [appPassword, setAppPassword] = useState("")
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)

  const wpSite = getPublicWpSiteUrl()
  const profileUrl = wpSite ? `${wpSite}/wp-admin/profile.php` : null
  const registrationUrl = getWpRegistrationUrl({
    role: isOrganizerIntent ? "organizer" : "vendor",
    redirect,
  })

  useEffect(() => {
    if (browseFirst) {
      router.replace("/vendor/find")
    }
  }, [browseFirst, router])

  if (browseFirst) {
    return <div className="p-8 text-center text-muted-foreground">Redirecting…</div>
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault()
    setLoading(true)
    setError(null)
    trackLnkupEvent({
      event: "login_started",
      source: isOrganizerIntent ? "organizer" : intent === "vendor" ? "vendor" : "general",
    })

    try {
      const response = await fetch("/api/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username, appPassword }),
      })
      const data = await response.json()
      if (!response.ok) {
        throw new Error(data.message || "Login failed")
      }
      const profile: AppSessionUser = {
        id: data.user?.id ? String(data.user.id) : undefined,
        name: data.user?.name,
        roles: data.user?.roles ?? [],
        primaryRole: data.user?.primaryRole ?? "unknown",
        canAccessVendor: data.user?.canAccessVendor,
        canAccessOrganizer: data.user?.canAccessOrganizer,
        canAccessAdmin: data.user?.canAccessAdmin,
      }
      const destination = resolvePostLoginPath(profile, redirectParam, {
        intent,
        lastRole: readLastUsedRole(),
      })
      writeLastUsedRole(destination.startsWith("/organizer") ? "organizer" : "vendor")
      router.push(destination)
      router.refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : "Login failed")
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-12">
      <div className="flex w-full max-w-md flex-col gap-8">
        <div className="flex flex-col items-center gap-3 text-center">
          <Link href="/" className="flex items-center gap-2">
            <span className="flex size-10 items-center justify-center rounded-xl bg-primary">
              <Zap className="size-5 text-accent" fill="currentColor" />
            </span>
          </Link>
          <PageHeader
            title="Sign in to LNKup"
            subtitle={
              isOrganizerIntent
                ? "Sign in as an organizer to manage events and vendor opportunities."
                : "Use your LNKup account credentials or app password to continue."
            }
          />
        </div>

        <Card className="rounded-2xl">
          <CardContent className="pt-6">
            <form onSubmit={handleSubmit} className="flex flex-col gap-4">
              <div className="flex flex-col gap-2">
                <Label htmlFor="username">Username or email</Label>
                <Input
                  id="username"
                  autoComplete="username"
                  value={username}
                  onChange={(e) => setUsername(e.target.value)}
                  required
                />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="app-password">Password</Label>
                <Input
                  id="app-password"
                  type="password"
                  autoComplete="current-password"
                  value={appPassword}
                  onChange={(e) => setAppPassword(e.target.value)}
                  required
                />
              </div>
              {error && (
                <p className="text-sm text-destructive" role="alert">
                  {error}
                </p>
              )}
              <Button type="submit" disabled={loading} className="w-full">
                {loading && <Loader2 className="animate-spin" data-icon="inline-start" />}
                Sign in
              </Button>
            </form>
          </CardContent>
        </Card>

        <div className="space-y-2 text-center text-sm text-muted-foreground">
          <p>
            {isOrganizerIntent ? "New organizer?" : "New vendor?"}{" "}
            <a href={registrationUrl} className="font-medium text-foreground underline">
              Create a free account
            </a>
            {isOrganizerIntent ? (
              <>
                {" · "}
                <Link href="/organizers" className="font-medium text-foreground underline">
                  Learn more
                </Link>
              </>
            ) : null}
          </p>
          <p>
            <Link href="/vendor/find" className="font-medium text-foreground underline">
              Browse first
            </Link>
            {" · "}
            <Link
              href={
                isOrganizerIntent
                  ? "/login?intent=organizer&redirect=/organizer"
                  : getVendorLoginUrl({ intent: "vendor" })
              }
              className="font-medium text-foreground underline"
            >
              {isOrganizerIntent ? "Organizer sign in" : "Vendor sign in"}
            </Link>
          </p>
          {profileUrl && (
            <p>
              Need a sign-in password? Create one in your{" "}
              <a href={profileUrl} className="font-medium text-foreground underline">
                LNKup profile
              </a>
              .
            </p>
          )}
          <p>
            <Link href="/" className="font-medium text-foreground underline">
              Back to role picker
            </Link>
          </p>
        </div>
      </div>
    </div>
  )
}

export default function LoginPage() {
  return (
    <Suspense fallback={<div className="p-8 text-center text-muted-foreground">Loading…</div>}>
      <LoginForm />
    </Suspense>
  )
}
