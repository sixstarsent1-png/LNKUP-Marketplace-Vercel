"use client"

import { Suspense, useState } from "react"
import { Search, Store, Users, ListPlus, Send, Plus } from "lucide-react"
import { vendors, incomingRequests, vendorLists, vendorInvites } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { VendorCard } from "@/components/marketplace/vendor-card"
import { IncomingRequestCard } from "@/components/marketplace/incoming-request-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Input } from "@/components/ui/input"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"
import { StatusBadge } from "@/components/marketplace/badges"

function OrganizerVendorsContent() {
  const { state, retry } = usePanelState()
  const [query, setQuery] = useState("")
  const filtered = vendors.filter(
    (v) =>
      v.name.toLowerCase().includes(query.toLowerCase()) ||
      v.category.toLowerCase().includes(query.toLowerCase()) ||
      v.city.toLowerCase().includes(query.toLowerCase()),
  )
  const pending = incomingRequests.filter((r) => r.status === "under-review")
  const approved = incomingRequests.filter((r) => r.status === "approved")

  return (
    <PanelContent state={state} retry={retry}>
      <div className="flex flex-col gap-6">
        <PageHeader title="Vendors" subtitle="Review requests and discover vendors to invite." />

        <Tabs defaultValue="requests">
          <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <TabsList variant="line" className="w-max">
              <TabsTrigger value="requests">Requests ({pending.length})</TabsTrigger>
              <TabsTrigger value="discover">Discover</TabsTrigger>
              <TabsTrigger value="approved">Approved ({approved.length})</TabsTrigger>
              <TabsTrigger value="lists">Lists</TabsTrigger>
              <TabsTrigger value="invites">Invites</TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="requests" className="mt-4 flex flex-col gap-3">
            {pending.length > 0 ? (
              pending.map((r) => <IncomingRequestCard key={r.id} request={r} />)
            ) : (
              <EmptyState
                icon={Store}
                title="No pending requests"
                description="Vendor applications will appear here as they come in."
              />
            )}
          </TabsContent>

          <TabsContent value="discover" className="mt-4 flex flex-col gap-4">
            <div className="relative">
              <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search vendors by name, category, or city"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                className="pl-9"
              />
            </div>

            {filtered.length > 0 ? (
              <div className="grid gap-4 md:grid-cols-2">
                {filtered.map((v) => (
                  <VendorCard key={v.id} vendor={v} />
                ))}
              </div>
            ) : (
              <EmptyState
                icon={Search}
                title="No vendors found"
                description="Try a different search term or category."
              />
            )}
          </TabsContent>

          <TabsContent value="approved" className="mt-4 flex flex-col gap-3">
            {approved.length > 0 ? (
              approved.map((r) => <IncomingRequestCard key={r.id} request={r} />)
            ) : (
              <EmptyState
                icon={Users}
                title="No approved vendors yet"
                description="Approved vendors waiting on payment will show here."
              />
            )}
          </TabsContent>

          <TabsContent value="lists" className="mt-4 flex flex-col gap-4">
            <div className="flex justify-end">
              <Button size="sm">
                <Plus data-icon="inline-start" />
                New list
              </Button>
            </div>
            {vendorLists.length > 0 ? (
              <div className="flex flex-col gap-3">
                {vendorLists.map((list) => (
                  <Card key={list.id} className="rounded-2xl">
                    <CardContent className="flex items-center gap-3 py-4">
                      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                        <ListPlus className="size-5 text-muted-foreground" />
                      </span>
                      <div className="flex flex-1 flex-col">
                        <span className="text-sm font-semibold">{list.name}</span>
                        <span className="text-xs text-muted-foreground">
                          {list.count} vendors · Updated {list.updatedAt}
                        </span>
                      </div>
                      <Button size="sm" variant="outline">
                        View
                      </Button>
                    </CardContent>
                  </Card>
                ))}
              </div>
            ) : (
              <EmptyState
                icon={ListPlus}
                title="No vendor lists"
                description="Save vendors from Discover into lists for faster event staffing."
              />
            )}
          </TabsContent>

          <TabsContent value="invites" className="mt-4 flex flex-col gap-4">
            <div className="flex justify-end">
              <Button size="sm">
                <Send data-icon="inline-start" />
                Invite vendor
              </Button>
            </div>
            {vendorInvites.length > 0 ? (
              <Card className="rounded-2xl">
                <CardContent className="flex flex-col pt-4">
                  {vendorInvites.map((invite, i) => (
                    <div key={invite.id}>
                      {i > 0 && <Separator className="my-1" />}
                      <div className="flex items-center gap-3 py-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                          <Send className="size-5 text-muted-foreground" />
                        </span>
                        <div className="flex flex-1 flex-col">
                          <span className="text-sm font-semibold">{invite.vendor}</span>
                          <span className="text-xs text-muted-foreground">
                            {invite.event} · Sent {invite.sentAt}
                          </span>
                        </div>
                        <StatusBadge variant={invite.status === "accepted" ? "approved" : "review"}>
                          {invite.status === "accepted" ? "Accepted" : "Pending"}
                        </StatusBadge>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            ) : (
              <EmptyState
                icon={Users}
                title="No invites sent"
                description="Invite top vendors from Discover to fill categories faster."
              />
            )}
          </TabsContent>
        </Tabs>
      </div>
    </PanelContent>
  )
}

export default function OrganizerVendorsPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerVendorsContent />
    </Suspense>
  )
}
