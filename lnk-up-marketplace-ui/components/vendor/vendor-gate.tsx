"use client"

import { Suspense } from "react"
import { usePathname } from "next/navigation"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useAuth } from "@/lib/hooks/use-auth"
import { VendorRoleGate } from "@/components/vendor/vendor-role-gate"
import { SkeletonList } from "@/components/marketplace/states"

const PUBLIC_VENDOR_PREFIXES = ["/vendor/find", "/vendor/map"]

function isPublicVendorPath(pathname: string): boolean {
  return PUBLIC_VENDOR_PREFIXES.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`))
}

function VendorGateInner({ children }: { children: React.ReactNode }) {
  const pathname = usePathname()
  const live = isLiveApiEnabled()
  const { loading, isAuthenticated, canAccessVendor, canAccessOrganizer } = useAuth()

  if (isPublicVendorPath(pathname)) {
    return <>{children}</>
  }

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

  if (!isAuthenticated) {
    return <VendorRoleGate variant="unauthenticated" />
  }

  if (!canAccessVendor) {
    return <VendorRoleGate variant="forbidden" />
  }

  return <>{children}</>
}

export function VendorGate({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorGateInner>{children}</VendorGateInner>
    </Suspense>
  )
}
