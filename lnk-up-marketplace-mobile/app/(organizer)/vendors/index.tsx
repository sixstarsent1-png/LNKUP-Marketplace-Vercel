import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { ApplicationRow } from "@/components/organizer/application-row"
import { InvitesPanel } from "@/components/organizer/invites-panel"
import {
  DirectoryCard,
  DiscoverFooter,
  DiscoverHeader,
} from "@/components/organizer-vendors/discover-directory"
import { InviteSheet } from "@/components/organizer-vendors/invite-sheet"
import { RequestsQueueHeader } from "@/components/organizer-vendors/requests-queue"
import {
  EMPTY_DISCOVER_FILTERS,
  matchesFilter,
  parseFilterKey,
  type FilterKey,
  type SegmentKey,
} from "@/components/organizer-vendors/vendor-queue"
import { ScreenList } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { SegmentedControl, type ChipOption } from "@/components/ui/chip"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { isAbortError } from "@/lib/api/wp-client"
import { fetchOrganizerRequests, type OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import {
  fetchOrganizerVendorDirectory,
  type OrganizerDirectoryVendor,
  type OrganizerDiscoverFilters,
} from "@/lib/api/organizer-vendor-directory"
import { fetchFindVendorsEnabled } from "@/lib/api/match"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"

export default function OrganizerVendorsScreen() {
  const { auth } = useAuth()
  const {
    event: eventParam,
    tab: tabParam,
    segment: segmentParam,
    filter: filterParam,
    invite: inviteParam,
  } = useLocalSearchParams<{
    event?: string
    tab?: string
    segment?: string
    filter?: string
    invite?: string
  }>()
  const eventFilter = typeof eventParam === "string" && eventParam ? eventParam : null
  const segmentHint = segmentParam ?? tabParam

  const initialSegment: SegmentKey = segmentHint === "discover" || segmentHint === "invites" ? segmentHint : "requests"

  const [segment, setSegment] = useState<SegmentKey>(initialSegment)
  const [filter, setFilter] = useState<FilterKey>(() => parseFilterKey(filterParam) ?? "review")

  useEffect(() => {
    const next = parseFilterKey(filterParam)
    if (next) setFilter(next)
  }, [filterParam])
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<OrganizerVendorRequest[]>([])

  const [discoverQuery, setDiscoverQuery] = useState("")
  const [discoverFilters, setDiscoverFilters] = useState<OrganizerDiscoverFilters>(EMPTY_DISCOVER_FILTERS)
  const [discoverLoading, setDiscoverLoading] = useState(false)
  const [discoverLoadingMore, setDiscoverLoadingMore] = useState(false)
  const [discoverError, setDiscoverError] = useState<AppErrorShape | null>(null)
  const [directory, setDirectory] = useState<OrganizerDirectoryVendor[]>([])
  const [discoverPage, setDiscoverPage] = useState(1)
  const [discoverPages, setDiscoverPages] = useState(1)
  const [discoverTotal, setDiscoverTotal] = useState(0)
  const [inviteVendor, setInviteVendor] = useState<OrganizerDirectoryVendor | null>(null)
  const [invitesVersion, setInvitesVersion] = useState(0)
  const discoverRef = useRef<AbortController | null>(null)

  const didFocusLoad = useRef(false)
  const [findVendorsEnabled, setFindVendorsEnabled] = useState(false)

  useEffect(() => {
    if (!auth) return
    let cancelled = false
    fetchFindVendorsEnabled(auth)
      .then((enabled) => {
        if (!cancelled) setFindVendorsEnabled(enabled)
      })
      .catch(() => {
        if (!cancelled) setFindVendorsEnabled(false)
      })
    return () => {
      cancelled = true
    }
  }, [auth])

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
      // A new search cancels the one in flight so an older, slower page can't overwrite it.
      discoverRef.current?.abort()
      const controller = new AbortController()
      discoverRef.current = controller
      const isCurrent = () => discoverRef.current === controller
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
          signal: controller.signal,
        })
        if (!isCurrent()) return
        setDirectory((prev) => {
          if (!append) return result.items
          const seen = new Set(prev.map((vendor) => vendor.id))
          return [...prev, ...result.items.filter((vendor) => !seen.has(vendor.id))]
        })
        setDiscoverPage(result.page)
        setDiscoverPages(result.pages)
        setDiscoverTotal(result.total)
      } catch (err) {
        if (isAbortError(err) || !isCurrent()) return
        if (append) {
          showToast(`Couldn't load more vendors. ${appErrorFromWp(err).message}`, "error")
        } else {
          setDiscoverError(appErrorFromWp(err))
          setDirectory([])
        }
      } finally {
        if (isCurrent()) {
          discoverRef.current = null
          setDiscoverLoading(false)
          setDiscoverLoadingMore(false)
        }
      }
    },
    [auth, discoverFilters],
  )

  useEffect(() => () => discoverRef.current?.abort(), [])

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

  function openReview(item: OrganizerVendorRequest) {
    const query = [`filter=${filter}`, eventFilter ? `eventId=${eventFilter}` : ""].filter(Boolean).join("&")
    router.push(`/(organizer)/vendors/review/${item.id}?${query}` as Href)
  }

  function handleInvite(vendor: OrganizerDirectoryVendor) {
    if (!vendor.vendorUserId) {
      showToast("This vendor can't be invited from the app yet.", "info")
      return
    }
    setInviteVendor(vendor)
  }

  function clearEventFilter() {
    router.replace("/(organizer)/vendors")
  }

  useEffect(() => {
    if (segmentHint === "discover" || segmentHint === "invites" || segmentHint === "requests") {
      setSegment(segmentHint)
    }
  }, [segmentHint])

  function onRefresh() {
    if (segment === "discover") {
      setRefreshing(true)
      void loadDiscover(discoverQuery, 1, false, discoverFilters).finally(() => setRefreshing(false))
      return
    }
    void loadRequests("refresh")
  }

  const discoverHasMore = discoverPage < discoverPages

  const rows = useMemo<VendorRow[]>(() => {
    if (segment === "requests")
      return loading && items.length === 0 ? [] : visible.map((item) => ({ kind: "request", item }))
    if (segment === "discover") return directory.map((vendor) => ({ kind: "vendor", vendor }))
    return []
  }, [segment, loading, items.length, visible, directory])

  function loadMoreDiscover() {
    if (segment !== "discover" || !discoverHasMore || discoverLoading || discoverLoadingMore) return
    void loadDiscover(discoverQuery, discoverPage + 1, true, discoverFilters)
  }

  function renderRow(row: VendorRow) {
    if (row.kind === "request") {
      return <ApplicationRow request={row.item} onPress={() => openReview(row.item)} />
    }
    return <DirectoryCard vendor={row.vendor} onInvite={handleInvite} />
  }

  return (
    <>
      <ScreenList
        title="Vendors"
        subtitle="Review requests and discover vendors"
        keyboardAvoiding={segment === "discover"}
        refreshing={refreshing}
        onRefresh={onRefresh}
        data={rows}
        keyExtractor={(row) => (row.kind === "request" ? `r-${row.item.id}` : `v-${row.vendor.id}`)}
        renderItem={renderRow}
        onEndReached={loadMoreDiscover}
        listFooter={
          segment === "discover" ? (
            <DiscoverFooter
              count={directory.length}
              hasMore={discoverHasMore}
              loadingMore={discoverLoadingMore}
              onLoadMore={loadMoreDiscover}
            />
          ) : null
        }
        listHeader={
          <>
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

            {segment === "discover" && findVendorsEnabled ? (
              <InlineBanner
                tone="info"
                title="Recruit for an event"
                message="See discoverable vendors that match one of your events and invite them to apply."
                actionLabel="Find vendors"
                onAction={() =>
                  router.push(
                    (eventFilter ? `/(organizer)/vendors/find?event=${eventFilter}` : "/(organizer)/vendors/find") as Href,
                  )
                }
              />
            ) : null}

            {segment === "requests" ? (
              <RequestsQueueHeader
                loading={loading}
                error={error}
                hasItems={items.length > 0}
                visibleCount={visible.length}
                filter={filter}
                filterCounts={filterCounts}
                eventFiltered={Boolean(eventFilter)}
                onFilterChange={setFilter}
                onRetry={() => void loadRequests("refresh")}
                onClearEventFilter={clearEventFilter}
                onDiscover={() => setSegment("discover")}
              />
            ) : null}

            {segment === "discover" ? (
              <DiscoverHeader
                query={discoverQuery}
                onQueryChange={setDiscoverQuery}
                filters={discoverFilters}
                onFiltersChange={setDiscoverFilters}
                activeFilterCount={activeFilterCount}
                loading={discoverLoading}
                error={discoverError}
                count={directory.length}
                total={discoverTotal}
                onRetry={() => void loadDiscover(discoverQuery, 1, false, discoverFilters)}
              />
            ) : null}

            {segment === "invites" ? (
              <InvitesPanel
                key={invitesVersion}
                auth={auth}
                eventFilter={eventFilter}
                openInviteId={typeof inviteParam === "string" ? inviteParam : null}
                onDiscover={() => setSegment("discover")}
              />
            ) : null}
          </>
        }
      />

      <InviteSheet
        vendor={inviteVendor}
        auth={auth}
        defaultEventId={eventFilter}
        onClose={() => setInviteVendor(null)}
        onSent={() => setInvitesVersion((v) => v + 1)}
      />
    </>
  )
}

type VendorRow =
  { kind: "request"; item: OrganizerVendorRequest } | { kind: "vendor"; vendor: OrganizerDirectoryVendor }
