import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { RequestCard } from "@/components/request-card"
import { ScreenList } from "@/components/screen"
import { InlineBanner } from "@/components/ui/banner"
import { FilterChipRow, type ChipOption } from "@/components/ui/chip"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { useAuth } from "@/lib/auth/auth-context"
import {
  BOOKING_SEGMENTS,
  canOpenPass,
  isPastEventInvoice,
  isUpcomingPaymentDue,
  payCtaLabel,
  segmentBookings,
  type BookingSegment,
} from "@/lib/vendor/booking-lifecycle"

/** Older links (push notifications, Home, web) used other names; keep them working. */
const FILTER_ALIASES: Record<string, BookingSegment> = {
  action: "action",
  payment: "action",
  review: "applications",
  applications: "applications",
  confirmed: "upcoming",
  booked: "upcoming",
  upcoming: "upcoming",
  past: "past",
  needs_review: "review",
}

function parseFilterParam(value: string | string[] | undefined): BookingSegment | null {
  const raw = Array.isArray(value) ? value[0] : value
  return raw ? FILTER_ALIASES[raw] ?? null : null
}

function primaryAction(item: VendorRequest): { label: string; href: Href } | null {
  if (item.status === "info_requested") return { label: "Reply to organizer", href: `/booking/${item.id}` as Href }
  if (isUpcomingPaymentDue(item)) return { label: payCtaLabel(item), href: `/pass/${item.id}` as Href }
  if (isPastEventInvoice(item)) return { label: "Pay invoice", href: `/pass/${item.id}` as Href }
  if (canOpenPass(item)) return { label: "Open pass", href: `/pass/${item.id}` as Href }
  return null
}

const EMPTY: Record<BookingSegment, { title: string; message: string }> = {
  action: { title: "Nothing needs you", message: "Organizer questions and booth fees that confirm a spot show up here." },
  applications: { title: "No open applications", message: "Applications waiting on organizers show here." },
  upcoming: { title: "No upcoming bookings", message: "Confirmed bookings and their event passes show here." },
  past: { title: "No past bookings", message: "Completed events, declines and withdrawals end up here." },
  review: { title: "Nothing to review", message: "Bookings without a date or event show here." },
}

/** Bookings: what needs you, open applications, upcoming events, and history. */
export default function VendorBookingsScreen() {
  const { auth } = useAuth()
  const params = useLocalSearchParams<{ filter?: string }>()
  const [segment, setSegment] = useState<BookingSegment | null>(() => parseFilterParam(params.filter))
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<VendorRequest[]>([])
  const hasLoadedOnce = useRef(false)
  const didFocusLoad = useRef(false)

  useEffect(() => {
    const parsed = parseFilterParam(params.filter)
    if (parsed) setSegment(parsed)
  }, [params.filter])

  const load = useCallback(
    async (mode: "initial" | "focus" | "refresh" = "initial") => {
      if (mode === "refresh") setRefreshing(true)
      else if (!hasLoadedOnce.current) setInitialLoad(true)
      setError(null)
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

  const segments = useMemo(() => segmentBookings(items), [items])
  // Default to the first segment with something in it, most urgent first.
  const active: BookingSegment =
    segment ?? (["action", "upcoming", "applications", "past"] as const).find((s) => segments[s].length > 0) ?? "action"

  const options = useMemo<ChipOption<BookingSegment>[]>(() => {
    const base: ChipOption<BookingSegment>[] = BOOKING_SEGMENTS.map((s) => ({ value: s.id, label: s.label, count: segments[s.id].length }))
    if (segments.review.length > 0) base.push({ value: "review", label: "Needs review", count: segments.review.length })
    return base
  }, [segments])

  const visible = segments[active]
  const empty = EMPTY[active]
  const showFullLoader = initialLoad && items.length === 0
  const rows = showFullLoader ? EMPTY_ROWS : visible

  return (
    <ScreenList
      title="Bookings"
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
          <FilterChipRow options={options} value={active} onChange={setSegment} />

          {active === "review" ? (
            <InlineBanner
              tone="info"
              message="These bookings have no event date, or their event listing was removed. Your payments and receipts are kept. Contact the organizer or LNKup support if something looks wrong."
            />
          ) : null}

          {!showFullLoader && error && items.length > 0 ? (
            <InlineBanner tone="error" message={`Couldn't refresh. ${error.message}`} actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : null}

          {showFullLoader ? <LoadingPanel label="Loading bookings…" variant="skeleton" /> : null}

          {!showFullLoader && error && items.length === 0 ? (
            <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
          ) : null}

          {!showFullLoader && !(error && items.length === 0) && visible.length === 0 ? (
            <EmptyPanel
              icon={active === "action" ? "card-outline" : active === "upcoming" ? "ticket-outline" : "file-tray-outline"}
              title={empty.title}
              message={empty.message}
              actionLabel="Explore events"
              onAction={() => router.push("/(vendor)/find")}
            />
          ) : null}
        </>
      }
    />
  )
}

const EMPTY_ROWS: VendorRequest[] = []
