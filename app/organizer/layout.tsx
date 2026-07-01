'use client'

import type { ReactNode } from 'react'
import {
  LayoutDashboard,
  CalendarPlus,
  Inbox,
  Users,
  DollarSign,
  Download,
} from 'lucide-react'
import { DashboardShell, type NavItem } from '@/components/dashboard/shell'

const nav: NavItem[] = [
  { label: 'Overview', href: '/organizer', icon: LayoutDashboard },
  { label: 'Create Opportunity', href: '/organizer/create', icon: CalendarPlus },
  { label: 'Applications', href: '/organizer/applications', icon: Inbox, badge: 27 },
  { label: 'Import Event', href: '/organizer/import', icon: Download },
  { label: 'Vendors', href: '/organizer/vendors', icon: Users },
  { label: 'Payouts', href: '/organizer/payouts', icon: DollarSign },
]

export default function OrganizerLayout({ children }: { children: ReactNode }) {
  return (
    <DashboardShell
      nav={nav}
      roleLabel="Organizer"
      brandHref="/organizer"
      user={{
        name: 'Jasmine Carter',
        role: 'Southern Live Co.',
        initials: 'JC',
      }}
    >
      {children}
    </DashboardShell>
  )
}
