"use client"

import { Suspense } from "react"
import { Wallet, Receipt, Gift, RotateCcw, CheckCircle2, Calendar, Info } from "lucide-react"
import { vendorRequests, computeVendorFeeBreakdown } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { WalletCard } from "@/components/marketplace/wallet-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs"
import { Card } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"
import { StatusBadge } from "@/components/marketplace/badges"

const tabs = [
  { value: "due", label: "Payments Due" },
  { value: "paid", label: "Paid Bookings" },
  { value: "receipts", label: "Receipts" },
  { value: "credits", label: "Credits" },
  { value: "refunds", label: "Refunds" },
]

function FeeBreakdown({ vendorFee }: { vendorFee: number }) {
  const fees = computeVendorFeeBreakdown(vendorFee)
  return (
    <div className="mt-2 flex flex-col gap-1 rounded-xl bg-muted/60 p-3 text-xs">
      <div className="flex justify-between">
        <span className="text-muted-foreground">Vendor spot fee</span>
        <span>${fees.vendorSpotFee}</span>
      </div>
      <div className="flex justify-between">
        <span className="text-muted-foreground">LNKup fee</span>
        <span>${fees.lnkupFee}</span>
      </div>
      <Separator className="my-1" />
      <div className="flex justify-between font-semibold">
        <span>Total</span>
        <span>${fees.total}</span>
      </div>
      <p className="mt-1 flex items-start gap-1 text-muted-foreground">
        <Info className="mt-0.5 size-3 shrink-0" />
        Your vendor packet unlocks automatically after payment.
      </p>
    </div>
  )
}

function VendorWalletContent() {
  const { state, retry } = usePanelState()
  const due = vendorRequests.filter((r) => r.status === "payment-due" || r.status === "approved")
  const paid = vendorRequests.filter((r) => r.status === "paid")
  const totalDue = due.reduce((sum, r) => sum + computeVendorFeeBreakdown(r.vendorFee).total, 0)
  const totalPaid = paid.reduce((sum, r) => sum + r.vendorFee, 0)

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-5">
        <PageHeader title="Wallet" subtitle="Your bookings, receipts, and credits in one place." />

        <div className="grid grid-cols-2 gap-3">
          <WalletCard label="Payments due" amount={`$${totalDue}`} icon={Wallet} sublabel={`${due.length} to pay`} tone="primary" />
          <WalletCard label="Paid this season" amount={`$${totalPaid}`} icon={CheckCircle2} sublabel={`${paid.length} bookings`} />
        </div>

        <Tabs defaultValue="due">
          <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <TabsList variant="line" className="w-max">
              {tabs.map((t) => (
                <TabsTrigger key={t.value} value={t.value}>
                  {t.label}
                </TabsTrigger>
              ))}
            </TabsList>
          </div>

          <TabsContent value="due" className="mt-4">
            {due.length === 0 ? (
              <EmptyState icon={CheckCircle2} title="You're all paid up" description="No outstanding vendor fees right now." />
            ) : (
              <div className="flex flex-col gap-3">
                {due.map((r) => {
                  const fees = computeVendorFeeBreakdown(r.vendorFee)
                  return (
                    <Card key={r.id} className="rounded-2xl p-4">
                      <div className="flex items-center gap-3">
                        <div className="flex flex-1 flex-col">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold">{r.eventName}</span>
                            <StatusBadge variant="due">Due</StatusBadge>
                          </div>
                          <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Calendar className="size-3" />
                            {r.date}
                          </span>
                        </div>
                        <div className="flex flex-col items-end gap-1">
                          <span className="font-heading font-bold">${fees.total}</span>
                          <Button size="sm">Pay now</Button>
                        </div>
                      </div>
                      <FeeBreakdown vendorFee={r.vendorFee} />
                    </Card>
                  )
                })}
              </div>
            )}
          </TabsContent>

          <TabsContent value="paid" className="mt-4">
            <div className="flex flex-col gap-3">
              {paid.map((r) => (
                <Card key={r.id} className="flex-row items-center gap-3 rounded-2xl p-4">
                  <div className="flex size-10 shrink-0 items-center justify-center rounded-full bg-success/15">
                    <CheckCircle2 className="size-5 text-success" />
                  </div>
                  <div className="flex flex-1 flex-col">
                    <span className="font-semibold">{r.eventName}</span>
                    <span className="text-xs text-muted-foreground">Paid on {r.submittedAt} · {r.city}</span>
                  </div>
                  <span className="font-heading font-bold">${r.vendorFee}</span>
                </Card>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="receipts" className="mt-4">
            <div className="flex flex-col gap-3">
              {paid.map((r) => (
                <Card key={r.id} className="flex-row items-center gap-3 rounded-2xl p-4">
                  <Receipt className="size-5 text-muted-foreground" />
                  <div className="flex flex-1 flex-col">
                    <span className="text-sm font-semibold">Receipt · {r.eventName}</span>
                    <span className="text-xs text-muted-foreground">#{r.id.toUpperCase()} · ${r.vendorFee}</span>
                  </div>
                  <Button size="sm" variant="outline">Download</Button>
                </Card>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="credits" className="mt-4">
            <WalletCard label="Available credits" amount="$25.00" icon={Gift} sublabel="From referral bonus" tone="success" />
          </TabsContent>

          <TabsContent value="refunds" className="mt-4">
            <EmptyState icon={RotateCcw} title="No refunds" description="You have no refund requests. Refunds appear here if a booking is canceled." />
          </TabsContent>
        </Tabs>
      </div>
    </PanelContent>
  )
}

export default function VendorWalletPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorWalletContent />
    </Suspense>
  )
}
