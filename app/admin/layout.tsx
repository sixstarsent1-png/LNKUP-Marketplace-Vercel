'use client'

import type { ReactNode } from 'react'
import {
  LayoutDashboard,
  Store,
  Building2,
  BarChart3,
  ShieldCheck,
  CalendarRange,
} from 'lucide-react'
import { DashboardShell, type NavItem } from '@/components/dashboard/shell'

const nav: NavItem[] = [
  { label: 'Overview', href: '/admin', icon: LayoutDashboard },
  { label: 'Vendors', href: '/admin/vendors', icon: Store, badge: 4 },
  { label: 'Organizers', href: '/admin/organizers', icon: Building2, badge: 1 },
  { label: 'Events', href: '/admin/events', icon: CalendarRange },
  { label: 'Analytics', href: '/admin/analytics', icon: BarChart3 },
]

export default function AdminLayout({ children }: { children: ReactNode }) {
  return (
    <DashboardShell
      nav={nav}
      roleLabel="Admin"
      brandHref="/admin"
      user={{
        name: 'Dana Okafor',
        role: 'Platform Admin',
        initials: 'DO',
      }}
    >
      {children}
    </DashboardShell>
  )
}
