"use client"

import type { Role } from "@/lib/data"
import { AppShell } from "@/components/app-shell"
import { useAuth } from "@/lib/hooks/use-auth"
import { SkeletonList } from "@/components/marketplace/states"

export default function NotificationsLayout({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth()
  const role: Role = user?.canAccessOrganizer && !user?.canAccessVendor
    ? "organizer"
    : user?.canAccessAdmin
      ? "admin"
      : "vendor"

  if (loading) {
    return (
      <div className="mx-auto max-w-2xl px-4 py-8">
        <SkeletonList count={3} />
      </div>
    )
  }

  return <AppShell role={role}>{children}</AppShell>
}
