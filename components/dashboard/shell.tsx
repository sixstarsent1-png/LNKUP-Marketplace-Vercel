'use client'

import Link from 'next/link'
import { usePathname } from 'next/navigation'
import { useState, type ReactNode } from 'react'
import { Menu, Bell, Search, ChevronsUpDown, Check, type LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Logo } from '@/components/brand/logo'
import { Button } from '@/components/ui/button'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

const ROLE_LINKS = [
  { label: 'Vendor', href: '/dashboard' },
  { label: 'Organizer', href: '/organizer' },
  { label: 'Admin', href: '/admin' },
]

export type NavItem = {
  label: string
  href: string
  icon: LucideIcon
  badge?: number
}

export type ShellUser = {
  name: string
  role: string
  avatar?: string
  initials: string
}

function NavList({
  items,
  pathname,
  onNavigate,
}: {
  items: NavItem[]
  pathname: string
  onNavigate?: () => void
}) {
  return (
    <nav className="flex flex-col gap-1">
      {items.map((item) => {
        const active =
          pathname === item.href ||
          (item.href !== '/' &&
            pathname.startsWith(item.href) &&
            item.href.split('/').length > 2)
        return (
          <Link
            key={item.href}
            href={item.href}
            onClick={onNavigate}
            className={cn(
              'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-colors',
              active
                ? 'bg-primary text-primary-foreground'
                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
            )}
          >
            <item.icon className="size-[18px]" />
            <span className="flex-1">{item.label}</span>
            {item.badge ? (
              <span
                className={cn(
                  'grid h-5 min-w-5 place-items-center rounded-full px-1.5 text-xs font-semibold',
                  active
                    ? 'bg-primary-foreground text-primary'
                    : 'bg-accent text-accent-foreground',
                )}
              >
                {item.badge}
              </span>
            ) : null}
          </Link>
        )
      })}
    </nav>
  )
}

export function DashboardShell({
  nav,
  user,
  brandHref = '/dashboard',
  roleLabel,
  children,
}: {
  nav: NavItem[]
  user: ShellUser
  brandHref?: string
  roleLabel: string
  children: ReactNode
}) {
  const pathname = usePathname()
  const [open, setOpen] = useState(false)

  return (
    <div className="min-h-screen bg-muted/30 lg:grid lg:grid-cols-[272px_1fr]">
      {/* Desktop sidebar */}
      <aside className="sticky top-0 hidden h-screen flex-col border-r border-border bg-card lg:flex">
        <div className="flex h-16 items-center border-b border-border px-5">
          <Link href={brandHref}>
            <Logo />
          </Link>
        </div>
        <div className="flex flex-1 flex-col gap-1 overflow-y-auto p-4">
          <DropdownMenu>
            <DropdownMenuTrigger
              render={
                <button className="mb-3 flex w-full items-center justify-between rounded-xl border border-border bg-muted/50 px-3 py-2.5 text-left transition-colors hover:bg-muted" />
              }
            >
              <span className="flex flex-col">
                <span className="text-xs text-muted-foreground">Workspace</span>
                <span className="text-sm font-semibold">{roleLabel}</span>
              </span>
              <ChevronsUpDown className="size-4 text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              <DropdownMenuGroup>
                <DropdownMenuLabel>Switch workspace</DropdownMenuLabel>
                <DropdownMenuSeparator />
                {ROLE_LINKS.map((role) => (
                  <DropdownMenuItem
                    key={role.href}
                    render={<Link href={role.href} />}
                  >
                    <span className="flex-1">{role.label}</span>
                    {role.label === roleLabel && <Check className="size-4" />}
                  </DropdownMenuItem>
                ))}
              </DropdownMenuGroup>
            </DropdownMenuContent>
          </DropdownMenu>
          <p className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Menu
          </p>
          <NavList items={nav} pathname={pathname} />
        </div>
        <div className="border-t border-border p-4">
          <div className="flex items-center gap-3 rounded-xl px-2 py-2">
            <Avatar className="size-9">
              <AvatarImage src={user.avatar || '/placeholder.svg'} alt={user.name} />
              <AvatarFallback>{user.initials}</AvatarFallback>
            </Avatar>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold">{user.name}</p>
              <p className="truncate text-xs text-muted-foreground">{user.role}</p>
            </div>
          </div>
        </div>
      </aside>

      <div className="flex min-w-0 flex-col">
        {/* Top bar */}
        <header className="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-card/80 px-4 backdrop-blur-xl sm:px-6">
          <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger
              render={
                <Button variant="outline" size="icon" className="lg:hidden" />
              }
            >
              <Menu />
            </SheetTrigger>
            <SheetContent side="left" className="w-72 p-0">
              <SheetTitle className="sr-only">Navigation</SheetTitle>
              <div className="flex h-16 items-center border-b border-border px-5">
                <Logo />
              </div>
              <div className="p-4">
                <p className="px-3 pb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                  {roleLabel}
                </p>
                <NavList
                  items={nav}
                  pathname={pathname}
                  onNavigate={() => setOpen(false)}
                />
              </div>
            </SheetContent>
          </Sheet>

          <div className="relative hidden max-w-md flex-1 sm:block">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input
              type="search"
              placeholder="Search events, cities, vendors..."
              className="h-10 w-full rounded-xl border border-border bg-background pl-9 pr-3 text-sm outline-none transition-colors focus:border-ring"
            />
          </div>

          <div className="ml-auto flex items-center gap-2">
            <Button variant="outline" size="icon" aria-label="Notifications">
              <Bell />
            </Button>
            <Avatar className="size-9 lg:hidden">
              <AvatarImage src={user.avatar || '/placeholder.svg'} alt={user.name} />
              <AvatarFallback>{user.initials}</AvatarFallback>
            </Avatar>
          </div>
        </header>

        <main className="flex-1 p-4 sm:p-6 lg:p-8">{children}</main>
      </div>
    </div>
  )
}

export function PageHeading({
  title,
  description,
  action,
}: {
  title: string
  description?: string
  action?: ReactNode
}) {
  return (
    <div className="mb-6 flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
      <div>
        <h1 className="font-display text-2xl font-extrabold tracking-tight sm:text-3xl">
          {title}
        </h1>
        {description && (
          <p className="mt-1 text-muted-foreground">{description}</p>
        )}
      </div>
      {action}
    </div>
  )
}
