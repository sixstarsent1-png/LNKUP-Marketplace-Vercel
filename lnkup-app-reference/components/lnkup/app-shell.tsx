"use client"

import type React from "react"
import { cn } from "@/lib/utils"
import { Bell, type LucideIcon } from "lucide-react"

export type Role = "vendor" | "organizer" | "admin"

export type NavItem = {
  key: string
  label: string
  icon: LucideIcon
}

const roleLabels: Record<Role, string> = {
  vendor: "Vendor",
  organizer: "Organizer",
  admin: "Admin",
}

export function AppShell({
  role,
  onRoleChange,
  navItems,
  active,
  onNavigate,
  children,
}: {
  role: Role
  onRoleChange: (r: Role) => void
  navItems: NavItem[]
  active: string
  onNavigate: (key: string) => void
  children: React.ReactNode
}) {
  return (
    <div className="min-h-dvh bg-background lg:flex">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-dvh w-64 shrink-0 flex-col border-r border-sidebar-border bg-sidebar px-4 py-6 lg:flex">
        <Brand />
        <RoleSwitcher role={role} onRoleChange={onRoleChange} className="mt-6" />
        <nav className="mt-6 flex flex-1 flex-col gap-1">
          {navItems.map((item) => {
            const isActive = item.key === active
            const Icon = item.icon
            return (
              <button
                key={item.key}
                onClick={() => onNavigate(item.key)}
                className={cn(
                  "flex items-center gap-3 rounded-2xl px-3 py-2.5 text-sm font-semibold transition-colors",
                  isActive
                    ? "bg-sidebar-primary text-sidebar-primary-foreground"
                    : "text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-foreground",
                )}
              >
                <Icon className="size-5" />
                {item.label}
              </button>
            )
          })}
        </nav>
        <div className="rounded-2xl bg-sidebar-accent p-3 text-sidebar-foreground">
          <p className="text-xs text-sidebar-foreground/60">Signed in as</p>
          <p className="text-sm font-semibold">{roleLabels[role]} Demo</p>
        </div>
      </aside>

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        {/* Mobile top bar */}
        <header className="sticky top-0 z-30 flex items-center justify-between border-b border-border bg-background/90 px-4 py-3 backdrop-blur lg:hidden">
          <Brand />
          <div className="flex items-center gap-2">
            <button className="flex size-9 items-center justify-center rounded-full bg-secondary text-foreground">
              <Bell className="size-4" />
            </button>
          </div>
        </header>

        {/* Mobile role switcher */}
        <div className="border-b border-border bg-background px-4 py-2.5 lg:hidden">
          <RoleSwitcher role={role} onRoleChange={onRoleChange} />
        </div>

        <main className="mx-auto w-full max-w-3xl flex-1 px-4 pb-28 pt-4 lg:pb-10 lg:pt-8">
          {children}
        </main>
      </div>

      {/* Mobile bottom nav */}
      <nav className="fixed inset-x-0 bottom-0 z-40 border-t border-border bg-background/95 backdrop-blur lg:hidden">
        <div className="mx-auto flex max-w-md items-stretch justify-around px-2 py-1.5">
          {navItems.map((item) => {
            const isActive = item.key === active
            const Icon = item.icon
            return (
              <button
                key={item.key}
                onClick={() => onNavigate(item.key)}
                className="flex flex-1 flex-col items-center gap-0.5 rounded-2xl py-1.5"
              >
                <span
                  className={cn(
                    "flex h-8 w-12 items-center justify-center rounded-full transition-colors",
                    isActive
                      ? "bg-lime text-lime-foreground"
                      : "text-muted-foreground",
                  )}
                >
                  <Icon className="size-5" />
                </span>
                <span
                  className={cn(
                    "text-[10px] font-semibold",
                    isActive ? "text-foreground" : "text-muted-foreground",
                  )}
                >
                  {item.label}
                </span>
              </button>
            )
          })}
        </div>
      </nav>
    </div>
  )
}

function Brand() {
  return (
    <div className="flex items-center gap-2">
      <div className="flex size-8 items-center justify-center rounded-xl bg-lime font-heading text-sm font-extrabold text-lime-foreground">
        L
      </div>
      <span className="font-heading text-lg font-extrabold tracking-tight">
        LNKup
      </span>
    </div>
  )
}

function RoleSwitcher({
  role,
  onRoleChange,
  className,
}: {
  role: Role
  onRoleChange: (r: Role) => void
  className?: string
}) {
  const roles: Role[] = ["vendor", "organizer", "admin"]
  return (
    <div
      className={cn(
        "flex items-center gap-1 rounded-full bg-secondary p-1",
        className,
      )}
    >
      {roles.map((r) => (
        <button
          key={r}
          onClick={() => onRoleChange(r)}
          className={cn(
            "flex-1 rounded-full px-2 py-1.5 text-xs font-semibold capitalize transition-colors",
            role === r
              ? "bg-foreground text-background"
              : "text-muted-foreground hover:text-foreground",
          )}
        >
          {r}
        </button>
      ))}
    </div>
  )
}

/* Reusable page header used inside screens */
export function PageHeader({
  title,
  subtitle,
  action,
}: {
  title: string
  subtitle?: string
  action?: React.ReactNode
}) {
  return (
    <div className="mb-4 flex items-start justify-between gap-3">
      <div>
        <h1 className="font-heading text-2xl font-extrabold tracking-tight text-balance">
          {title}
        </h1>
        {subtitle ? (
          <p className="mt-1 text-sm text-muted-foreground text-pretty">
            {subtitle}
          </p>
        ) : null}
      </div>
      {action}
    </div>
  )
}
