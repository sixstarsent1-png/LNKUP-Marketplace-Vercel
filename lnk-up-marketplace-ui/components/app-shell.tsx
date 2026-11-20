"use client"

import Link from "next/link"
import { usePathname, useRouter } from "next/navigation"
import { Check, ChevronsUpDown, MoreHorizontal, Zap } from "lucide-react"
import { NotificationBell } from "@/components/notifications/notification-bell"
import type { Role } from "@/lib/data"
import {
  roleLabels,
  roleHome,
  getDesktopNav,
  getMobileNav,
  isNavActive,
  type NavItem,
} from "@/lib/nav"
import { isLiveApiEnabled, getVendorLoginUrl } from "@/lib/api/config"
import { writeLastUsedRole } from "@/lib/auth/app-user"
import { useAuth } from "@/lib/hooks/use-auth"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"
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

const vendorMoreNav: NavItem = {
  label: "More",
  href: "/vendor/more",
  icon: MoreHorizontal,
  activePaths: [
    "/vendor/more",
    "/vendor/documents",
    "/vendor/growth",
    "/vendor/reviews",
    "/vendor/favorites",
    "/vendor/operations",
    "/vendor/pass",
  ],
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

function getSwitchableRoles(
  role: Role,
  liveApi: boolean,
  canAccessVendor: boolean,
  canAccessOrganizer: boolean,
  canAccessAdmin: boolean,
): Role[] {
  if (!liveApi) {
    return isOpsAnalyticsEnabled() ? roles : roles.filter((item) => item !== "admin")
  }
  const next: Role[] = []
  if (canAccessVendor) {
    next.push("vendor")
  }
  if (canAccessOrganizer) {
    next.push("organizer")
  }
  if (canAccessAdmin && isOpsAnalyticsEnabled()) {
    next.push("admin")
  }
  if (next.length === 0) {
    return [role]
  }
  return next
}

function RoleSwitcher({
  role,
  liveApi,
  canAccessVendor,
  canAccessOrganizer,
  canAccessAdmin,
}: {
  role: Role
  liveApi: boolean
  canAccessVendor: boolean
  canAccessOrganizer: boolean
  canAccessAdmin: boolean
}) {
  const router = useRouter()
  const available = getSwitchableRoles(
    role,
    liveApi,
    canAccessVendor,
    canAccessOrganizer,
    canAccessAdmin,
  )
  if (available.length <= 1) {
    return null
  }
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
        <DropdownMenuGroup>
          <DropdownMenuLabel>{liveApi ? "Switch workspace" : "Switch role (demo)"}</DropdownMenuLabel>
          {available.map((r) => (
            <DropdownMenuItem
              key={r}
              onClick={() => {
                writeLastUsedRole(r)
                router.push(roleHome[r])
              }}
            >
              {roleLabels[r]}
              {r === role && <Check data-icon="inline-end" />}
            </DropdownMenuItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}

function NavLink({
  item,
  role,
  pathname,
  mobile,
}: {
  item: NavItem
  role: Role
  pathname: string
  mobile?: boolean
}) {
  const active = isNavActive(pathname, item, role)
  const label = mobile ? (item.mobileLabel ?? item.label) : item.label

  if (mobile) {
    return (
      <Link
        href={item.href}
        aria-current={active ? "page" : undefined}
        className={cn(
          "flex min-h-11 flex-1 flex-col items-center justify-center gap-1 rounded-lg py-1.5 text-[11px] font-medium transition-colors",
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
        {label}
      </Link>
    )
  }

  return (
    <Link
      href={item.href}
      className={cn(
        "flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors",
        active
          ? "bg-primary text-primary-foreground"
          : "text-muted-foreground hover:bg-muted hover:text-foreground",
      )}
    >
      <item.icon className="size-5" />
      {label}
    </Link>
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
  const desktopNav = getDesktopNav(role)
  const mobileNav = getMobileNav(role)
  const profile = roleProfile[role]
  const moreActive = role === "vendor" && isNavActive(pathname, vendorMoreNav, role)
  const liveApi = isLiveApiEnabled()
  const { user, isAuthenticated, logout, canAccessVendor, canAccessOrganizer, canAccessAdmin } = useAuth()
  const switcherProps = {
    role,
    liveApi,
    canAccessVendor: liveApi ? canAccessVendor : true,
    canAccessOrganizer: liveApi ? canAccessOrganizer : true,
    canAccessAdmin: liveApi ? canAccessAdmin : true,
  }

  return (
    <div className="min-h-dvh bg-background md:flex">
      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-r bg-sidebar p-4 md:flex">
        <div className="flex items-center justify-between gap-2">
          <Logo href={roleHome[role]} />
          {(role === "vendor" || role === "organizer") && <NotificationBell className="hidden md:inline-flex" />}
        </div>
        <div className="mt-6">
          <RoleSwitcher {...switcherProps} />
        </div>
        <nav className="mt-6 flex flex-col gap-1">
          {desktopNav.map((item) => (
            <NavLink key={item.href} item={item} role={role} pathname={pathname} />
          ))}
          {role === "vendor" && (
            <Link
              href="/vendor/more"
              className={cn(
                "mt-2 flex items-center gap-3 rounded-xl border border-dashed px-3 py-2.5 text-sm font-medium transition-colors",
                moreActive
                  ? "border-accent bg-accent/10 text-foreground"
                  : "text-muted-foreground hover:bg-muted hover:text-foreground",
              )}
            >
              <MoreHorizontal className="size-5" />
              More
            </Link>
          )}
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

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-40 flex items-center justify-between border-b bg-background/80 px-4 py-3 pt-safe backdrop-blur md:hidden">
          <Logo href={roleHome[role]} />
          <div className="flex items-center gap-2">
            {(role === "vendor" || role === "organizer") && liveApi && (
              isAuthenticated ? (
                <Button variant="outline" size="sm" className="min-h-11" onClick={() => logout()}>
                  {user?.name?.split(" ")[0] ?? "Account"}
                </Button>
              ) : (
                <Button
                  variant="outline"
                  size="sm"
                  className="min-h-11"
                  nativeButton={false}
                  render={
                    <Link
                      href={
                        role === "organizer"
                          ? "/login?redirect=/organizer"
                          : getVendorLoginUrl({ intent: "vendor", redirect: pathname })
                      }
                    />
                  }
                >
                  Sign in
                </Button>
              )
            )}
            <RoleSwitcher {...switcherProps} />
            {(role === "vendor" || role === "organizer") && <NotificationBell className="md:hidden" />}
          </div>
        </header>

        <main
          className={cn(
            "mx-auto w-full max-w-2xl flex-1 px-4 pb-shell md:max-w-5xl md:px-8 md:pb-12",
            role === "vendor" ? "pt-5 md:pt-8" : "pt-4 md:pt-8",
          )}
        >
          {children}
        </main>
      </div>

      <nav className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/90 pb-safe backdrop-blur md:hidden">
        <div className="mx-auto flex max-w-2xl items-stretch justify-around px-2 py-1.5">
          {mobileNav.map((item) => (
            <NavLink key={item.href} item={item} role={role} pathname={pathname} mobile />
          ))}
        </div>
      </nav>
    </div>
  )
}
