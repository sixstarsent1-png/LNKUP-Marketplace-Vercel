import {
  Home,
  Search,
  Inbox,
  Wallet,
  User,
  CalendarDays,
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
  href: string
  icon: LucideIcon
}

export const roleNav: Record<Role, NavItem[]> = {
  vendor: [
    { label: "Home", href: "/vendor", icon: Home },
    { label: "Find Events", href: "/vendor/find", icon: Search },
    { label: "Requests", href: "/vendor/requests", icon: Inbox },
    { label: "Wallet", href: "/vendor/wallet", icon: Wallet },
    { label: "Profile", href: "/vendor/profile", icon: User },
  ],
  organizer: [
    { label: "Home", href: "/organizer", icon: Home },
    { label: "Events", href: "/organizer/events", icon: CalendarDays },
    { label: "Vendors", href: "/organizer/vendors", icon: Store },
    { label: "Money", href: "/organizer/money", icon: DollarSign },
    { label: "More", href: "/organizer/more", icon: MoreHorizontal },
  ],
  admin: [
    { label: "Overview", href: "/admin", icon: LayoutDashboard },
    { label: "Users", href: "/admin/users", icon: Users },
    { label: "Events", href: "/admin/events", icon: CalendarDays },
    { label: "Money", href: "/admin/money", icon: DollarSign },
    { label: "More", href: "/admin/more", icon: MoreHorizontal },
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
