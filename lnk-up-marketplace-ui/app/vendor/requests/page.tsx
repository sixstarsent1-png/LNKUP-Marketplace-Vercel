"use client"

import { Suspense } from "react"
import { Inbox } from "lucide-react"
import { vendorRequests, type RequestStatus } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { RequestCard } from "@/components/marketplace/request-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"

const tabs: { value: string; label: string; statuses: RequestStatus[] | null }[] = [
  { value: "all", label: "All", statuses: null },
  { value: "under-review", label: "Under Review", statuses: ["under-review"] },
  { value: "approved", label: "Approved", statuses: ["approved"] },
  { value: "payment-due", label: "Payment Due", statuses: ["payment-due"] },
  { value: "paid", label: "Paid", statuses: ["paid"] },
  { value: "waitlisted", label: "Waitlisted", statuses: ["waitlisted"] },
  { value: "rejected", label: "Rejected", statuses: ["rejected"] },
]

function VendorRequestsContent() {
  const { state, retry } = usePanelState()

  return (
    <div className="flex flex-col gap-4">
      <PageHeader title="Requests" subtitle="Track every application from submission to booked." />

      <Tabs defaultValue="all">
        <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
          <TabsList variant="line" className="w-max">
            {tabs.map((t) => (
              <TabsTrigger key={t.value} value={t.value}>
                {t.label}
              </TabsTrigger>
            ))}
          </TabsList>
        </div>

        {tabs.map((t) => {
          const items = t.statuses
            ? vendorRequests.filter((r) => t.statuses!.includes(r.status))
            : vendorRequests
          const tabEmpty = state === "content" && items.length === 0

          return (
            <TabsContent key={t.value} value={t.value} className="mt-4">
              <PanelContent
                state={tabEmpty ? "empty" : state}
                retry={retry}
                empty={
                  <EmptyState
                    icon={Inbox}
                    title="Nothing here yet"
                    description="When you have requests in this stage, they'll show up here."
                  />
                }
              >
                <div className="flex flex-col gap-3">
                  {items.map((r) => (
                    <RequestCard key={r.id} request={r} />
                  ))}
                </div>
              </PanelContent>
            </TabsContent>
          )
        })}
      </Tabs>
    </div>
  )
}

export default function VendorRequestsPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <VendorRequestsContent />
    </Suspense>
  )
}
