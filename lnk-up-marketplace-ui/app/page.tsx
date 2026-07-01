import Link from "next/link"
import { Store, CalendarDays, LayoutDashboard, Zap } from "lucide-react"
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

        <div className="flex w-full flex-col gap-3">
          {roles.map((role) => (
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
