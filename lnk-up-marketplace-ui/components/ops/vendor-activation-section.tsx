"use client"

import Link from "next/link"
import { StatCard } from "@/components/marketplace/stat-card"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { SkeletonList, ErrorState } from "@/components/marketplace/states"
import type { VendorActivationInsight } from "@/lib/ops/types"
import type { PanelState } from "@/lib/use-panel-state"
import { Users, FileWarning, CreditCard, CheckCircle } from "lucide-react"

export function VendorActivationSection({
  data,
  state,
  onRetry,
}: {
  data: VendorActivationInsight | null
  state: PanelState
  onRetry: () => void
}) {
  if (state === "loading") {
    return (
      <Card data-testid="ops-vendor-activation-section">
        <CardHeader>
          <CardTitle>Vendor activation</CardTitle>
        </CardHeader>
        <CardContent>
          <SkeletonList count={2} />
        </CardContent>
      </Card>
    )
  }

  if (state === "error") {
    return (
      <Card data-testid="ops-vendor-activation-section">
        <CardContent className="pt-6">
          <ErrorState title="Activation metrics unavailable" onRetry={onRetry} />
        </CardContent>
      </Card>
    )
  }

  if (!data) {
    return (
      <Card data-testid="ops-vendor-activation-section">
        <CardHeader>
          <CardTitle>Vendor activation</CardTitle>
        </CardHeader>
        <CardContent>
          <p className="text-sm text-muted-foreground">No activation data available.</p>
        </CardContent>
      </Card>
    )
  }

  return (
    <Card data-testid="ops-vendor-activation-section">
      <CardHeader>
        <CardTitle>Vendor activation</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <StatCard label="New vendors" value={data.newVendors} icon={Users} />
          <StatCard label="Incomplete profiles" value={data.incompleteProfiles} icon={Users} />
          <StatCard label="Missing documents" value={data.missingDocuments} icon={FileWarning} />
          <StatCard label="Saved, no apply" value={data.savedNoApply} icon={Users} />
          <StatCard label="Under review" value={data.appliedUnderReview} icon={Users} />
          <StatCard label="Payment required" value={data.paymentRequired} icon={CreditCard} accent />
          <StatCard label="Booked" value={data.booked} icon={CheckCircle} />
          <StatCard label="Pass viewed" value={data.passViewed} icon={CheckCircle} />
        </div>

        {data.stuckVendors && data.stuckVendors.length > 0 && (
          <div>
            <p className="mb-2 text-sm font-medium">Stuck vendors (aggregate)</p>
            <ul className="flex flex-col gap-2 text-sm">
              {data.stuckVendors.map((v) => (
                <li key={v.id} className="flex items-center justify-between rounded-xl border px-3 py-2">
                  <span>{v.label}</span>
                  <span className="text-muted-foreground">{v.reason.replace(/_/g, " ")}</span>
                  {v.href && (
                    <Link href={v.href} className="text-xs underline">
                      View
                    </Link>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
