'use client'

import type { ReactNode } from 'react'
import {
  Home,
  Search,
  FileText,
  Store,
  FolderArchive,
  CreditCard,
  Star,
} from 'lucide-react'
import { DashboardShell, type NavItem } from '@/components/dashboard/shell'

const nav: NavItem[] = [
  { label: 'Home', href: '/dashboard', icon: Home },
  { label: 'Find Events', href: '/dashboard/find-events', icon: Search },
  { label: 'Applications', href: '/dashboard/applications', icon: FileText, badge: 2 },
  { label: 'Business Profile', href: '/dashboard/profile', icon: Store },
  { label: 'Documents', href: '/dashboard/documents', icon: FolderArchive, badge: 1 },
  { label: 'Payments', href: '/dashboard/payments', icon: CreditCard },
  { label: 'Reviews', href: '/dashboard/reviews', icon: Star },
]

export default function DashboardLayout({ children }: { children: ReactNode }) {
  return (
    <DashboardShell
      nav={nav}
      roleLabel="Vendor"
      brandHref="/dashboard"
      user={{
        name: 'Marcus Reed',
        role: 'Smoke & Brisket Co.',
        avatar: '/vendors/smoke-brisket.png',
        initials: 'MR',
      }}
    >
      {children}
    </DashboardShell>
  )
}
