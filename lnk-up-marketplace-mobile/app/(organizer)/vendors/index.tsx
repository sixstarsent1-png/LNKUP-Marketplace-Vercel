import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { ApproveIntelModal } from "@/components/organizer-vendors/approve-intel-modal"
import { InvitesList } from "@/components/organizer-vendors/invites-list"
import { fetchOrganizerInvites, type OrganizerInvite } from "@/lib/api/organizer-crm"
import { DiscoverDirectory } from "@/components/organizer-vendors/discover-directory"
import { InfoRequestSheet } from "@/components/organizer-vendors/info-request-sheet"
import { InviteSheet } from "@/components/organizer-vendors/invite-sheet"
import { RequestsQueue } from "@/components/organizer-vendors/requests-queue"
import {
  EMPTY_DISCOVER_FILTERS,
  matchesFilter,
  parseFilterKey,
  vendorProfileHref,
  type FilterKey,
  type SegmentKey,
} from "@/components/organizer-vendors/vendor-queue"
import { Screen } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { SegmentedControl, type ChipOption } from "@/components/ui/chip"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchApplicationIntel,
  fetchOrganizerRequests,
  reviewOrganizerRequest,
  type ApplicationIntel,
  type OrganizerReviewAction,
  type OrganizerVendorRequest,
} from "@/lib/api/organizer-requests"
import {
  fetchOrganizerVendorDirectory,
  type OrganizerDirectoryVendor,
  type OrganizerDiscoverFilters,
} from "@/lib/api/organizer-vendor-directory"
import { invalidateOrganizerMoney } from "@/lib/api/organizer-financial"
import { useAuth } from "@/lib/auth/auth-context"
import { promptText, showToast } from "@/lib/feedback/feedback"

export default function OrganizerVendorsScreen() {
  const { auth } = useAuth()
  const { event: eventParam, tab: tabParam, filter: filterParam } = useLocalSearchParams<{
    event?: string
    tab?: string
    filter?: string
  }>()
  const eventFilter = typeof eventParam === "string" && eventParam ? eventParam : null

  const initialSegment: SegmentKey =
    tabParam === "discover" || tabParam === "invites" ? tabParam : "requests"

  const [segment, setSegment] = useState<SegmentKey>(initialSegment)
  const [filter, setFilter] = useState<FilterKey>(() => parseFilterKey(filterParam) ?? "review")

  useEffect(() => {
    const next = parseFilterKey(filterParam)
    if (next) setFilter(next)
  }, [filterParam])
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [actingId, setActingId] = useState<string | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<OrganizerVendorRequest[]>([])

  const [discoverQuery, setDiscoverQuery] = useState("")
  const [discoverFilters, setDiscoverFilters] =
    useState<OrganizerDiscoverFilters>(EMPTY_DISCOVER_FILTERS)
  const [discoverLoading, setDiscoverLoading] = useState(false)
  const [discoverLoadingMore, setDiscoverLoadingMore] = useState(false)
  const [discoverError, setDiscoverError] = useState<AppErrorShape | null>(null)
  const [directory, setDirectory] = useState<OrganizerDirectoryVendor[]>([])
  const [discoverPage, setDiscoverPage] = useState(1)
  const [discoverPages, setDiscoverPages] = useState(1)
  const [discoverTotal, setDiscoverTotal] = useState(0)
  const [inviteVendor, setInviteVendor] = useState<OrganizerDirectoryVendor | null>(null)
  const [invites, setInvites] = useState<OrganizerInvite[] | null>(null)
  const [invitesLoading, setInvitesLoading] = useState(false)
  const [invitesError, setInvitesError] = useState<AppErrorShape | null>(null)
  const intelRequestRef = useRef<string | null>(null)

  const [intelItem, setIntelItem] = useState<OrganizerVendorRequest | null>(null)
  const [intel, setIntel] = useState<ApplicationIntel | null>(null)
  const [intelLoading, setIntelLoading] = useState(false)

  const didFocusLoad = useRef(false)
  const reviewInFlight = useRef(false)
  const [infoItem, setInfoItem] = useState<OrganizerVendorRequest | null>(null)

  const loadRequests = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        const result = await fetchOrganizerRequests(auth, { force: mode === "refresh" })
        setItems(result.items)
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  const loadDiscover = useCallback(
    async (query: string, page = 1, append = false, filters = discoverFilters) => {
      if (append) setDiscoverLoadingMore(true)
      else setDiscoverLoading(true)
      setDiscoverError(null)
      try {
        const result = await fetchOrganizerVendorDirectory({
          auth,
          query,
          scope: "discover",
          page,
          filters,
        })
        setDirectory((prev) => (append ? [...prev, ...result.items] : result.items))
        setDiscoverPage(result.page)
        setDiscoverPages(result.pages)
        setDiscoverTotal(result.total)
      } catch (err) {
        setDiscoverError(appErrorFromWp(err))
        if (!append) setDirectory([])
      } finally {
        setDiscoverLoading(false)
        setDiscoverLoadingMore(false)
      }
    },
    [auth, discoverFilters],
  )

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "refresh" : "initial"
      didFocusLoad.current = true
      void loadRequests(mode)
    }, [loadRequests]),
  )

  useEffect(() => {
    if (segment !== "discover") return
    const handle = setTimeout(() => {
      void loadDiscover(discoverQuery, 1, false, discoverFilters)
    }, 300)
    return () => clearTimeout(handle)
  }, [segment, discoverQuery, discoverFilters, loadDiscover])

  const activeFilterCount = useMemo(() => {
    let count = 0
    if (discoverFilters.city?.trim()) count += 1
    if (discoverFilters.category?.trim()) count += 1
    if (discoverFilters.verified) count += 1
    if (discoverFilters.sort && discoverFilters.sort !== "score") count += 1
    return count
  }, [discoverFilters])

  const eventScoped = useMemo(() => {
    if (!eventFilter) return items
    return items.filter((item) => item.eventId === eventFilter)
  }, [items, eventFilter])

  const eventLabel = useMemo(() => {
    if (!eventFilter) return null
    return eventScoped[0]?.eventName ?? `Event ${eventFilter}`
  }, [eventFilter, eventScoped])

  const filterCounts = useMemo<Record<FilterKey, number>>(() => {
    const count = (key: FilterKey) => eventScoped.filter((item) => matchesFilter(item.status, key)).length
    return {
      all: eventScoped.length,
      review: count("review"),
      needs_info: count("needs_info"),
      waitlisted: count("waitlisted"),
      awaiting_payment: count("awaiting_payment"),
      confirmed: count("confirmed"),
      declined: count("declined"),
    }
  }, [eventScoped])

  const visible = useMemo(
    () => eventScoped.filter((item) => matchesFilter(item.status, filter)),
    [eventScoped, filter],
  )

  const segmentOptions = useMemo<ChipOption<SegmentKey>[]>(
    () => [
      { value: "requests", label: "Requests", count: filterCounts.review },
      { value: "discover", label: "Discover" },
      { value: "invites", label: "Invites" },
    ],
    [filterCounts.review],
  )

  const loadInvites = useCallback(
    async (force = false) => {
      setInvitesLoading(true)
      setInvitesError(null)
      try {
        setInvites(await fetchOrganizerInvites(auth, { force }))
      } catch (err) {
        setInvitesError(appErrorFromWp(err))
      } finally {
        setInvitesLoading(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    if (segment === "invites") void loadInvites()
  }, [segment, loadInvites])

  async function runReview(
    item: OrganizerVendorRequest,
    action: OrganizerReviewAction,
    reason?: string,
  ) {
    if (reviewInFlight.current) return
    reviewInFlight.current = true
    setActingId(item.id)
    try {
      await reviewOrganizerRequest(item, action, auth, reason)
      invalidateOrganizerMoney()
      // Re-read canonical status: approve can land on payment_required or paid (free booth).
      const fresh = await fetchOrganizerRequests(auth, { force: true })
      setItems(fresh.items)
      const updated = fresh.items.find((row) => row.id === item.id)
      if (updated) {
        const next = (["waitlisted", "awaiting_payment", "confirmed", "declined"] as const).find((key) =>
          matchesFilter(updated.status, key),
        )
        if (next) {
          setFilter(next)
          showToast(
            next === "awaiting_payment"
              ? `${item.vendorBusinessName} approved — we've asked them to pay to confirm.`
              : next === "confirmed"
                ? `${item.vendorBusinessName} is confirmed.`
                : next === "waitlisted"
                  ? `${item.vendorBusinessName} is on the waitlist.`
                  : `${item.vendorBusinessName} was declined.`,
            "success",
          )
        }
      }
    } catch (err) {
      showToast(`Couldn't update this request. ${appErrorFromWp(err).message}`, "error")
      void loadRequests("refresh")
    } finally {
      reviewInFlight.current = false
      setActingId(null)
    }
  }

  async function handleReview(item: OrganizerVendorRequest, action: OrganizerReviewAction) {
    if (reviewInFlight.current) return
    if (action === "reject") {
      const reason = await promptText({
        title: `Decline ${item.vendorBusinessName}?`,
        message: "The vendor sees this reason with the decision. Keep it short and kind.",
        placeholder: "e.g. We already have enough food vendors for this date",
        confirmLabel: "Decline request",
        minLength: 3,
      })
      if (reason) void runReview(item, action, reason)
      return
    }
    if (action === "approve") {
      const requestId = item.id
      intelRequestRef.current = requestId
      setIntelItem(item)
      setIntel(null)
      setIntelLoading(true)
      void fetchApplicationIntel(requestId, auth)
        .then((data) => {
          if (intelRequestRef.current === requestId) setIntel(data)
        })
        .catch(() => {
          if (intelRequestRef.current === requestId) setIntel(null)
        })
        .finally(() => {
          if (intelRequestRef.current === requestId) setIntelLoading(false)
        })
      return
    }
    void runReview(item, action)
  }

  function handleInvite(vendor: OrganizerDirectoryVendor) {
    if (!vendor.vendorUserId) {
      showToast("This vendor can't be invited from the app yet.", "info")
      return
    }
    setInviteVendor(vendor)
  }

  function closeIntel() {
    intelRequestRef.current = null
    setIntelItem(null)
    setIntel(null)
    setIntelLoading(false)
  }

  function clearEventFilter() {
    router.replace("/(organizer)/vendors")
  }

  useEffect(() => {
    if (tabParam === "discover" || tabParam === "invites" || tabParam === "requests") {
      setSegment(tabParam)
    }
  }, [tabParam])

  function onRefresh() {
    if (segment === "discover") {
      setRefreshing(true)
      void loadDiscover(discoverQuery, 1, false, discoverFilters).finally(() =>
        setRefreshing(false),
      )
      return
    }
    void loadRequests("refresh")
  }

  const intelProfileId = intelItem?.vendorProfileId

  return (
    <Screen
      title="Vendors"
      subtitle="Review requests and discover vendors"
      keyboardAvoiding={segment === "discover"}
      refreshing={refreshing}
      onRefresh={onRefresh}
    >
      {eventFilter ? (
        <InlineBanner
          tone="attention"
          title="Filtered to one event"
          message={eventLabel ?? ""}
          actionLabel="Clear"
          onAction={clearEventFilter}
        />
      ) : null}

      <SegmentedControl options={segmentOptions} value={segment} onChange={setSegment} />

      {segment === "requests" ? (
        <RequestsQueue
          loading={loading}
          error={error}
          hasItems={items.length > 0}
          visible={visible}
          filter={filter}
          filterCounts={filterCounts}
          eventFiltered={Boolean(eventFilter)}
          actingId={actingId}
          onFilterChange={setFilter}
          onRetry={() => void loadRequests("refresh")}
          onClearEventFilter={clearEventFilter}
          onDiscover={() => setSegment("discover")}
          onReview={handleReview}
          onRequestInfo={setInfoItem}
        />
      ) : null}

      {segment === "discover" ? (
        <DiscoverDirectory
          query={discoverQuery}
          onQueryChange={setDiscoverQuery}
          filters={discoverFilters}
          onFiltersChange={setDiscoverFilters}
          activeFilterCount={activeFilterCount}
          loading={discoverLoading}
          loadingMore={discoverLoadingMore}
          error={discoverError}
          directory={directory}
          total={discoverTotal}
          hasMore={discoverPage < discoverPages}
          invitingId={null}
          onRetry={() => void loadDiscover(discoverQuery, 1, false, discoverFilters)}
          onLoadMore={() =>
            void loadDiscover(discoverQuery, discoverPage + 1, true, discoverFilters)
          }
          onInvite={handleInvite}
        />
      ) : null}

      {segment === "invites" ? (
        <InvitesList
          invites={invites}
          loading={invitesLoading}
          error={invitesError}
          eventFilter={eventFilter}
          onRetry={() => void loadInvites(true)}
          onDiscover={() => setSegment("discover")}
        />
      ) : null}

      <ApproveIntelModal
        item={intelItem}
        intel={intel}
        loading={intelLoading}
        acting={Boolean(intelItem) && actingId === intelItem?.id}
        onCancel={closeIntel}
        onOpenProfile={
          intelItem && intelProfileId
            ? () => {
                const applicationId = intelItem.id
                closeIntel()
                router.push(vendorProfileHref(intelProfileId, applicationId) as Href)
              }
            : undefined
        }
        onApprove={() => {
          const target = intelItem
          closeIntel()
          if (target) void runReview(target, "approve")
        }}
      />

      <InviteSheet
        vendor={inviteVendor}
        auth={auth}
        defaultEventId={eventFilter}
        onClose={() => setInviteVendor(null)}
        onSent={() => setInvites(null)}
      />

      <InfoRequestSheet
        item={infoItem}
        auth={auth}
        onClose={() => setInfoItem(null)}
        onSent={() => {
          setInfoItem(null)
          setFilter("needs_info")
          void loadRequests("refresh")
        }}
      />
    </Screen>
  )
}
