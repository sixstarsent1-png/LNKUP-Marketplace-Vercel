"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { Bell, ChevronsUpDown, Zap, Check } from "lucide-react"
import type { Role } from "@/lib/data"
import { roleNav, roleLabels, roleHome } from "@/lib/nav"
import { cn } from "@/lib/utils"
import { Button } from "@/components/ui/button"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"

const roles: Role[] = ["vendor", "organizer", "admin"]

function isActive(pathname: string, href: string) {
  if (href === roleHome.vendor || href === roleHome.organizer || href === roleHome.admin) {
    return pathname === href
  }
  return pathname === href || pathname.startsWith(href + "/")
}

const roleProfile: Record<Role, { name: string; meta: string; initials: string }> = {
  vendor: { name: "Sweet Heat BBQ", meta: "Charlotte, NC", initials: "SH" },
  organizer: { name: "CultureWave Events", meta: "Event organizer", initials: "CW" },
  admin: { name: "LNKup Admin", meta: "Platform team", initials: "LA" },
}

function Logo({ href, className }: { href: string; className?: string }) {
  return (
    <Link href={href} className={cn("flex items-center gap-2", className)}>
      <span className="flex size-8 items-center justify-center rounded-xl bg-primary">
        <Zap className="size-4 text-accent" fill="currentColor" />
      </span>
      <span className="font-heading text-lg font-bold tracking-tight">LNKup</span>
    </Link>
  )
}

function RoleSwitcher({ role }: { role: Role }) {
  const router = useRouter()
  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button variant="outline" size="sm" className="gap-1.5">
            <span className="size-1.5 rounded-full bg-accent" />
            {roleLabels[role]}
            <ChevronsUpDown data-icon="inline-end" className="text-muted-foreground" />
          </Button>
        }
      />
      <DropdownMenuContent align="end" className="w-48">
        <DropdownMenuLabel>Switch role (demo)</DropdownMenuLabel>
        <DropdownMenuGroup>
          {roles.map((r) => (
            <DropdownMenuItem key={r} onClick={() => router.push(roleHome[r])}>
              {roleLabels[r]}
              {r === role && <Check data-icon="inline-end" />}
            </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

export function AppShell({
  role,
  children,
}: {
  role: Role
  children: React.ReactNode
}) {
  const pathname = usePathname()
  const nav = roleNav[role]
  const profile = roleProfile[role]

  return (
    <div className="min-h-dvh bg-background md:flex">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-r bg-sidebar p-4 md:flex">
        <div className="flex items-center justify-between">
          <Logo href={roleHome[role]} />
        </div>
        <div className="mt-6">
          <RoleSwitcher role={role} />
        </div>
        <nav className="mt-6 flex flex-col gap-1">
          {nav.map((item) => {
            const active = isActive(pathname, item.href)
            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors",
                  active
                    ? "bg-primary text-primary-foreground"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground",
                )}
              >
                <item.icon className="size-5" />
                {item.label}
              </Link>
            )
          })}
        </nav>
        <div className="mt-auto flex items-center gap-3 rounded-xl bg-muted p-3">
          <Avatar className="size-9">
            <AvatarFallback className="bg-primary font-heading text-sm font-semibold text-primary-foreground">
              {profile.initials}
            </AvatarFallback>
          </Avatar>
          <div className="flex min-w-0 flex-col">
            <span className="truncate text-sm font-semibold">{profile.name}</span>
            <span className="truncate text-xs text-muted-foreground">{profile.meta}</span>
          </div>
        </div>
      </aside>

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        {/* Mobile header */}
        <header className="sticky top-0 z-40 flex items-center justify-between border-b bg-background/80 px-4 py-3 backdrop-blur md:hidden">
          <Logo href={roleHome[role]} />
          <div className="flex items-center gap-2">
            <RoleSwitcher role={role} />
            <Button variant="outline" size="icon" aria-label="Notifications" className="relative">
              <Bell />
              <span className="absolute top-1.5 right-1.5 size-2 rounded-full bg-accent" />
            </Button>
          </div>
        </header>

        <main className="mx-auto w-full max-w-2xl flex-1 px-4 pt-4 pb-28 md:max-w-5xl md:px-8 md:pt-8 md:pb-12">
          {children}
        </main>
      </div>

      {/* Mobile bottom nav */}
      <nav className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/90 backdrop-blur md:hidden">
        <div className="mx-auto flex max-w-2xl items-stretch justify-around px-2 py-1.5">
          {nav.map((item) => {
            const active = isActive(pathname, item.href)
            return (
              <Link
                key={item.href}
                href={item.href}
                className={cn(
                  "flex flex-1 flex-col items-center gap-1 rounded-lg py-1.5 text-[11px] font-medium transition-colors",
                  active ? "text-foreground" : "text-muted-foreground",
                )}
              >
                <span
                  className={cn(
                    "flex h-7 w-12 items-center justify-center rounded-full transition-colors",
                    active && "bg-accent",
                  )}
                >
                  <item.icon className={cn("size-5", active && "text-accent-foreground")} />
                </span>
                {item.label}
              </Link>
            )
          })}
        </div>
      </nav>
    </div>
  )
}
