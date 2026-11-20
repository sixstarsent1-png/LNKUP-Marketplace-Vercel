"use client"

import { Suspense } from "react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useOrganizerHome } from "@/lib/hooks/use-organizer-home"
import { OrganizerRoleGate } from "@/components/organizer/organizer-role-gate"
import { SkeletonList } from "@/components/marketplace/states"

function OrganizerGateInner({ children }: { children: React.ReactNode }) {
  const live = isLiveApiEnabled()
  const { loading, unauthenticated, forbidden } = useOrganizerHome()

  if (!live) {
    return <>{children}</>
  }

  if (loading) {
    return (
      <div className="py-8">
        <SkeletonList count={2} />
      </div>
    )
  }

  if (unauthenticated) {
    return <OrganizerRoleGate variant="unauthenticated" />
  }

  if (forbidden) {
    return <OrganizerRoleGate variant="forbidden" />
  }

  return <>{children}</>
}

export function OrganizerGate({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerGateInner>{children}</OrganizerGateInner>
    </Suspense>
  )
}
