import Link from "next/link"
import { Store, CalendarDays, LayoutDashboard, Zap, ExternalLink } from "lucide-react"

const roles = [
  { href: "/dashboard", label: "Vendor", icon: Store },
  { href: "/organizer", label: "Organizer", icon: CalendarDays },
  { href: "/admin", label: "Admin", icon: LayoutDashboard },
] as const

export default function HomePage() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center bg-background px-4 py-12">
      <div className="flex w-full max-w-md flex-col items-center gap-8">
        <div className="flex flex-col items-center gap-3 text-center">
          <span className="flex size-12 items-center justify-center rounded-2xl bg-foreground">
            <Zap className="size-6 text-lime-400" fill="currentColor" />
          </span>
          <h1 className="text-3xl font-bold tracking-tight">LNKup</h1>
          <p className="text-sm text-muted-foreground">
            Legacy reference UI. For the active app shell, run{" "}
            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">lnk-up-marketplace-ui</code>.
          </p>
        </div>

        <div className="flex w-full flex-col gap-2">
          {roles.map((role) => (
            <Link
              key={role.href}
              href={role.href}
              className="flex items-center gap-3 rounded-2xl border bg-card p-4 transition-colors hover:bg-muted"
            >
              <role.icon className="size-5" />
              <span className="flex-1 font-medium">{role.label}</span>
              <span className="text-xs text-muted-foreground">Open</span>
            </Link>
          ))}
        </div>

        <p className="flex items-center gap-1 text-xs text-muted-foreground">
          <ExternalLink className="size-3" />
          Canonical app: <code className="text-xs">cd lnk-up-marketplace-ui && pnpm dev</code>
        </p>
      </div>
    </div>
  )
}
