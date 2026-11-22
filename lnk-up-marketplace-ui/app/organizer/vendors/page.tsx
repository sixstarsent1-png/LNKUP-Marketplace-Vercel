"use client"

import { Suspense, useEffect, useMemo, useState } from "react"
import dynamic from "next/dynamic"
import { Search, Store, Users, ListPlus, Send, LogIn } from "lucide-react"
import Link from "next/link"
import { useSearchParams } from "next/navigation"
import { isLiveApiEnabled } from "@/lib/api/config"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { vendors, vendorLists, vendorInvites } from "@/lib/data"
import type { OrganizerVendorDirectoryItem } from "@/lib/api/organizer-crm"
import {
  filterOrganizerRequestsByStatus,
  useOrganizerRequests,
} from "@/lib/hooks/use-organizer-requests"
import { useOrganizerInvites, useOrganizerLists, useOrganizerVendorDirectory } from "@/lib/hooks/use-organizer-crm"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { useOrganizerVendorProfile } from "@/lib/hooks/use-organizer-vendor-profile"
import { usePanelState, type PanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { VendorCard } from "@/components/marketplace/vendor-card"
import { OrganizerVendorRequestCard } from "@/components/organizer/organizer-vendor-request-card"
import { FindVendorsPanel } from "@/components/organizer/find-vendors-panel"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Input } from "@/components/ui/input"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"
import { StatusBadge } from "@/components/marketplace/badges"
import { vendorCategories } from "@/lib/data"

const OrganizerVendorProfileDrawer = dynamic(
  () =>
    import("@/components/organizer/organizer-vendor-profile-drawer").then((m) => ({
      default: m.OrganizerVendorProfileDrawer,
    })),
  { ssr: false },
)

function OrganizerVendorsContent() {
  const live = isLiveApiEnabled()
  const searchParams = useSearchParams()
  const eventFilter = searchParams.get("event")
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { items, loading, error, unauthenticated, reviewUnavailable, queueBuckets, retry } = useOrganizerRequests()
  const [query, setQuery] = useState("")
  const [discoverCity, setDiscoverCity] = useState("")
  const [discoverCategory, setDiscoverCategory] = useState("")
  const [discoverVerified, setDiscoverVerified] = useState(false)
  const [directorySort, setDirectorySort] = useState<"score" | "rating" | "completed" | "reliable">("score")
  const [profileRequest, setProfileRequest] = useState<OrganizerVendorRequest | null>(null)
  const [discoverProfileId, setDiscoverProfileId] = useState<string | null>(null)
  const [profileOpen, setProfileOpen] = useState(false)

  const profileId = profileRequest?.vendorProfileId ?? profileRequest?.vendorId ?? discoverProfileId
  const { profile, timeline, notes, loading: profileLoading } = useOrganizerVendorProfile(
    profileId,
    profileRequest?.applicationId ?? profileRequest?.id,
    profileOpen,
  )
  const { items: directory, loading: directoryLoading, retry: retryDirectory } = useOrganizerVendorDirectory(live)
  const { items: liveLists, loading: listsLoading } = useOrganizerLists(live)
  const { items: liveInvites, loading: invitesLoading } = useOrganizerInvites(live)

  const filteredItems = useMemo(() => {
    let list = items
    if (eventFilter) {
      list = list.filter((r) => r.eventId === eventFilter)
    }
    return list
  }, [items, eventFilter])

  const pending = filterOrganizerRequestsByStatus(filteredItems, "under_review")
  const approved = filterOrganizerRequestsByStatus(filteredItems, "approved")
  const atRiskCount = useMemo(
    () =>
      filteredItems.filter(
        (request) =>
          request.documentsStatus === "missing" ||
          request.documentsStatus === "pending" ||
          request.status === "payment_required",
      ).length,
    [filteredItems],
  )

  const qaOverride = qaState !== "content"

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (live && unauthenticated) return "content"
    if (filteredItems.length === 0) return "empty"
    return "content"
  }, [qaState, live, loading, error, unauthenticated, filteredItems.length])

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
  }

  function handleViewProfile(request: OrganizerVendorRequest) {
    setDiscoverProfileId(null)
    setProfileRequest(request)
    setProfileOpen(true)
    trackLnkupEvent({
      event: "organizer_vendor_profile_opened",
      source: "organizer_vendors_page",
      requestId: request.id,
    })
  }

  function handleDiscoverViewProfile(vendorProfileId: string) {
    setProfileRequest(null)
    setDiscoverProfileId(vendorProfileId)
    setProfileOpen(true)
    trackLnkupEvent({
      event: "organizer_vendor_profile_opened",
      source: "organizer_vendor_discover",
      metadata: { profile_id: vendorProfileId },
    })
  }

  useEffect(() => {
    if (profileOpen && profile && !profileLoading) {
      trackLnkupEvent({
        event: "organizer_reputation_preview_viewed",
        source: "organizer_profile_drawer",
        metadata: {
          tier: profile.reputation?.tier ?? "unknown",
        },
      })
    }
  }, [profileOpen, profile, profileLoading])

  useEffect(() => {
    if (!live || directoryLoading) return
    trackLnkupEvent({
      event: "organizer_vendor_directory_viewed",
      source: "organizer_vendors_page",
      metadata: { count: directory.length },
    })
  }, [live, directoryLoading, directory.length])

  useEffect(() => {
    if (atRiskCount <= 0) return
    trackLnkupEvent({
      event: "organizer_vendor_risk_viewed",
      source: "organizer_vendors_page",
      metadata: { at_risk_count: atRiskCount },
    })
  }, [atRiskCount])

  useEffect(() => {
    if (!live) return
    const handle = setTimeout(() => {
      void retryDirectory({
        q: query.trim() || undefined,
        city: discoverCity.trim() || undefined,
        category: discoverCategory || undefined,
        verified: discoverVerified || undefined,
        sort: directorySort,
      })
      trackLnkupEvent({
        event: "filter_changed",
        source: "organizer_vendor_discover",
        metadata: {
          filter: "discover_search",
          city: discoverCity,
          category: discoverCategory,
          verified: discoverVerified,
          sort: directorySort,
        },
      })
    }, 300)
    return () => clearTimeout(handle)
  }, [live, query, discoverCity, discoverCategory, discoverVerified, directorySort, retryDirectory])

  const discoverFiltered = useMemo(() => {
    const source: OrganizerVendorDirectoryItem[] = live
      ? directory
      : vendors.map((v) => ({
          profileId: v.id,
          businessName: v.name,
          category: v.category,
          city: v.city,
          state: v.state,
          rating: v.rating,
          completedEvents: v.completedEvents,
          trustBadges: v.verified ? ["verified"] : [],
        }))

    if (live) return source

    const filtered = source.filter((v) => {
      const matchesQuery =
        !query ||
        v.businessName.toLowerCase().includes(query.toLowerCase()) ||
        String(v.category ?? "").toLowerCase().includes(query.toLowerCase()) ||
        String(v.city ?? "").toLowerCase().includes(query.toLowerCase())
      const matchesCity =
        !discoverCity || String(v.city ?? "").toLowerCase().includes(discoverCity.toLowerCase())
      const matchesCategory =
        !discoverCategory || String(v.category ?? "").toLowerCase().includes(discoverCategory.toLowerCase())
      const matchesVerified =
        !discoverVerified || Boolean(v.trustBadges?.includes("verified"))
      return matchesQuery && matchesCity && matchesCategory && matchesVerified
    })
    if (directorySort === "rating") {
      return [...filtered].sort((a, b) => (b.rating ?? 0) - (a.rating ?? 0))
    }
    if (directorySort === "completed") {
      return [...filtered].sort((a, b) => (b.completedEvents ?? 0) - (a.completedEvents ?? 0))
    }
    return filtered
  }, [live, directory, query, discoverCity, discoverCategory, discoverVerified, directorySort])

  const listItems = live ? liveLists : vendorLists.map((list) => ({
    id: list.id,
    name: list.name,
    count: list.count,
    updatedAt: list.updatedAt,
  }))
  const inviteItems = live
    ? liveInvites
    : vendorInvites.map((invite) => ({
        id: invite.id,
        vendor: invite.vendor,
        event: invite.event,
        sentAt: invite.sentAt,
        status: invite.status === "accepted" ? "accepted" : "pending",
      }))

  return (
    <div className="flex flex-col gap-6">
      <PageHeader title="Vendors" subtitle="Review requests and discover vendors to invite." />

      <Card className="rounded-2xl">
        <CardContent className="py-4">
          <div className="flex flex-wrap items-center gap-2 text-xs">
            <StatusBadge variant="review">All {queueBuckets.all}</StatusBadge>
            <StatusBadge variant="review">Submitted {queueBuckets.submitted}</StatusBadge>
            <StatusBadge variant="review">Under review {queueBuckets.underReview}</StatusBadge>
            <StatusBadge variant="due">Approved {queueBuckets.approved}</StatusBadge>
            <StatusBadge variant="due">Payment required {queueBuckets.paymentRequired}</StatusBadge>
            <StatusBadge variant="rejected">At risk {atRiskCount}</StatusBadge>
          </div>
        </CardContent>
      </Card>

      {live && unauthenticated && !qaOverride && panelState !== "loading" && (
        <Card className="rounded-2xl border-dashed">
          <CardContent className="flex items-center justify-between gap-3 py-4">
            <div className="flex items-center gap-3">
              <LogIn className="size-5 text-muted-foreground" />
              <p className="text-sm">Sign in to review live vendor requests.</p>
            </div>
            <Button size="sm" nativeButton={false} render={<Link href="/login?redirect=/organizer/vendors" />}>
              Sign in
            </Button>
          </CardContent>
        </Card>
      )}

      <Tabs defaultValue="requests">
        <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
          <TabsList variant="line" className="w-max">
            <TabsTrigger value="requests">Requests ({pending.length})</TabsTrigger>
            <TabsTrigger value="find">Find Vendors</TabsTrigger>
            <TabsTrigger value="discover">Discover</TabsTrigger>
            <TabsTrigger value="approved">Approved ({approved.length})</TabsTrigger>
            <TabsTrigger value="lists">Lists</TabsTrigger>
            <TabsTrigger value="invites">Invites</TabsTrigger>
          </TabsList>
        </div>

        <TabsContent value="requests" className="mt-4">
          <PanelContent
            state={panelState}
            retry={handleRetry}
            empty={
              <EmptyState
                icon={Store}
                title="No pending requests"
                description="Vendor applications will appear here as they come in."
              />
            }
          >
            <div className="flex flex-col gap-3">
              {reviewUnavailable && (
                <p className="text-sm text-muted-foreground">
                  Review actions are available in the LNKup web dashboard.
                </p>
              )}
              {pending.map((r) => (
                <OrganizerVendorRequestCard
                  key={r.id}
                  request={r}
                  reviewDisabled={reviewUnavailable}
                  onReviewed={retry}
                  onViewProfile={handleViewProfile}
                />
              ))}
            </div>
          </PanelContent>
        </TabsContent>

        <TabsContent value="find" className="mt-4">
          {live ? (
            <FindVendorsPanel initialEventId={eventFilter} />
          ) : (
            <EmptyState
              icon={Search}
              title="Find Vendors uses live data"
              description="Connect to LNKup to see vendors that match your events."
            />
          )}
        </TabsContent>

        <TabsContent value="discover" className="mt-4 flex flex-col gap-4">
          <div className="relative">
            <Search className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              placeholder="Search vendors by name"
              value={query}
              onChange={(e) => {
                setQuery(e.target.value)
                trackLnkupEvent({
                  event: "search_performed",
                  source: "organizer_vendor_discover",
                  metadata: { length: e.target.value.length },
                })
              }}
              className="pl-9"
              data-testid="organizer-discover-search"
            />
          </div>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4" data-testid="organizer-discover-filters">
            <Input
              placeholder="City"
              value={discoverCity}
              onChange={(e) => setDiscoverCity(e.target.value)}
              data-testid="organizer-discover-city"
            />
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={discoverCategory}
              onChange={(e) => setDiscoverCategory(e.target.value)}
              data-testid="organizer-discover-category"
            >
              <option value="">All categories</option>
              {vendorCategories.map((cat) => (
                <option key={cat} value={cat}>
                  {cat}
                </option>
              ))}
            </select>
            <select
              className="h-9 rounded-md border bg-background px-2 text-sm"
              value={directorySort}
              onChange={(e) => {
                const sort = e.target.value as typeof directorySort
                setDirectorySort(sort)
                trackLnkupEvent({
                  event: "filter_changed",
                  source: "organizer_vendor_discover",
                  metadata: { filter: "sort", value: sort },
                })
              }}
              data-testid="organizer-discover-sort"
            >
              <option value="score">Best match</option>
              <option value="rating">Top rated</option>
              <option value="completed">Most completed</option>
              <option value="reliable">Most reliable</option>
            </select>
            <label className="flex h-9 items-center gap-2 rounded-md border px-3 text-sm">
              <input
                type="checkbox"
                checked={discoverVerified}
                onChange={(e) => setDiscoverVerified(e.target.checked)}
                data-testid="organizer-discover-verified"
              />
              Verified only
            </label>
          </div>
          <p className="text-xs text-muted-foreground">
            {live ? "Live vendor directory" : "Showing mock vendor directory"}
          </p>

          {directoryLoading ? (
            <SkeletonList count={2} />
          ) : discoverFiltered.length > 0 ? (
            <div className="grid gap-4 md:grid-cols-2">
              {discoverFiltered.map((v) => (
                <VendorCard
                  key={v.profileId}
                  vendor={{
                    id: v.profileId,
                    name: v.businessName,
                    category: v.category ?? "Vendor",
                    city: v.city ?? "",
                    state: v.state ?? "",
                    rating: v.rating ?? 0,
                    completedEvents: v.completedEvents ?? 0,
                    reviews: 0,
                    verified: Boolean(v.trustBadges?.includes("verified")),
                    documentsVerified: Boolean(v.trustBadges?.includes("documents_ready")),
                    insured: Boolean(v.trustBadges?.includes("insured")),
                    avatarColor: "bg-primary",
                    initials: v.businessName.slice(0, 2).toUpperCase(),
                  }}
                  onViewProfile={handleDiscoverViewProfile}
                />
              ))}
            </div>
          ) : (
            <EmptyState
              icon={Search}
              title="No vendors found"
              description="Try a different search term or category."
              action={
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() =>
                    retryDirectory({
                      q: query.trim() || undefined,
                      city: discoverCity.trim() || undefined,
                      category: discoverCategory || undefined,
                      verified: discoverVerified || undefined,
                      sort: directorySort,
                    })
                  }
                >
                  Refresh directory
                </Button>
              }
            />
          )}
        </TabsContent>

        <TabsContent value="approved" className="mt-4 flex flex-col gap-3">
          {approved.length > 0 ? (
            approved.map((r) => (
              <OrganizerVendorRequestCard
                key={r.id}
                request={r}
                reviewDisabled
                onViewProfile={handleViewProfile}
              />
            ))
          ) : (
            <EmptyState
              icon={Users}
              title="No approved vendors yet"
              description="Approved vendors waiting on payment will show here."
            />
          )}
        </TabsContent>

        <TabsContent value="lists" className="mt-4 flex flex-col gap-4">
          <p className="text-xs text-muted-foreground">
            {live ? "Live list snapshots from CRM" : "Available on LNKup web"}
          </p>
          <div className="flex justify-end">
            <Button
              size="sm"
              disabled={!live}
              onClick={() =>
                trackLnkupEvent({
                  event: "organizer_list_action_clicked",
                  source: "organizer_vendors_lists",
                  metadata: { action: "new_list" },
                })
              }
            >
              <ListPlus data-icon="inline-start" />
              New list
            </Button>
          </div>
          {listsLoading ? (
            <SkeletonList count={2} />
          ) : listItems.length > 0 ? (
            <div className="flex flex-col gap-3">
              {listItems.map((list) => (
                <Card key={list.id} className="rounded-2xl">
                  <CardContent className="flex items-center gap-3 py-4">
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                      <ListPlus className="size-5 text-muted-foreground" />
                    </span>
                    <div className="flex flex-1 flex-col">
                      <span className="text-sm font-semibold">{list.name}</span>
                      <span className="text-xs text-muted-foreground">
                        {list.count} vendors · Updated {list.updatedAt ?? "recently"}
                      </span>
                    </div>
                    <Button
                      size="sm"
                      variant="outline"
                      disabled
                      onClick={() =>
                        trackLnkupEvent({
                          event: "organizer_list_action_clicked",
                          source: "organizer_vendors_lists",
                          metadata: { list_id: list.id },
                        })
                      }
                    >
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
          <p className="text-xs text-muted-foreground">
            CRM Invites send registered vendors to your events. To recruit outside organizers and earn LNKup fees for 12 months, use Referral Invites on Home.
          </p>
          <p className="text-xs text-muted-foreground">
            {live ? "Live invite snapshots from CRM" : "Available on LNKup web"}
          </p>
          <div className="flex justify-end">
            <Button
              size="sm"
              disabled={!live}
              onClick={() =>
                trackLnkupEvent({
                  event: "organizer_invite_action_clicked",
                  source: "organizer_vendors_invites",
                  metadata: { action: "invite_vendor" },
                })
              }
            >
              <Send data-icon="inline-start" />
              Invite vendor
            </Button>
          </div>
          {invitesLoading ? (
            <SkeletonList count={2} />
          ) : inviteItems.length > 0 ? (
            <Card className="rounded-2xl">
              <CardContent className="flex flex-col pt-4">
                {inviteItems.map((invite, i) => (
                  <div key={invite.id}>
                    {i > 0 && <Separator className="my-1" />}
                    <div className="flex items-center gap-3 py-3">
                      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted">
                        <Send className="size-5 text-muted-foreground" />
                      </span>
                      <div className="flex flex-1 flex-col">
                        <span className="text-sm font-semibold">{invite.vendor}</span>
                        <span className="text-xs text-muted-foreground">
                          {invite.event} · Sent {invite.sentAt ?? "recently"}
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

      <OrganizerVendorProfileDrawer
        open={profileOpen}
        onOpenChange={setProfileOpen}
        profile={profile}
        timeline={timeline}
        notes={notes}
        loading={profileLoading}
      />
    </div>
  )
}

export default function OrganizerVendorsPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerVendorsContent />
    </Suspense>
  )
}
