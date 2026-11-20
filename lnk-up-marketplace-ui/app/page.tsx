import Link from "next/link"
import { Store, CalendarDays, LayoutDashboard, Zap, ArrowRight } from "lucide-react"
import { getWpRegistrationUrl } from "@/lib/api/config"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

const roles = [
  {
    href: "/vendor",
    label: "Vendor",
    description: "Find events, apply for free, pay only when accepted.",
    icon: Store,
  },
  {
    href: "/organizer",
    label: "Organizer",
    description: "Launch events, approve vendors, and collect vendor fees.",
    icon: CalendarDays,
  },
  {
    href: "/admin",
    label: "Admin",
    description: "Platform overview, users, and marketplace health.",
    icon: LayoutDashboard,
  },
] as const

export default function RolePickerPage() {
  const showAdminEntry = isOpsAnalyticsEnabled()
  const workspaceRoles = showAdminEntry ? roles : roles.filter((role) => role.href !== "/admin")
  const vendorSignupUrl = getWpRegistrationUrl({
    role: "vendor",
    redirect: "/vendor/onboarding",
  })
  const organizerSignupUrl = getWpRegistrationUrl({
    role: "organizer",
    redirect: "/organizer/onboarding",
  })

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-12">
      <div className="flex w-full max-w-md flex-col items-center gap-8">
        <div className="flex flex-col items-center gap-3 text-center">
          <span className="flex size-12 items-center justify-center rounded-2xl bg-primary">
            <Zap className="size-6 text-accent" fill="currentColor" />
          </span>
          <h1 className="font-heading text-3xl font-bold tracking-tight">LNKup</h1>
          <p className="text-pretty text-sm text-muted-foreground">
            Vendor opportunity marketplace for live events. Choose your workspace to continue.
          </p>
        </div>

        <Card
          className="w-full rounded-2xl border-accent/40 bg-accent/5 transition-colors hover:bg-accent/10"
          data-testid="home-vendor-cta"
        >
          <CardContent className="flex flex-col gap-3 p-4">
            <div className="flex items-start gap-3">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent">
                <Store className="size-5 text-accent-foreground" />
              </span>
              <div className="flex flex-1 flex-col gap-1 text-left">
                <span className="font-heading font-semibold">For vendors</span>
                <span className="text-xs text-muted-foreground">
                  Find events, apply for free, and pay only when accepted.
                </span>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button nativeButton={false} render={<Link href="/vendors" />}>
                Learn more
              </Button>
              <Button
                variant="outline"
                nativeButton={false}
                render={<a href={vendorSignupUrl} />}
              >
                Join as a Vendor
                <ArrowRight data-icon="inline-end" />
              </Button>
            </div>
          </CardContent>
        </Card>

        <Card
          className="w-full rounded-2xl border-accent/40 bg-accent/5 transition-colors hover:bg-accent/10"
          data-testid="home-organizer-cta"
        >
          <CardContent className="flex flex-col gap-3 p-4">
            <div className="flex items-start gap-3">
              <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent">
                <CalendarDays className="size-5 text-accent-foreground" />
              </span>
              <div className="flex flex-1 flex-col gap-1 text-left">
                <span className="font-heading font-semibold">For organizers</span>
                <span className="text-xs text-muted-foreground">
                  Post vendor opportunities, review requests, and manage event logistics.
                </span>
              </div>
            </div>
            <div className="flex flex-wrap gap-2">
              <Button nativeButton={false} render={<Link href="/organizers" />}>
                Learn more
              </Button>
              <Button
                variant="outline"
                nativeButton={false}
                render={<a href={organizerSignupUrl} />}
              >
                Join as an Organizer
                <ArrowRight data-icon="inline-end" />
              </Button>
            </div>
          </CardContent>
        </Card>

        <div className="flex w-full flex-col gap-3">
          {workspaceRoles.map((role) => (
            <Card key={role.href} className="rounded-2xl transition-colors hover:bg-muted/40">
              <CardContent className="flex items-center gap-4 p-4">
                <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-accent">
                  <role.icon className="size-5 text-accent-foreground" />
                </span>
                <div className="flex flex-1 flex-col gap-0.5 text-left">
                  <span className="font-heading font-semibold">{role.label}</span>
                  <span className="text-xs text-muted-foreground">{role.description}</span>
                </div>
                <Button nativeButton={false} render={<Link href={role.href} />}>
                  Open
                </Button>
              </CardContent>
            </Card>
          ))}
        </div>

        <p className="text-center text-xs text-muted-foreground">
          Vendor marketplace only — not attendee ticketing.
        </p>
      </div>
    </div>
  )
}
