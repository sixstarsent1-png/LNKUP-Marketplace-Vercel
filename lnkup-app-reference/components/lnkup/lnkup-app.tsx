"use client"

import { useState } from "react"
import {
  Home,
  Search,
  Inbox,
  Wallet,
  User,
  CalendarDays,
  Users,
  DollarSign,
  MoreHorizontal,
  LayoutGrid,
} from "lucide-react"
import { AppShell, type NavItem, type Role } from "./app-shell"
import {
  VendorHome,
  VendorFindEvents,
  VendorRequests,
  VendorEventPass,
  VendorWallet,
  VendorProfile,
} from "./screens/vendor"
import {
  OrganizerHome,
  OrganizerEvents,
  OrganizerEventDetail,
  OrganizerVendors,
  OrganizerMoney,
  OrganizerMore,
} from "./screens/organizer"
import {
  AdminOverview,
  AdminUsers,
  AdminEvents,
  AdminMoney,
  AdminMore,
} from "./screens/admin"
import type { EventItem } from "@/lib/data"

const navByRole: Record<Role, NavItem[]> = {
  vendor: [
    { key: "home", label: "Home", icon: Home },
    { key: "find", label: "Find", icon: Search },
    { key: "requests", label: "Requests", icon: Inbox },
    { key: "wallet", label: "Wallet", icon: Wallet },
    { key: "profile", label: "Profile", icon: User },
  ],
  organizer: [
    { key: "home", label: "Home", icon: Home },
    { key: "events", label: "Events", icon: CalendarDays },
    { key: "vendors", label: "Vendors", icon: Users },
    { key: "money", label: "Money", icon: DollarSign },
    { key: "more", label: "More", icon: MoreHorizontal },
  ],
  admin: [
    { key: "overview", label: "Overview", icon: LayoutGrid },
    { key: "users", label: "Users", icon: Users },
    { key: "events", label: "Events", icon: CalendarDays },
    { key: "money", label: "Money", icon: DollarSign },
    { key: "more", label: "More", icon: MoreHorizontal },
  ],
}

const defaultScreen: Record<Role, string> = {
  vendor: "home",
  organizer: "home",
  admin: "overview",
}

export function LnkupApp() {
  const [role, setRole] = useState<Role>("vendor")
  const [screen, setScreen] = useState<string>("home")
  const [activeEvent, setActiveEvent] = useState<EventItem | null>(null)

  const handleRole = (r: Role) => {
    setRole(r)
    setScreen(defaultScreen[r])
    setActiveEvent(null)
  }

  const navigate = (key: string) => {
    setActiveEvent(null)
    setScreen(key)
  }

  const renderScreen = () => {
    if (role === "vendor") {
      switch (screen) {
        case "home":
          return <VendorHome onNavigate={navigate} />
        case "find":
          return <VendorFindEvents />
        case "requests":
          return <VendorRequests onOpenPass={() => setScreen("pass")} />
        case "pass":
          return <VendorEventPass />
        case "wallet":
          return <VendorWallet />
        case "profile":
          return <VendorProfile />
      }
    }
    if (role === "organizer") {
      if (activeEvent) {
        return (
          <OrganizerEventDetail
            event={activeEvent}
            onBack={() => setActiveEvent(null)}
          />
        )
      }
      switch (screen) {
        case "home":
          return <OrganizerHome onNavigate={navigate} />
        case "events":
          return <OrganizerEvents onOpenEvent={(e) => setActiveEvent(e)} />
        case "vendors":
          return <OrganizerVendors />
        case "money":
          return <OrganizerMoney />
        case "more":
          return <OrganizerMore />
      }
    }
    if (role === "admin") {
      switch (screen) {
        case "overview":
          return <AdminOverview />
        case "users":
          return <AdminUsers />
        case "events":
          return <AdminEvents />
        case "money":
          return <AdminMoney />
        case "more":
          return <AdminMore />
      }
    }
    return null
  }

  // Keep bottom-nav highlight sensible when on a sub-screen like the pass
  const activeKey =
    role === "vendor" && screen === "pass" ? "requests" : screen

  return (
    <AppShell
      role={role}
      onRoleChange={handleRole}
      navItems={navByRole[role]}
      active={activeKey}
      onNavigate={navigate}
    >
      {renderScreen()}
    </AppShell>
  )
}
