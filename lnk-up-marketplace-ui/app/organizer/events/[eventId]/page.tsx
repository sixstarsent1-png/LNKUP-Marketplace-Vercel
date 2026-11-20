"use client"

import { Suspense, use, useEffect, useMemo, useState } from "react"
import Link from "next/link"
import { ArrowLeft, ExternalLink, MapPin, Calendar, Users } from "lucide-react"
import { getOrganizerDashboardUrl, getOrganizerFinancialUrl } from "@/lib/api/config"
import { useOrganizerEventWorkspace } from "@/lib/hooks/use-organizer-event-workspace"
import { useOrganizerEventMoney } from "@/lib/hooks/use-organizer-event-money"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { ShareApplicationPanel } from "@/components/organizer/share-application-panel"
import { LaunchChecklist } from "@/components/marketplace/launch-checklist"
import { StatCard } from "@/components/marketplace/stat-card"
import { OrganizerRoleGate } from "@/components/organizer/organizer-role-gate"
import { PanelContent, SkeletonList, EmptyState, ErrorState } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { PaymentActivityList } from "@/components/financial/payment-activity-list"
import { MoneyStatCard } from "@/components/financial/money-stat-card"
import { Badge } from "@/components/ui/badge"
import { Input } from "@/components/ui/input"
import { useOrganizerRequests } from "@/lib/hooks/use-organizer-requests"
import { OrganizerVendorRequestCard } from "@/components/organizer/organizer-vendor-request-card"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import type { OrganizerEventWorkspace } from "@/lib/api/organizer-event-workspace"
import { vendorCategories } from "@/lib/data"

function EventDetailsEditor({
  workspace,
  onSaved,
}: {
  workspace: OrganizerEventWorkspace
  onSaved: () => void
}) {
  const [title, setTitle] = useState(workspace.title)
  const [date, setDate] = useState(workspace.date ?? "")
  const [city, setCity] = useState(workspace.city ?? "")
  const [state, setState] = useState(workspace.state ?? "")
  const [venue, setVenue] = useState(workspace.venue ?? "")
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setTitle(workspace.title)
    setDate(workspace.date ?? "")
    setCity(workspace.city ?? "")
    setState(workspace.state ?? "")
    setVenue(workspace.venue ?? "")
  }, [workspace.title, workspace.date, workspace.city, workspace.state, workspace.venue, workspace.eventId])

  async function handleSave() {
    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      const response = await fetch(`/api/organizer/events/${encodeURIComponent(workspace.eventId)}`, {
        method: "PATCH",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          event_name: title,
          event_date: date || undefined,
          city: city || undefined,
          state: state || undefined,
          venue_name: venue || undefined,
        }),
      })
      const { data, error: apiError } = await parseApiResponse<{ message?: string }>(response)
      if (apiError || !response.ok) {
        setError(apiError?.message ?? "Could not update event.")
        return
      }
      setMessage(data?.message ?? "Event updated.")
      onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="rounded-2xl" data-testid="lnkup-app-event-edit">
      <CardHeader>
        <CardTitle className="text-base">Edit event details</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-2">
        <label className="grid gap-1 text-sm sm:col-span-2">
          <span className="text-muted-foreground">Title</span>
          <Input value={title} onChange={(e) => setTitle(e.target.value)} data-testid="lnkup-app-edit-event-title" />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">Date</span>
          <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">Venue</span>
          <Input value={venue} onChange={(e) => setVenue(e.target.value)} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">City</span>
          <Input value={city} onChange={(e) => setCity(e.target.value)} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">State</span>
          <Input value={state} onChange={(e) => setState(e.target.value)} maxLength={2} />
        </label>
        <div className="flex flex-wrap items-center gap-2 sm:col-span-2">
          <Button type="button" onClick={handleSave} disabled={saving || !title.trim()} data-testid="lnkup-app-edit-event-save">
            {saving ? "Saving…" : "Save event"}
          </Button>
          {message && <p className="text-sm text-emerald-700">{message}</p>}
          {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
      </CardContent>
    </Card>
  )
}

function AddOpportunityForm({
  eventId,
  onSaved,
}: {
  eventId: string
  onSaved: () => void
}) {
  const [category, setCategory] = useState<string>(vendorCategories[0] ?? "Food Vendor")
  const [fee, setFee] = useState("200")
  const [spots, setSpots] = useState("10")
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  async function handleAdd() {
    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      const response = await fetch(`/api/organizer/events/${encodeURIComponent(eventId)}/opportunities`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          opportunities: [
            {
              categories: [category],
              vendor_category: category,
              vendor_fee: Number(fee),
              spots_total: Number(spots),
              opportunity_title: category,
            },
          ],
        }),
      })
      const { data, error: apiError } = await parseApiResponse<{ message?: string }>(response)
      if (apiError || !response.ok) {
        setError(apiError?.message ?? "Could not add opportunity.")
        return
      }
      setMessage(data?.message ?? "Opportunity added.")
      onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <Card className="rounded-2xl border-dashed" data-testid="lnkup-app-add-opportunity">
      <CardHeader>
        <CardTitle className="text-base">Add opportunity</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-3 sm:grid-cols-[1.4fr_1fr_1fr_auto] sm:items-end">
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">Category</span>
          <select
            className="h-9 rounded-md border bg-background px-2 text-sm"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
          >
            {vendorCategories.map((cat) => (
              <option key={cat} value={cat}>
                {cat}
              </option>
            ))}
          </select>
        </label>
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">Vendor fee</span>
          <Input type="number" min={0} step="0.01" value={fee} onChange={(e) => setFee(e.target.value)} />
        </label>
        <label className="grid gap-1 text-sm">
          <span className="text-muted-foreground">Spots</span>
          <Input type="number" min={1} value={spots} onChange={(e) => setSpots(e.target.value)} />
        </label>
        <Button type="button" onClick={handleAdd} disabled={saving} data-testid="lnkup-app-add-opportunity-save">
          {saving ? "Adding…" : "Add"}
        </Button>
        {message && <p className="text-sm text-emerald-700 sm:col-span-4">{message}</p>}
        {error && <p className="text-sm text-destructive sm:col-span-4">{error}</p>}
      </CardContent>
    </Card>
  )
}

function OpportunityPricingEditor({
  opportunity,
  onSaved,
}: {
  opportunity: OrganizerEventWorkspace["opportunities"][number]
  onSaved: () => void
}) {
  const [fee, setFee] = useState(String(opportunity.vendorFee ?? 0))
  const [spots, setSpots] = useState(String(opportunity.spotsTotal ?? 1))
  const [saving, setSaving] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setFee(String(opportunity.vendorFee ?? 0))
    setSpots(String(opportunity.spotsTotal ?? 1))
  }, [opportunity.vendorFee, opportunity.spotsTotal, opportunity.id])

  async function handleSave() {
    setSaving(true)
    setError(null)
    setMessage(null)
    try {
      const response = await fetch(`/api/organizer/opportunities/${encodeURIComponent(opportunity.id)}/pricing`, {
        method: "POST",
        headers: { "content-type": "application/json" },
        body: JSON.stringify({
          vendor_fee: Number(fee),
          spots_total: Number(spots),
        }),
      })
      const { data, error: apiError } = await parseApiResponse<{
        message?: string
        vendor_fee?: number
        spots_total?: number
      }>(response)
      if (apiError || !response.ok) {
        setError(apiError?.message ?? "Could not update fee and spots.")
        return
      }
      setMessage(data?.message ?? "Vendor fee and spots updated.")
      if (data?.vendor_fee != null) setFee(String(data.vendor_fee))
      if (data?.spots_total != null) setSpots(String(data.spots_total))
      onSaved()
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="mt-3 grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
      <label className="grid gap-1 text-sm">
        <span className="text-muted-foreground">Vendor fee</span>
        <Input
          type="number"
          min={0}
          step="0.01"
          value={fee}
          onChange={(e) => setFee(e.target.value)}
          data-testid="lnkup-app-edit-opp-fee"
        />
      </label>
      <label className="grid gap-1 text-sm">
        <span className="text-muted-foreground">Spots</span>
        <Input
          type="number"
          min={1}
          step={1}
          value={spots}
          onChange={(e) => setSpots(e.target.value)}
          data-testid="lnkup-app-edit-opp-spots"
        />
      </label>
      <Button type="button" onClick={handleSave} disabled={saving} data-testid="lnkup-app-edit-opp-save">
        {saving ? "Saving…" : "Save fee & spots"}
      </Button>
      {message && <p className="text-sm text-emerald-700 sm:col-span-3">{message}</p>}
      {error && <p className="text-sm text-destructive sm:col-span-3">{error}</p>}
    </div>
  )
}

function EventWorkspaceContent({ eventId }: { eventId: string }) {
  const { workspace, loading, error, notFound, forbidden, reload } = useOrganizerEventWorkspace(eventId)
  const { summary: eventMoney, loading: eventMoneyLoading } = useOrganizerEventMoney(eventId, Boolean(workspace))
  const { items: eventRequests, reviewUnavailable, retry: retryRequests } = useOrganizerRequests(Boolean(workspace))
  const wpUrl = workspace?.wordpressUrl ?? getOrganizerDashboardUrl()
  const financialUrl = getOrganizerFinancialUrl()

  useEffect(() => {
    if (workspace) {
      trackLnkupEvent({
        event: "organizer_event_workspace_viewed",
        source: "event_workspace",
        eventId: workspace.eventId,
      })
    }
  }, [workspace])

  useEffect(() => {
    if (eventMoney) {
      trackLnkupEvent({
        event: "organizer_event_money_viewed",
        source: "event_workspace_payments",
        eventId,
      })
    }
  }, [eventId, eventMoney])

  if (forbidden) {
    return <OrganizerRoleGate variant="forbidden" />
  }

  if (notFound) {
    return (
      <EmptyState
        icon={Calendar}
        title="Event not found"
        description="This event could not be loaded."
        action={
          <Button nativeButton={false} render={<Link href="/organizer/events" />}>
            Back to events
          </Button>
        }
      />
    )
  }

  const panelState = loading ? "loading" : error ? "error" : workspace ? "content" : "empty"
  const requestsForEvent = eventRequests.filter((request) => request.eventId === eventId)
  const [requestFilter, setRequestFilter] = useState<"all" | "payment_required" | "paid" | "under_review">("all")
  const [requestSort, setRequestSort] = useState<"newest" | "oldest">("newest")
  const [selectedRequestIds, setSelectedRequestIds] = useState<string[]>([])
  const [bulkActionLoading, setBulkActionLoading] = useState<"approve" | "waitlist" | null>(null)
  const visibleRequests = useMemo(() => {
    const filtered = requestsForEvent.filter((request) => {
      if (requestFilter === "all") return true
      if (requestFilter === "payment_required") {
        return request.status === "payment_required" || request.paymentStatus === "pending"
      }
      if (requestFilter === "paid") {
        return request.status === "paid" || request.status === "completed" || request.paymentStatus === "paid"
      }
      return request.status === "under_review" || request.status === "submitted"
    })
    const direction = requestSort === "newest" ? -1 : 1
    return [...filtered].sort((a, b) => {
      const aDate = Date.parse(a.submittedAt ?? "")
      const bDate = Date.parse(b.submittedAt ?? "")
      if (Number.isNaN(aDate) || Number.isNaN(bDate)) return 0
      return (aDate - bDate) * direction
    })
  }, [requestFilter, requestSort, requestsForEvent])

  async function handleBulkReview(action: "approve" | "waitlist") {
    if (selectedRequestIds.length === 0) return
    setBulkActionLoading(action)
    try {
      await Promise.all(
        selectedRequestIds.map(async (id) => {
          await fetch(`/api/organizer/requests/${id}/review`, {
            method: "POST",
            headers: { "content-type": "application/json" },
            body: JSON.stringify({ action }),
          })
        }),
      )
      setSelectedRequestIds([])
      retryRequests()
    } finally {
      setBulkActionLoading(null)
    }
  }

  return (
    <PanelContent
      state={panelState}
      retry={() => {
        reload()
        retryRequests()
      }}
    >
      {workspace && (
        <div className="flex flex-col gap-6" data-testid="organizer-event-workspace">
          <Button variant="ghost" size="sm" className="w-fit -ml-2" nativeButton={false} render={<Link href="/organizer/events" />}>
            <ArrowLeft data-icon="inline-start" />
            Back to events
          </Button>

          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h1 className="font-heading text-2xl font-bold">{workspace.title}</h1>
              <div className="mt-1 flex flex-wrap gap-3 text-sm text-muted-foreground">
                {workspace.date && (
                  <span className="flex items-center gap-1">
                    <Calendar className="size-4" />
                    {workspace.date}
                  </span>
                )}
                {(workspace.city || workspace.state) && (
                  <span className="flex items-center gap-1">
                    <MapPin className="size-4" />
                    {[workspace.city, workspace.state].filter(Boolean).join(", ")}
                  </span>
                )}
                {workspace.expectedAttendance && (
                  <span className="flex items-center gap-1">
                    <Users className="size-4" />
                    {workspace.expectedAttendance.toLocaleString()} expected
                  </span>
                )}
              </div>
            </div>
            {wpUrl && (
              <Button
                variant="outline"
                size="sm"
                nativeButton={false}
                render={<a href={wpUrl} target="_blank" rel="noopener noreferrer" />}
                onClick={() =>
                  trackLnkupEvent({ event: "wordpress_dashboard_opened", source: "event_workspace" })
                }
              >
                Open LNKup dashboard
                <ExternalLink data-icon="inline-end" />
              </Button>
            )}
          </div>

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Requests" value={workspace.stats.requests} />
            <StatCard label="Approved" value={workspace.stats.approved} />
            <StatCard label="Payment required" value={workspace.stats.paymentRequired} />
            <StatCard label="Paid / booked" value={workspace.stats.paid} />
          </div>

          <Tabs defaultValue="overview">
            <TabsList className="w-full justify-start overflow-x-auto">
              <TabsTrigger value="overview">Overview</TabsTrigger>
              <TabsTrigger value="opportunities">Opportunities</TabsTrigger>
              <TabsTrigger value="requests">Vendor Requests</TabsTrigger>
              <TabsTrigger value="payments">Payments</TabsTrigger>
              <TabsTrigger value="packet">Packet</TabsTrigger>
              <TabsTrigger value="handoff">Web</TabsTrigger>
            </TabsList>

            <TabsContent value="overview" className="mt-4 flex flex-col gap-4">
              <ShareApplicationPanel workspace={workspace} />
              <EventDetailsEditor workspace={workspace} onSaved={reload} />
              {workspace.launchChecklist && (
                <LaunchChecklist
                  title="Launch checklist"
                  items={workspace.launchChecklist.map((item) => ({
                    label: item.label,
                    done: item.complete,
                    href: item.href,
                  }))}
                />
              )}
            </TabsContent>

            <TabsContent value="opportunities" className="mt-4">
              {workspace.opportunities.length === 0 ? (
                <EmptyState
                  icon={Users}
                  title="No opportunities yet"
                  description="Add a vendor opportunity so vendors can apply."
                />
              ) : (
                <div className="grid gap-3">
                  {workspace.opportunities.map((opp) => (
                    <Card key={opp.id} className="rounded-2xl">
                      <CardContent className="flex flex-col gap-2 py-4">
                        <p className="font-semibold">{opp.title}</p>
                        <p className="text-sm text-muted-foreground">
                          {opp.categories?.join(", ")} · ${opp.vendorFee} · {opp.spotsRemaining ?? opp.spotsTotal}/
                          {opp.spotsTotal} spots
                        </p>
                        {opp.applicationDeadline && (
                          <Badge variant="outline">Deadline {opp.applicationDeadline}</Badge>
                        )}
                        <OpportunityPricingEditor opportunity={opp} onSaved={reload} />
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}
              <div className="mt-4">
                <AddOpportunityForm eventId={workspace.eventId} onSaved={reload} />
              </div>
            </TabsContent>

            <TabsContent value="requests" className="mt-4">
              <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex flex-wrap gap-2" data-testid="organizer-request-filters">
                    <Button
                      size="sm"
                      variant={requestFilter === "all" ? "default" : "outline"}
                      onClick={() => setRequestFilter("all")}
                    >
                      All
                    </Button>
                    <Button
                      size="sm"
                      variant={requestFilter === "payment_required" ? "default" : "outline"}
                      onClick={() => setRequestFilter("payment_required")}
                    >
                      Payment required
                    </Button>
                    <Button
                      size="sm"
                      variant={requestFilter === "paid" ? "default" : "outline"}
                      onClick={() => setRequestFilter("paid")}
                    >
                      Paid
                    </Button>
                    <Button
                      size="sm"
                      variant={requestFilter === "under_review" ? "default" : "outline"}
                      onClick={() => setRequestFilter("under_review")}
                    >
                      Under review
                    </Button>
                  </div>
                  <div className="flex items-center gap-2 text-sm">
                    <label htmlFor="request-sort" className="text-muted-foreground">
                      Sort
                    </label>
                    <select
                      id="request-sort"
                      className="rounded-md border bg-background px-2 py-1"
                      value={requestSort}
                      onChange={(event) => setRequestSort(event.target.value as "newest" | "oldest")}
                      data-testid="organizer-request-sort"
                    >
                      <option value="newest">Newest first</option>
                      <option value="oldest">Oldest first</option>
                    </select>
                  </div>
                </div>
                {reviewUnavailable && (
                  <Card className="rounded-2xl border-dashed">
                    <CardContent className="flex flex-wrap items-center justify-between gap-3 py-4">
                      <p className="text-sm text-muted-foreground">
                        Review actions are available in the LNKup web dashboard.
                      </p>
                      {wpUrl ? (
                        <Button
                          variant="outline"
                          size="sm"
                          nativeButton={false}
                          render={<a href={wpUrl} target="_blank" rel="noopener noreferrer" />}
                        >
                          Open web review queue
                        </Button>
                      ) : null}
                    </CardContent>
                  </Card>
                )}
                {!reviewUnavailable && visibleRequests.length > 0 ? (
                  <div className="flex flex-wrap items-center gap-2 rounded-xl border p-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        if (selectedRequestIds.length === visibleRequests.length) {
                          setSelectedRequestIds([])
                        } else {
                          setSelectedRequestIds(visibleRequests.map((request) => request.id))
                        }
                      }}
                    >
                      {selectedRequestIds.length === visibleRequests.length ? "Clear" : "Select"} all
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => void handleBulkReview("approve")}
                      disabled={selectedRequestIds.length === 0 || bulkActionLoading !== null}
                    >
                      {bulkActionLoading === "approve"
                        ? "Approving..."
                        : `Approve selected (${selectedRequestIds.length})`}
                    </Button>
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => void handleBulkReview("waitlist")}
                      disabled={selectedRequestIds.length === 0 || bulkActionLoading !== null}
                    >
                      {bulkActionLoading === "waitlist"
                        ? "Waitlisting..."
                        : `Waitlist selected (${selectedRequestIds.length})`}
                    </Button>
                  </div>
                ) : null}
                {visibleRequests.length > 0 ? (
                  <>
                    {visibleRequests.map((request) => (
                      <div key={request.id} className="flex gap-2">
                        {!reviewUnavailable ? (
                          <input
                            type="checkbox"
                            aria-label={`Select request ${request.id}`}
                            checked={selectedRequestIds.includes(request.id)}
                            onChange={(event) => {
                              setSelectedRequestIds((current) =>
                                event.target.checked
                                  ? [...new Set([...current, request.id])]
                                  : current.filter((item) => item !== request.id),
                              )
                            }}
                            className="mt-4 size-4 rounded border"
                          />
                        ) : null}
                        <div className="flex-1">
                          <OrganizerVendorRequestCard
                            request={request}
                            reviewDisabled={reviewUnavailable}
                            onReviewed={retryRequests}
                          />
                        </div>
                      </div>
                    ))}
                    <Button variant="outline" nativeButton={false} render={<Link href={`/organizer/vendors?event=${eventId}`} />}>
                      View full request queue
                    </Button>
                  </>
                ) : (
                  <Card className="rounded-2xl">
                    <CardContent className="flex flex-col gap-3 py-6">
                      <p className="text-sm text-muted-foreground">
                        {workspace.stats.requests} vendor request{workspace.stats.requests === 1 ? "" : "s"} for this event.
                      </p>
                      <Button nativeButton={false} render={<Link href={`/organizer/vendors?event=${eventId}`} />}>
                        Review vendor requests
                      </Button>
                    </CardContent>
                  </Card>
                )}
              </div>
            </TabsContent>

            <TabsContent value="payments" className="mt-4" data-testid="organizer-event-payments">
              <Card className="rounded-2xl">
                <CardHeader>
                  <CardTitle className="text-base">Payments snapshot</CardTitle>
                </CardHeader>
                <CardContent className="flex flex-col gap-4">
                  {eventMoneyLoading ? (
                    <SkeletonList count={2} />
                  ) : eventMoney ? (
                    <>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <MoneyStatCard label="Collected" amount={eventMoney.collected} currency={eventMoney.currency} />
                        <MoneyStatCard label="Pending" amount={eventMoney.pending} currency={eventMoney.currency} tone="warning" />
                      </div>
                      <p className="text-sm text-muted-foreground">
                        {eventMoney.paidVendorCount ?? 0} paid · {eventMoney.paymentRequiredCount ?? 0} payment required
                      </p>
                      {eventMoney.recentActivity && eventMoney.recentActivity.length > 0 && (
                        <PaymentActivityList
                          items={eventMoney.recentActivity}
                          emptyMessage="No recent activity for this event."
                        />
                      )}
                    </>
                  ) : (
                    <p className="text-sm text-muted-foreground">
                      Financial details are managed on LNKup web.
                    </p>
                  )}
                  <div className="flex flex-wrap gap-2">
                    <Button variant="outline" nativeButton={false} render={<Link href="/organizer/money" />}>
                      View all money
                    </Button>
                    {financialUrl && (
                      <Button
                        variant="outline"
                        nativeButton={false}
                        render={<a href={financialUrl} target="_blank" rel="noopener noreferrer" />}
                      >
                        Open Financial Center
                      </Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="packet" className="mt-4">
              <Card className="rounded-2xl">
                <CardContent className="py-6 text-sm text-muted-foreground">
                  <p>Load-in times, parking, and vendor packets are managed in LNKup Operations.</p>
                  {wpUrl && (
                    <Button className="mt-4" variant="outline" nativeButton={false} render={<a href={`${wpUrl}&view=operations`} target="_blank" rel="noopener noreferrer" />}>
                      Manage packets on LNKup web
                    </Button>
                  )}
                </CardContent>
              </Card>
            </TabsContent>

            <TabsContent value="handoff" className="mt-4">
              <Card className="rounded-2xl border-dashed">
                <CardContent className="flex flex-col gap-3 py-6 text-sm">
                  <p>Use the LNKup web dashboard for advanced event operations, maps, CSV import, and financial setup.</p>
                  {wpUrl && (
                    <Button nativeButton={false} render={<a href={wpUrl} target="_blank" rel="noopener noreferrer" />}>
                      Open LNKup Dashboard
                    </Button>
                  )}
                </CardContent>
              </Card>
            </TabsContent>
          </Tabs>
        </div>
      )}
      {error && !workspace && <ErrorState description={error} onRetry={reload} />}
    </PanelContent>
  )
}

export default function OrganizerEventPage({ params }: { params: Promise<{ eventId: string }> }) {
  const { eventId } = use(params)
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <EventWorkspaceContent eventId={eventId} />
    </Suspense>
  )
}
