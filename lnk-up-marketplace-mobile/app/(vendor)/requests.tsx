import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { RequestCard } from "@/components/request-card"
import { ScreenList } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { FilterChipRow, type ChipOption } from "@/components/ui/chip"
import { DraftResumeList } from "@/components/vendor/draft-resume-list"
import { discardApplyDraft, fetchApplyDrafts, type ApplyDraftCard } from "@/lib/api/apply"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { formatMoney } from "@/lib/api/vendor-wallet"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { lifecycleOf, vendorLifecycleCounts } from "@/lib/vendor/vendor-stage"

type FilterKey = "all" | "action" | "review" | "confirmed" | "past"

const FILTER_KEYS: readonly FilterKey[] = ["all", "action", "review", "confirmed", "past"]

/** Older links (push notifications, Home) used payment/booked; keep them working. */
const FILTER_ALIASES: Record<string, FilterKey> = { payment: "action", booked: "confirmed" }

function parseFilterParam(value: string | string[] | undefined): FilterKey {
  const raw = Array.isArray(value) ? value[0] : value
  if (raw && FILTER_ALIASES[raw]) return FILTER_ALIASES[raw]
  return FILTER_KEYS.find((key) => key === raw) ?? "all"
}

function matchesFilter(item: VendorRequest, filter: FilterKey): boolean {
  const bucket = lifecycleOf(item)
  switch (filter) {
    case "all":
      return true
    case "action":
      return bucket === "action"
    case "review":
      return bucket === "review"
    case "confirmed":
      return bucket === "confirmed"
    case "past":
      return bucket === "past" || bucket === "closed"
    default: {
      const unhandled: never = filter
      return unhandled
    }
  }
}

function primaryAction(item: VendorRequest): { label: string; href: Href } | null {
  if (item.status === "info_requested") {
    return { label: "Reply to organizer", href: `/booking/${item.id}` as Href }
  }
  if (lifecycleOf(item) === "action" && (item.status === "approved" || item.status === "payment_required")) {
    const amount =
      item.amountDue != null && item.amountDue > 0
        ? formatMoney(item.amountDue, item.currency ?? "USD")
        : null
    return { label: amount ? `Pay ${amount}` : "Pay now", href: `/pass/${item.id}` as Href }
  }
  if (lifecycleOf(item) === "confirmed") {
    return { label: "Open pass", href: `/pass/${item.id}` as Href }
  }
  return null
}

const EMPTY: Record<FilterKey, { title: string; message: string }> = {
  all: {
    title: "No applications yet",
    message: "Apply to events on Explore — every status and payment tracks here.",
  },
  action: {
    title: "Nothing needs you",
    message: "Organizer questions and booth fees to confirm a spot show up here.",
  },
  review: { title: "Nothing in review", message: "Applications waiting on organizers will show here." },
  confirmed: { title: "No upcoming bookings", message: "Paid bookings and their event passes show here." },
  past: { title: "No past bookings", message: "Completed events, declines and cancellations end up here." },
}

/** Bookings: the vendor's applications organized by what happens next. */
export default function VendorBookingsScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ filter?: string }>()
  const [filter, setFilter] = useState<FilterKey>(() => parseFilterParam(params.filter))
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<VendorRequest[]>([])
  const [drafts, setDrafts] = useState<ApplyDraftCard[]>([])
  const [discardingId, setDiscardingId] = useState<number | null>(null)
  const hasLoadedOnce = useRef(false)
  const didFocusLoad = useRef(false)

  useEffect(() => {
    if (params.filter) setFilter(parseFilterParam(params.filter))
  }, [params.filter])

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else if (!hasLoadedOnce.current) setInitialLoad(true)
      setError(null)
      // Drafts are secondary: if they fail to load, the section stays hidden rather than blocking bookings.
      void fetchApplyDrafts(auth)
        .then(setDrafts)
        .catch(() => undefined)
      try {
        const result = await fetchVendorRequests(auth, { force: mode === "refresh" })
        setItems(result.items)
        hasLoadedOnce.current = true
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setInitialLoad(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useFocusEffect(
    useCallback(() => {
      const firstFocus = !didFocusLoad.current
      didFocusLoad.current = true
      void load(firstFocus ? "initial" : "focus")
    }, [load]),
  )

  const handleResumeDraft = useCallback((draft: ApplyDraftCard) => {
    router.push(`/apply/${draft.opportunityId}` as Href)
  }, [])

  const handleDiscardDraft = useCallback(
    async (draft: ApplyDraftCard) => {
      if (discardingId) return
      const confirmed = await confirmAction({
        title: "Discard this application?",
        message: "Your answers for this booth will be deleted. You can start again while applications are open.",
        confirmLabel: "Discard",
        cancelLabel: "Keep it",
        destructive: true,
      })
      if (!confirmed) return
      setDiscardingId(draft.id)
      try {
        await discardApplyDraft(draft.opportunityId, auth)
        setDrafts((current) => current.filter((d) => d.id !== draft.id))
        showToast("Application discarded.", "success")
      } catch (err) {
        showToast(appErrorFromWp(err).message, "error")
      } finally {
        setDiscardingId(null)
      }
    },
    [auth, discardingId],
  )

  const counts = useMemo(() => vendorLifecycleCounts(items), [items])

  const filters = useMemo<ChipOption<FilterKey>[]>(
    () => [
      { value: "all", label: "All", count: items.length },
      { value: "action", label: "Needs action", count: counts.action },
      { value: "review", label: "In review", count: counts.review },
      { value: "confirmed", label: "Confirmed", count: counts.confirmed },
      { value: "past", label: "Past", count: counts.past + counts.closed },
    ],
    [counts, items.length],
  )

  const visible = useMemo(() => {
    const order: Record<ReturnType<typeof lifecycleOf>, number> = {
      action: 0,
      confirmed: 1,
      review: 2,
      past: 3,
      closed: 4,
    }
    return items
      .filter((item) => matchesFilter(item, filter))
      .sort((a, b) => order[lifecycleOf(a)] - order[lifecycleOf(b)])
  }, [items, filter])

  const empty = EMPTY[filter]
  const showFullLoader = initialLoad && items.length === 0

  const rows = showFullLoader ? EMPTY_ROWS : visible

  return (
    <ScreenList
      title="Bookings"
      subtitle="Applications, payments and confirmed events"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
      data={rows}
      keyExtractor={(item) => item.id}
      renderItem={(item) => {
        const action = primaryAction(item)
        return (
          <RequestCard
            item={item}
            onPress={() => router.push(`/booking/${item.id}` as Href)}
            primaryLabel={action?.label}
            onPrimaryAction={action ? () => router.push(action.href) : undefined}
          />
        )
      }}
      listHeader={
        <>
          <DraftResumeList
            drafts={drafts}
            discardingId={discardingId}
            onResume={handleResumeDraft}
            onDiscard={(draft) => void handleDiscardDraft(draft)}
          />

          <FilterChipRow options={filters} value={filter} onChange={setFilter} />

          {filter === "all" && counts.action > 0 ? (
            <InlineBanner
              tone="attention"
              title={`${counts.action} booking${counts.action === 1 ? " needs" : "s need"} you`}
              message="Answer organizer questions or pay to confirm your spot."
              actionLabel="View"
              onAction={() => setFilter("action")}
            />
          ) : null}

          {!showFullLoader && error && items.length > 0 ? (
            <InlineBanner
              tone="error"
              message={`Couldn't refresh. ${error.message}`}
              actionLabel="Retry"
              onAction={() => void load("refresh")}
            />
          ) : null}

          {showFullLoader ? <LoadingPanel label="Loading bookings…" variant="skeleton" /> : null}

          {!showFullLoader && error && items.length === 0 ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
          ) : null}

          {!showFullLoader &&
          !(error && items.length === 0) &&
          visible.length === 0 &&
          !(filter === "all" && drafts.length > 0) ? (
            <EmptyPanel
              icon={
                filter === "action"
                  ? "card-outline"
                  : filter === "confirmed"
                    ? "ticket-outline"
                    : "file-tray-outline"
              }
              title={empty.title}
              message={empty.message}
              actionLabel="Explore events"
              onAction={() => router.push("/(vendor)/find")}
              secondaryActionLabel={filter !== "all" ? "Show all bookings" : undefined}
              onSecondaryAction={filter !== "all" ? () => setFilter("all") : undefined}
            />
          ) : null}
        </>
      }
    />
  )
}

const EMPTY_ROWS: VendorRequest[] = []
