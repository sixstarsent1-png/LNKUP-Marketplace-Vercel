import {
  Home,
  Search,
  Inbox,
  Wallet,
  User,
  CalendarDays,
  Ticket,
  TrendingUp,
  Store,
  DollarSign,
  MoreHorizontal,
  LayoutDashboard,
  Users,
  type LucideIcon,
} from "lucide-react"
import type { Role } from "@/lib/data"

export type NavItem = {
  label: string
  /** Shorter label for mobile bottom nav */
  mobileLabel?: string
  href: string
  icon: LucideIcon
  /** Hide on mobile bottom nav (still shown in desktop sidebar) */
  hideOnMobile?: boolean
  /** Hide on desktop sidebar (still shown on mobile bottom nav) */
  hideOnDesktop?: boolean
  /** Additional paths that should highlight this nav item */
  activePaths?: string[]
}

function isHomeHref(href: string, role: Role) {
  return href === roleHome[role]
}

export function isNavActive(pathname: string, item: NavItem, role: Role) {
  if (item.activePaths?.some((p) => pathname === p || pathname.startsWith(p + "/"))) {
    return true
  }
  if (isHomeHref(item.href, role)) {
    return pathname === item.href
  }
  return pathname === item.href || pathname.startsWith(item.href + "/")
}

export const roleNav: Record<Role, NavItem[]> = {
  vendor: [
    { label: "Home", href: "/vendor", icon: Home },
    {
      label: "Explore",
      mobileLabel: "Explore",
      href: "/vendor/find",
      icon: Search,
    },
    { label: "Bookings", href: "/vendor/requests", icon: Inbox },
    { label: "Operations", href: "/vendor/operations", icon: Ticket, hideOnMobile: true },
    { label: "Wallet", href: "/vendor/wallet", icon: Wallet },
    { label: "Passport", href: "/vendor/profile", icon: User, hideOnMobile: true },
    { label: "Growth", href: "/vendor/growth", icon: TrendingUp, hideOnMobile: true },
    {
      label: "More",
      href: "/vendor/more",
      icon: MoreHorizontal,
      hideOnDesktop: true,
      activePaths: [
        "/vendor/more",
        "/vendor/documents",
        "/vendor/growth",
        "/vendor/reviews",
        "/vendor/favorites",
        "/vendor/operations",
        "/vendor/pass",
      ],
    },
  ],
  organizer: [
    { label: "Home", href: "/organizer", icon: Home },
    { label: "Launch event", href: "/organizer/launch", icon: CalendarDays, hideOnMobile: true },
    { label: "Events", mobileLabel: "Events", href: "/organizer/events", icon: CalendarDays },
    {
      label: "Vendors",
      mobileLabel: "Vendors",
      href: "/organizer/vendors",
      icon: Store,
      activePaths: ["/organizer/vendors"],
    },
    { label: "Money", mobileLabel: "Money", href: "/organizer/money", icon: DollarSign },
    { label: "More", href: "/organizer/more", icon: MoreHorizontal },
  ],
  admin: [
    { label: "Overview", mobileLabel: "Home", href: "/admin", icon: LayoutDashboard },
    { label: "Users", href: "/admin/users", icon: Users },
    { label: "Events", href: "/admin/events", icon: CalendarDays },
    { label: "Money", href: "/admin/money", icon: DollarSign },
    {
      label: "More",
      href: "/admin/more",
      icon: MoreHorizontal,
      activePaths: [
        "/admin/more",
        "/admin/external-events",
        "/admin/verification",
        "/admin/connectors",
      ],
    },
  ],
}

export const roleLabels: Record<Role, string> = {
  vendor: "Vendor",
  organizer: "Organizer",
  admin: "Admin",
}

export const roleHome: Record<Role, string> = {
  vendor: "/vendor",
  organizer: "/organizer",
  admin: "/admin",
}

export function getDesktopNav(role: Role) {
  return roleNav[role].filter((item) => !item.hideOnDesktop)
}

export function getMobileNav(role: Role) {
  return roleNav[role].filter((item) => !item.hideOnMobile)
}
