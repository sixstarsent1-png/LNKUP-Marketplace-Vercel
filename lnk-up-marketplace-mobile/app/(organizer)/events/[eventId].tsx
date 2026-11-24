import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Pressable, Share, StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { IsoDatePickerField } from "@/components/iso-date-picker-field"
import { LocationField } from "@/components/location-field"
import { ApplicationRow } from "@/components/organizer/application-row"
import { DuplicateEventSheet } from "@/components/organizer/duplicate-event-sheet"
import { eventMetaLine } from "@/components/organizer/event-row"
import { InviteRow } from "@/components/organizer/invite-row"
import { ProgressBar } from "@/components/organizer/progress-bar"
import { vendorProfileHref } from "@/components/organizer-vendors/vendor-queue"
import { PacketForm } from "@/components/organizer-workspace/packet-form"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppIcon } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { CapacityBar } from "@/components/ui/capacity-bar"
import { FilterChip, SegmentedControl } from "@/components/ui/chip"
import { CollapsibleSection } from "@/components/ui/collapsible-section"
import { EventImage } from "@/components/ui/event-image"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SectionHeader } from "@/components/ui/section-header"
import { SegmentedTabs, type SegmentedTab } from "@/components/ui/segmented-tabs"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Meta, SectionTitle } from "@/components/ui/typography"
import { newClientToken } from "@/lib/api/client-token"
import { getOpportunityApplyUrl } from "@/lib/api/config"
import { fetchShareLink } from "@/lib/api/deep-links"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { isLocationSubmittable, type LocationStatus } from "@/lib/api/locations"
import { fetchOrganizerEventMoney, formatMoney, type OrganizerEventMoneySummary } from "@/lib/api/organizer-financial"
import { LAUNCH_CATEGORIES } from "@/lib/api/organizer-launch"
import {
  addOrganizerOpportunities,
  eventListingLabel,
  eventListingState,
  fetchOrganizerEvent,
  setOpportunityListingStatus,
  updateOpportunityPricing,
  updateOrganizerEvent,
  type OrganizerEventLite,
  type OrganizerEventOpportunity,
} from "@/lib/api/organizer-events"
import {
  fetchEventScore,
  fetchInviteOverview,
  fetchOperationsSummary,
  fetchOrganizerEvents,
  invalidateOrganizerMobile,
  type EventScore,
  type OperationsSummary,
  type OrgEventCard,
  type OrgInvite,
} from "@/lib/api/organizer-mobile"
import { fetchOrganizerRequests, type OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { confirmedVendors, fetchEventRoster, type EventRoster } from "@/lib/api/organizer-roster"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { formatShortDate } from "@/lib/format/date"
import { publishChecklist, scoreActionHref } from "@/lib/organizer/event-detail"
import { rememberLastEvent } from "@/lib/organizer/last-event"
import { colors, fonts, layout, radii, spacing, typeScale, typography } from "@/lib/theme"

type TabKey = "overview" | "vendors" | "inventory" | "money" | "operations"
type VendorSegment = "applications" | "confirmed" | "invites"

/** Old deep links (`?tab=slots`, `payments`, `dayof`, `packet`) land on the tab that now holds them. */
const LEGACY_TABS: Record<string, TabKey> = {
  overview: "overview",
  slots: "inventory",
  inventory: "inventory",
  applications: "vendors",
  vendors: "vendors",
  payments: "money",
  money: "money",
  dayof: "operations",
  packet: "operations",
  operations: "operations",
}

type PricingDraft = Record<string, { fee: string; spots: string }>

function opportunitiesFromEvent(event: OrganizerEventLite): OrganizerEventOpportunity[] {
  if (event.opportunities && event.opportunities.length > 0) return event.opportunities
  if (event.opportunityId) {
    return [
      {
        id: event.opportunityId,
        category: "Vendor booth",
        label: "Vendor booth",
        vendorFee: event.vendorFee,
        spotsTotal: event.spotsTotal,
        spotsRemaining: event.spotsRemaining,
      },
    ]
  }
  return []
}

/** Spots already approved, awaiting payment, or paid (server ledger: total − remaining). */
function heldSpots(opp: OrganizerEventOpportunity): number {
  if (opp.spotsTotal == null || opp.spotsRemaining == null) return 0
  return Math.max(0, opp.spotsTotal - opp.spotsRemaining)
}

function buildPricingDraft(opps: OrganizerEventOpportunity[]): PricingDraft {
  const next: PricingDraft = {}
  for (const opp of opps) {
    next[opp.id] = {
      fee: opp.vendorFee != null ? String(opp.vendorFee) : "",
      spots: opp.spotsTotal != null ? String(opp.spotsTotal) : "",
    }
  }
  return next
}

function parseTab(value: string | string[] | undefined): TabKey {
  const raw = Array.isArray(value) ? value[0] : value
  return (raw && LEGACY_TABS[raw]) || "overview"
}

/** Event workspace: one event's overview, vendors, inventory, money and operations. */
export default function OrganizerEventWorkspaceScreen() {
  const { eventId, tab: tabParam } = useLocalSearchParams<{ eventId: string; tab?: string }>()
  const { auth } = useAuth()
  const id = String(eventId ?? "")
  const [tab, setTab] = useState<TabKey>(() => parseTab(tabParam))
  const [vendorSegment, setVendorSegment] = useState<VendorSegment>("applications")
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [refreshError, setRefreshError] = useState<string | null>(null)
  const [event, setEvent] = useState<OrganizerEventLite | null>(null)
  const [requests, setRequests] = useState<OrganizerVendorRequest[]>([])
  const [roster, setRoster] = useState<EventRoster | null>(null)
  const [rosterError, setRosterError] = useState<AppErrorShape | null>(null)
  const [money, setMoney] = useState<OrganizerEventMoneySummary | null>(null)
  const [moneyError, setMoneyError] = useState<AppErrorShape | null>(null)
  const [ops, setOps] = useState<OperationsSummary | null>(null)
  const [score, setScore] = useState<EventScore | null>(null)
  const [invites, setInvites] = useState<OrgInvite[] | null>(null)
  const [pricingDraft, setPricingDraft] = useState<PricingDraft>({})
  const [savingPricingId, setSavingPricingId] = useState<string | null>(null)
  const [statusBusyId, setStatusBusyId] = useState<string | null>(null)
  const [publishing, setPublishing] = useState(false)
  const [editTitle, setEditTitle] = useState("")
  const [editDate, setEditDate] = useState("")
  const [editCity, setEditCity] = useState("")
  const [editState, setEditState] = useState("")
  const [editLocationStatus, setEditLocationStatus] = useState<LocationStatus>("")
  const [editVenue, setEditVenue] = useState("")
  const [editDescription, setEditDescription] = useState("")
  const [savingEvent, setSavingEvent] = useState(false)
  const [newCategory, setNewCategory] = useState("")
  const [newFee, setNewFee] = useState("")
  const [newSpots, setNewSpots] = useState("")
  const [addingOpp, setAddingOpp] = useState(false)
  const [switcherOpen, setSwitcherOpen] = useState(false)
  const [switcherEvents, setSwitcherEvents] = useState<OrgEventCard[] | null>(null)
  const [duplicateOpen, setDuplicateOpen] = useState(false)
  const addingRef = useRef(false)
  const addTokenRef = useRef(newClientToken())
  const savingEventRef = useRef(false)
  const didFocusLoad = useRef(false)
  const eventRef = useRef<OrganizerEventLite | null>(null)
  eventRef.current = event

  useEffect(() => {
    if (tabParam) setTab(parseTab(tabParam))
  }, [tabParam])

  const syncEditFields = useCallback((data: OrganizerEventLite) => {
    setEditTitle(data.title ?? "")
    setEditDate(data.date ?? "")
    setEditCity(data.city ?? "")
    setEditState(data.state ?? "")
    setEditVenue(data.venueName ?? "")
    setEditDescription(data.description ?? "")
    setPricingDraft(buildPricingDraft(opportunitiesFromEvent(data)))
  }, [])

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (!id) return
      if (mode === "refresh") setRefreshing(true)
      else setInitialLoad(true)
      setError(null)
      setRefreshError(null)
      const force = mode === "refresh"
      try {
        const [data, requestResult, opsResult] = await Promise.all([
          fetchOrganizerEvent(id, auth),
          fetchOrganizerRequests(auth, { force }).catch(() => null),
          auth ? fetchOperationsSummary(id, auth, force).catch(() => null) : Promise.resolve(null),
        ])
        setEvent(data)
        if (data) {
          syncEditFields(data)
          void rememberLastEvent(auth?.username ?? "", { id: data.id, title: data.title })
        }
        if (requestResult) setRequests(requestResult.items.filter((item) => item.eventId === id))
        setOps(opsResult)
      } catch (err) {
        const shaped = appErrorFromWp(err)
        if (mode === "refresh" && eventRef.current) setRefreshError(shaped.message)
        else setError(shaped)
      } finally {
        setInitialLoad(false)
        setRefreshing(false)
      }
    },
    [auth, id, syncEditFields],
  )

  const loadRoster = useCallback(
    async (force = false) => {
      if (!id) return
      setRosterError(null)
      try {
        setRoster(await fetchEventRoster(id, auth, { force }))
      } catch (err) {
        setRosterError(appErrorFromWp(err))
      }
    },
    [auth, id],
  )

  const loadMoney = useCallback(async () => {
    if (!id) return
    setMoneyError(null)
    try {
      setMoney(await fetchOrganizerEventMoney(id, eventRef.current?.title, auth))
    } catch (err) {
      setMoneyError(appErrorFromWp(err))
    }
  }, [auth, id])

  useFocusEffect(
    useCallback(() => {
      const mode = didFocusLoad.current ? "refresh" : "initial"
      didFocusLoad.current = true
      void load(mode)
    }, [load]),
  )

  useEffect(() => {
    if (tab === "vendors" && vendorSegment === "confirmed") void loadRoster()
    if (tab === "vendors" && vendorSegment === "invites" && auth && invites === null) {
      void fetchInviteOverview(auth)
        .then((overview) => setInvites(overview ? overview.items.filter((invite) => invite.eventId === id) : []))
        .catch(() => setInvites([]))
    }
    if (tab === "money") void loadMoney()
    if (tab === "overview" && auth && !score && ops?.can.view_intelligence) {
      void fetchEventScore(id, auth).then(setScore).catch(() => undefined)
    }
  }, [tab, vendorSegment, loadRoster, loadMoney, auth, id, invites, score, ops?.can.view_intelligence])

  const opportunities = event ? opportunitiesFromEvent(event) : []
  const state = event ? eventListingState(event) : "live"
  const pendingRequests = useMemo(
    () => requests.filter((r) => r.status === "submitted" || r.status === "under_review" || r.status === "info_requested"),
    [requests],
  )
  const confirmed = useMemo(() => (roster ? confirmedVendors(roster) : []), [roster])
  const checklist = event ? publishChecklist(event, opportunities) : []
  const checklistReady = checklist.every((item) => item.done || !item.required)
  const isEventDay = ops?.mode === "event_day"

  const tabs = useMemo<SegmentedTab<TabKey>[]>(
    () => [
      { value: "overview", label: "Overview" },
      {
        value: "vendors",
        label: "Vendors",
        count: pendingRequests.length || undefined,
        attention: pendingRequests.length > 0,
      },
      { value: "inventory", label: "Inventory", count: opportunities.length || undefined },
      {
        value: "money",
        label: "Money",
        count: event?.paymentRequiredCount || undefined,
        attention: (event?.paymentRequiredCount ?? 0) > 0,
      },
      { value: "operations", label: "Operations", attention: isEventDay },
    ],
    [opportunities.length, pendingRequests.length, event?.paymentRequiredCount, isEventDay],
  )

  async function handleSaveEvent() {
    if (!event || savingEventRef.current) return
    const title = editTitle.trim()
    if (!title) {
      showToast("Enter an event name before saving.", "error")
      return
    }
    savingEventRef.current = true
    setSavingEvent(true)
    try {
      // Empty strings are sent on purpose: they clear the field on the server.
      const result = await updateOrganizerEvent(
        event.id,
        {
          event_name: title,
          event_date: editDate.trim(),
          city: editCity.trim(),
          state: editState.trim(),
          venue_name: editVenue.trim(),
          description: editDescription,
        },
        auth,
      )
      invalidateOrganizerMobile(event.id)
      showToast(result.message ?? "Event updated.", "success")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't save the event. ${appErrorFromWp(err).message}`, "error")
    } finally {
      savingEventRef.current = false
      setSavingEvent(false)
    }
  }

  async function handleSavePricing(opp: OrganizerEventOpportunity) {
    if (savingPricingId) return
    const draft = pricingDraft[opp.id] ?? { fee: "", spots: "" }
    const fee = Number(draft.fee)
    const spots = Number(draft.spots)
    if (draft.fee.trim() === "" || !Number.isFinite(fee) || fee < 0) {
      showToast("Enter a vendor fee of $0 or more.", "error")
      return
    }
    if (!Number.isFinite(spots) || spots < 1) {
      showToast("Enter at least 1 vendor spot.", "error")
      return
    }
    const held = heldSpots(opp)
    if (held > 0 && spots < held) {
      showToast(`${held} spot${held === 1 ? " is" : "s are"} already booked or held — spots can't go below that.`, "error")
      return
    }
    setSavingPricingId(opp.id)
    try {
      const updated = await updateOpportunityPricing(opp.id, { vendorFee: fee, spotsTotal: spots }, auth)
      invalidateOrganizerMobile(id)
      showToast(updated.message ?? "Vendor fee and spots updated.", "success")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't update pricing. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setSavingPricingId(null)
    }
  }

  async function handleToggleSlot(opp: OrganizerEventOpportunity) {
    if (statusBusyId) return
    const closing = opp.status !== "closed"
    if (closing) {
      const ok = await confirmAction({
        title: "Close this slot?",
        message: "Vendors can't apply to it anymore. Existing applications and bookings stay as they are.",
        confirmLabel: "Close slot",
        destructive: true,
      })
      if (!ok) return
    }
    setStatusBusyId(opp.id)
    try {
      const result = await setOpportunityListingStatus(opp.id, closing ? "closed" : "open", auth)
      invalidateOrganizerMobile(id)
      showToast(result.message ?? (closing ? "Slot closed." : "Slot reopened."), "success")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't update the slot. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setStatusBusyId(null)
    }
  }

  /** Publishing = opening the event's vendor slots so vendors can apply (server rule). */
  async function handlePublish() {
    if (!event || publishing) return
    const closed = opportunities.filter((opp) => opp.status === "closed")
    if (opportunities.length === 0) {
      setTab("inventory")
      showToast("Add a vendor slot first — publishing opens it for applications.", "info")
      return
    }
    const ok = await confirmAction({
      title: "Publish this event?",
      message: "Vendors will be able to find it and apply. You can close slots again at any time.",
      confirmLabel: "Publish",
    })
    if (!ok) return
    setPublishing(true)
    try {
      for (const opp of closed) {
        await setOpportunityListingStatus(opp.id, "open", auth)
      }
      invalidateOrganizerMobile(id)
      showToast("Event published. Vendors can apply now.", "success")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't publish. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setPublishing(false)
    }
  }

  async function handleAddVendorType() {
    if (!event || addingRef.current) return
    const category = newCategory.trim()
    const fee = Number(newFee)
    const spots = Number(newSpots)
    if (!category) {
      showToast("Choose or type a vendor type.", "error")
      return
    }
    if (newFee.trim() === "" || !Number.isFinite(fee) || fee < 0) {
      showToast("Enter a vendor fee of $0 or more.", "error")
      return
    }
    if (!Number.isFinite(spots) || spots < 1) {
      showToast("Enter at least 1 vendor spot.", "error")
      return
    }
    addingRef.current = true
    setAddingOpp(true)
    try {
      const result = await addOrganizerOpportunities(
        event.id,
        [
          {
            categories: [category],
            vendor_category: category,
            vendor_fee: fee,
            spots_total: spots,
            opportunity_title: `${category} — ${event.title}`,
          },
        ],
        auth,
        addTokenRef.current,
      )
      addTokenRef.current = newClientToken()
      setNewCategory("")
      setNewFee("")
      setNewSpots("")
      invalidateOrganizerMobile(id)
      showToast(result.message ?? "Vendor type added.", "success")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't add the vendor type. ${appErrorFromWp(err).message}`, "error")
    } finally {
      addingRef.current = false
      setAddingOpp(false)
    }
  }

  function handleShare() {
    if (!event) return
    const oppId = opportunities.find((o) => o.status !== "closed")?.id || opportunities[0]?.id
    if (!oppId) {
      showToast("Add a vendor slot first so vendors have a link to apply.", "info")
      return
    }
    const fallback = getOpportunityApplyUrl(oppId)
    const title = event.title
    void (auth ? fetchShareLink(auth, { type: "opportunity", id: Number(oppId) }).then((link) => link.url) : Promise.reject(new Error("guest")))
      .catch(() => fallback)
      .then((url) => Share.share({ message: `Apply to vend at ${title} on LNKup\n${url}`, url }))
      .catch(() => undefined)
  }

  function openSwitcher() {
    setSwitcherOpen(true)
    if (auth && switcherEvents === null) {
      void fetchOrganizerEvents(auth, { chronology: "upcoming" })
        .then((result) => setSwitcherEvents(result?.events ?? []))
        .catch(() => setSwitcherEvents([]))
    }
  }

  const showLoader = initialLoad && !event
  const place = event ? event.place ?? [event.city, event.state].filter(Boolean).join(", ") : ""
  const metaLine = event
    ? eventMetaLine({ date: event.date ?? "", dateLabel: "", venue: event.venueName ?? "", place }) || "Date and location to be announced"
    : ""

  return (
    <Screen
      title={event?.title ?? "Event"}
      hideTitle
      refreshing={refreshing}
      onRefresh={() => {
        void load("refresh")
        if (tab === "vendors" && vendorSegment === "confirmed") void loadRoster(true)
        if (tab === "money") void loadMoney()
      }}
      header={<BackLink label="Events" fallbackHref="/(organizer)/events" />}
    >
      {showLoader ? <LoadingPanel label="Loading event…" variant="skeleton" withMedia count={2} /> : null}

      {!showLoader && error && !event ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("refresh")} />
      ) : null}

      {!showLoader && !error && !event ? (
        <EmptyPanel
          icon="calendar-outline"
          title="Event not found"
          message="It isn't in your organizer account, or it was removed."
          actionLabel="Back to Events"
          onAction={() => router.replace("/(organizer)/events")}
        />
      ) : null}

      {event ? (
        <View style={styles.page}>
          {refreshError ? (
            <InlineBanner tone="error" message={`Couldn't refresh: ${refreshError}`} actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : null}

          <View style={styles.header} testID="event-header">
            <View style={styles.headerThumb}>
              <EventImage
                uri={event.media?.largeUrl || event.imageUrl}
                fit={event.media?.fit}
                background={event.media?.background}
                aspectRatio={1}
                radius={radii.md}
                icon="calendar-outline"
              />
            </View>
            <View style={styles.headerText}>
              <Text style={styles.title} numberOfLines={2} accessibilityRole="header">
                {event.title}
              </Text>
              <Text style={styles.meta} numberOfLines={2}>
                {metaLine}
              </Text>
              <View style={styles.headerChips}>
                <StatusBadge label={eventListingLabel(state)} tone={state === "draft" ? "waitlist" : state === "past" ? "neutral" : "approved"} />
                {isEventDay ? <StatusBadge label="Today" tone="due" /> : null}
              </View>
            </View>
          </View>
          <View style={styles.headerActions}>
            <Button label="Share" icon="share-outline" variant="secondary" size="sm" onPress={handleShare} testID="organizer-share-event" />
            <Button label="Switch event" icon="swap-horizontal-outline" variant="ghost" size="sm" onPress={openSwitcher} testID="event-switcher" />
          </View>

          <SegmentedTabs tabs={tabs} value={tab} onChange={setTab} testID="event-workspace-tabs" />

          {tab === "overview" ? (
            <View style={styles.section}>
              {state === "draft" ? (
                <Surface variant="urgent" style={styles.card} testID="publish-checklist">
                  <SectionHeader title="Ready to publish?" subtitle="Vendors can't see this event until a slot is open." icon="rocket-outline" />
                  <ListGroup>
                    {checklist.map((item) => (
                      <ListRow
                        key={item.key}
                        icon={item.done ? "checkmark-circle" : "ellipse-outline"}
                        iconTone={item.done ? "accent" : "default"}
                        title={item.label}
                        meta={item.required ? (item.done ? "Done" : "Required") : "Optional"}
                        onPress={item.done ? undefined : () => setTab(item.tab)}
                      />
                    ))}
                  </ListGroup>
                  <Button
                    label="Publish event"
                    loadingLabel="Publishing…"
                    variant="primary"
                    icon="rocket-outline"
                    loading={publishing}
                    disabled={!checklistReady}
                    onPress={() => void handlePublish()}
                    testID="publish-event"
                  />
                  <Button
                    label="Preview as vendor"
                    variant="ghost"
                    size="sm"
                    icon="eye-outline"
                    disabled={!opportunities[0]}
                    onPress={() => opportunities[0] && router.push(`/opportunity/${opportunities[0].id}` as Href)}
                  />
                </Surface>
              ) : null}
              <StatGrid>
                <StatTile icon="file-tray-outline" label="To review" value={event.toReviewCount ?? pendingRequests.length} tone={pendingRequests.length > 0 ? "urgent" : "default"} onPress={() => setTab("vendors")} />
                <StatTile icon="card-outline" label="Awaiting pay" value={event.paymentRequiredCount ?? 0} onPress={() => setTab("money")} />
                <StatTile icon="ticket-outline" label="Confirmed" value={event.paidCount ?? 0} onPress={() => { setVendorSegment("confirmed"); setTab("vendors") }} />
              </StatGrid>
              {event.capacity ? (
                <Surface style={styles.card}>
                  <SectionHeader title="Booth capacity" icon="grid-outline" />
                  <CapacityBar capacity={event.capacity} />
                  <Meta>Confirmed = paid. Held = approved and waiting to pay.</Meta>
                </Surface>
              ) : null}
              {score ? <ScoreCard score={score} eventId={id} onTab={setTab} /> : null}
              <ListGroup title="Next steps">
                {pendingRequests.length > 0 ? (
                  <ListRow icon="people-outline" iconTone="accent" title={`Review ${pendingRequests.length} application${pendingRequests.length === 1 ? "" : "s"}`} meta="Approve, decline, waitlist or ask a question" onPress={() => setTab("vendors")} />
                ) : null}
                {opportunities.length === 0 ? (
                  <ListRow icon="pricetag-outline" iconTone="accent" title="Add a vendor slot" meta="Set booth types, prices and spots" onPress={() => setTab("inventory")} />
                ) : null}
                {state !== "draft" && state !== "past" ? (
                  <ListRow icon="search-outline" title="Find vendors for open spaces" meta="Recruit by category for this event" onPress={() => router.push(`/(organizer)/vendors/find?eventId=${id}` as Href)} />
                ) : null}
                <ListRow icon="megaphone-outline" title="Communications" meta="Message your vendors and see what was sent" onPress={() => router.push(`/(organizer)/event-comms/${id}` as Href)} />
                <ListRow icon="copy-outline" title="Duplicate event" meta="Reuse booth types and prices on a new date" onPress={() => setDuplicateOpen(true)} />
              </ListGroup>
              <CollapsibleSection title="Edit event details" subtitle="Name, date, location, and description" icon="create-outline">
                <FormField label="Event name" value={editTitle} onChangeText={setEditTitle} />
                <IsoDatePickerField label="Event date" value={editDate || undefined} onChange={setEditDate} optional />
                <LocationField
                  city={editCity}
                  state={editState}
                  onChange={({ city, state: nextState }) => {
                    setEditCity(city)
                    setEditState(nextState)
                  }}
                  onStatusChange={setEditLocationStatus}
                />
                <FormField label="Venue" value={editVenue} onChangeText={setEditVenue} />
                <FormField label="Description" value={editDescription} onChangeText={setEditDescription} multiline hint="Booked vendors are notified when the date or venue changes." />
                <Button label="Save event details" loadingLabel="Saving…" variant="accent" loading={savingEvent} disabled={!isLocationSubmittable(editLocationStatus)} onPress={() => void handleSaveEvent()} />
              </CollapsibleSection>
            </View>
          ) : null}

          {tab === "vendors" ? (
            <View style={styles.section}>
              <SegmentedControl
                options={[
                  { value: "applications", label: "Applications", count: pendingRequests.length || undefined },
                  { value: "confirmed", label: "Confirmed", count: event.paidCount || undefined },
                  { value: "invites", label: "Invitations" },
                ]}
                value={vendorSegment}
                onChange={setVendorSegment}
              />
              {vendorSegment === "applications" ? (
                requests.length === 0 ? (
                  <EmptyPanel icon="file-tray-outline" title="No applications yet" message="Share your apply link or invite vendors." actionLabel="Share apply link" onAction={handleShare} />
                ) : (
                  <ListGroup>
                    {requests.map((request) => (
                      <ApplicationRow key={request.id} request={request} onPress={() => router.push(`/(organizer)/vendors/review/${request.id}?eventId=${id}` as Href)} />
                    ))}
                  </ListGroup>
                )
              ) : null}
              {vendorSegment === "confirmed" ? (
                <>
                  {rosterError ? <ErrorPanel title={rosterError.title} message={rosterError.message} onAction={() => void loadRoster(true)} /> : null}
                  {!roster && !rosterError ? <LoadingPanel label="Loading vendors…" variant="skeleton" count={2} /> : null}
                  {roster && confirmed.length === 0 ? (
                    <EmptyPanel icon="ticket-outline" title="No confirmed vendors yet" message="Vendors appear here once they've paid their booth fee." />
                  ) : null}
                  {confirmed.length > 0 ? (
                    <ListGroup title={`${confirmed.length} confirmed`}>
                      {confirmed.map((vendor) => (
                        <ListRow
                          key={vendor.applicationId}
                          icon={vendor.checkedIn ? "checkmark-circle" : "storefront-outline"}
                          iconTone={vendor.checkedIn ? "accent" : "default"}
                          title={vendor.businessName}
                          meta={[vendor.booth ? `Booth ${vendor.booth}` : "No booth yet", vendor.checkedIn ? "Checked in" : null].filter(Boolean).join(" · ")}
                          onPress={vendor.profileId ? () => router.push(vendorProfileHref(vendor.profileId!, vendor.applicationId) as Href) : undefined}
                        />
                      ))}
                    </ListGroup>
                  ) : null}
                </>
              ) : null}
              {vendorSegment === "invites" ? (
                invites === null ? (
                  <LoadingPanel label="Loading invitations…" variant="skeleton" count={2} />
                ) : invites.length === 0 ? (
                  <EmptyPanel
                    icon="mail-outline"
                    title="No invitations for this event"
                    message="Invite vendors who fit your open spaces."
                    actionLabel="Find vendors"
                    onAction={() => router.push(`/(organizer)/vendors/find?eventId=${id}` as Href)}
                  />
                ) : (
                  <ListGroup>
                    {invites.map((invite) => (
                      <InviteRow key={invite.id} invite={invite} onPress={() => router.push(`/(organizer)/vendors?segment=invites&invite=${invite.id}` as Href)} />
                    ))}
                  </ListGroup>
                )
              ) : null}
            </View>
          ) : null}

          {tab === "inventory" ? (
            <View style={styles.section}>
              {opportunities.length === 0 ? (
                <EmptyPanel icon="storefront-outline" title="No vendor slots yet" message="Add a vendor type below so vendors have something to apply to." />
              ) : (
                opportunities.map((opp) => {
                  const draft = pricingDraft[opp.id] ?? { fee: "", spots: "" }
                  const name = opp.category || opp.label || "Vendor booth"
                  const held = heldSpots(opp)
                  const closed = opp.status === "closed"
                  return (
                    <Surface key={opp.id} style={styles.card}>
                      <View style={styles.slotHeader}>
                        <SectionTitle style={styles.slotTitle} numberOfLines={2}>
                          {name}
                        </SectionTitle>
                        <StatusBadge label={closed ? "Closed" : (opp.spotsRemaining ?? 1) <= 0 ? "Full" : "Open"} tone={closed ? "neutral" : (opp.spotsRemaining ?? 1) <= 0 ? "rejected" : "approved"} />
                      </View>
                      <View style={styles.inventory} accessibilityLabel={`${held} held, ${opp.spotsRemaining ?? 0} available of ${opp.spotsTotal ?? 0}`}>
                        <Text style={styles.inventoryText}>
                          <Text style={styles.inventoryStrong}>{held}</Text> held · <Text style={styles.inventoryStrong}>{opp.spotsRemaining ?? 0}</Text> available of {opp.spotsTotal ?? 0}
                        </Text>
                        {opp.spotsTotal ? <ProgressBar percent={(held / opp.spotsTotal) * 100} label={`${name} ${held} of ${opp.spotsTotal} held`} /> : null}
                      </View>
                      <View style={styles.row}>
                        <View style={styles.flex}>
                          <FormField
                            label="Vendor fee ($)"
                            value={draft.fee}
                            onChangeText={(raw) => setPricingDraft((prev) => ({ ...prev, [opp.id]: { ...draft, fee: raw.replace(/[^0-9.]/g, "") } }))}
                            keyboardType="decimal-pad"
                            accessibilityLabel={`${name} vendor fee in dollars`}
                          />
                        </View>
                        <View style={styles.flex}>
                          <FormField
                            label="Spots"
                            value={draft.spots}
                            onChangeText={(raw) => setPricingDraft((prev) => ({ ...prev, [opp.id]: { ...draft, spots: raw.replace(/[^0-9]/g, "") } }))}
                            keyboardType="number-pad"
                            hint={held > 0 ? `At least ${held}` : undefined}
                            accessibilityLabel={`${name} spots`}
                          />
                        </View>
                      </View>
                      <View style={styles.row}>
                        <Button label="Save fee & spots" loadingLabel="Saving…" variant="accent" style={styles.flex} loading={savingPricingId === opp.id} onPress={() => void handleSavePricing(opp)} />
                        <Button label={closed ? "Reopen" : "Close"} variant="secondary" loading={statusBusyId === opp.id} onPress={() => void handleToggleSlot(opp)} accessibilityLabel={`${closed ? "Reopen" : "Close"} ${name}`} />
                      </View>
                      <Button label="Preview as vendor" variant="ghost" size="sm" icon="eye-outline" onPress={() => router.push(`/opportunity/${opp.id}` as Href)} />
                    </Surface>
                  )
                })
              )}

              <Surface style={styles.card}>
                <SectionTitle style={styles.slotTitle}>Add vendor type</SectionTitle>
                <View style={styles.chips}>
                  {LAUNCH_CATEGORIES.map((cat) => (
                    <FilterChip key={cat} label={cat} selected={newCategory === cat} onPress={() => setNewCategory(cat)} />
                  ))}
                </View>
                <FormField
                  label="Or type a category"
                  value={LAUNCH_CATEGORIES.includes(newCategory as (typeof LAUNCH_CATEGORIES)[number]) ? "" : newCategory}
                  onChangeText={setNewCategory}
                  placeholder="Custom vendor type"
                />
                <View style={styles.row}>
                  <View style={styles.flex}>
                    <FormField label="Fee ($)" value={newFee} placeholder="0" onChangeText={(raw) => setNewFee(raw.replace(/[^0-9.]/g, ""))} keyboardType="decimal-pad" />
                  </View>
                  <View style={styles.flex}>
                    <FormField label="Spots" value={newSpots} placeholder="10" onChangeText={(raw) => setNewSpots(raw.replace(/[^0-9]/g, ""))} keyboardType="number-pad" />
                  </View>
                </View>
                <Button label="Add vendor type" loadingLabel="Adding…" icon="add" loading={addingOpp} onPress={() => void handleAddVendorType()} />
              </Surface>
            </View>
          ) : null}

          {tab === "money" ? (
            <View style={styles.section}>
              {moneyError ? <ErrorPanel title={moneyError.title} message={moneyError.message} onAction={() => void loadMoney()} /> : null}
              {!money && !moneyError ? <LoadingPanel label="Loading payments…" variant="skeleton" count={2} /> : null}
              {money ? (
                <>
                  <StatGrid>
                    <StatTile icon="checkmark-circle-outline" label="Collected (gross)" value={formatMoney(money.collected, money.currency)} tone="accent" />
                    <StatTile icon="hourglass-outline" label="Outstanding" value={formatMoney(money.pending, money.currency)} tone={(money.pending ?? 0) > 0 ? "urgent" : "default"} />
                  </StatGrid>
                  <Meta>
                    {money.paidVendorCount ?? event.paidCount ?? 0} paid · {money.paymentRequiredCount ?? event.paymentRequiredCount ?? 0} awaiting payment. Gross is what vendors paid, before LNKup fees; your net and payouts are on Money.
                  </Meta>
                  <Button label="Open event financials" icon="cash-outline" variant="secondary" onPress={() => router.push(`/(organizer)/money/event?eventId=${id}` as Href)} />
                </>
              ) : null}
            </View>
          ) : null}

          {tab === "operations" ? (
            <View style={styles.section}>
              {ops ? (
                <Surface variant={isEventDay ? "accent" : "default"} style={styles.card}>
                  <SectionHeader title={isEventDay ? "Event day" : ops.mode === "post_event" ? "After the event" : "Getting ready"} icon="construct-outline" />
                  <Meta>
                    {ops.checkin.checkedIn} of {ops.checkin.expected} eligible vendors checked in
                    {ops.checkin.blocked.paymentPending + ops.checkin.blocked.documents > 0
                      ? ` · ${ops.checkin.blocked.paymentPending + ops.checkin.blocked.documents} blocked`
                      : ""}
                  </Meta>
                  <Meta>
                    {ops.booths.assigned} of {ops.booths.confirmed} confirmed vendors have a booth · packet {ops.packet.stage === "draft" ? "not published" : "published"}
                  </Meta>
                  <Button label="Open operations" icon="construct-outline" onPress={() => router.push(`/(organizer)/events/operations?eventId=${id}` as Href)} testID="open-operations" />
                </Surface>
              ) : null}
              <ListGroup>
                <ListRow
                  icon="checkmark-done-outline"
                  iconTone={isEventDay ? "accent" : "default"}
                  title="Check-in"
                  meta="Find a vendor or enter a pass code"
                  onPress={() => router.push(`/(organizer)/events/checkin?eventId=${id}` as Href)}
                />
                <ListRow icon="grid-outline" title="Booths and map" meta="Assign booths and view the event map" onPress={() => router.push(`/(organizer)/events/booths?eventId=${id}` as Href)} />
                <ListRow icon="megaphone-outline" title="Communications" meta="Message your vendors" onPress={() => router.push(`/(organizer)/event-comms/${id}` as Href)} />
              </ListGroup>
              <CollapsibleSection title="Vendor packet" subtitle="Arrival, gate and rules on vendor passes" icon="document-text-outline" defaultOpen={tabParam === "packet"}>
                <PacketForm eventId={id} auth={auth} />
              </CollapsibleSection>
            </View>
          ) : null}
        </View>
      ) : null}

      {event ? (
        <DuplicateEventSheet
          visible={duplicateOpen}
          onClose={() => setDuplicateOpen(false)}
          eventId={id}
          title={event.title}
          place={place}
          auth={auth}
        />
      ) : null}

      <BottomSheet visible={switcherOpen} onClose={() => setSwitcherOpen(false)} title="Switch event">
        {switcherEvents === null ? (
          <LoadingPanel label="Loading events…" variant="spinner" />
        ) : switcherEvents.length === 0 ? (
          <Meta>No other upcoming events.</Meta>
        ) : (
          <View style={styles.switcher}>
            {switcherEvents.map((item) => (
              <Pressable
                key={item.id}
                onPress={() => {
                  setSwitcherOpen(false)
                  if (item.id !== id) router.replace(`/(organizer)/events/${item.id}?tab=${tab}` as Href)
                }}
                accessibilityRole="button"
                accessibilityLabel={`${item.title}, ${formatShortDate(item.date) ?? "no date"}`}
                style={[styles.switcherRow, item.id === id && styles.switcherCurrent]}
              >
                <View style={styles.flex}>
                  <Text style={styles.switcherTitle} numberOfLines={1}>
                    {item.title}
                  </Text>
                  <Text style={styles.meta}>{formatShortDate(item.date) ?? "Date to be announced"}</Text>
                </View>
                {item.id === id ? <AppIcon name="checkmark" size={18} color={colors.black} /> : null}
              </Pressable>
            ))}
          </View>
        )}
      </BottomSheet>
    </Screen>
  )
}

function ScoreCard({ score, eventId, onTab }: { score: EventScore; eventId: string; onTab: (tab: TabKey) => void }) {
  const weakest = [...score.factors].filter((f) => !f.estimated).sort((a, b) => a.value - b.value).slice(0, 3)
  return (
    <CollapsibleSection title={`Event score · ${score.score}`} subtitle={score.label} icon="analytics-outline">
      {weakest.map((factor) => {
        const href = factor.action ? scoreActionHref(factor.action.target, eventId) : null
        return (
          <View key={factor.key} style={styles.factor}>
            <View style={styles.factorHead}>
              <Text style={styles.factorLabel}>{factor.label}</Text>
              <Text style={styles.factorValue}>{factor.value}/100</Text>
            </View>
            <ProgressBar percent={factor.value} label={`${factor.label} ${factor.value} of 100`} />
            <Meta>
              {factor.explanation}
              {factor.weightPct != null ? ` Counts for ${factor.weightPct}% of the score.` : ""}
            </Meta>
            {factor.action && href ? (
              <Button
                label={factor.action.label}
                variant="ghost"
                size="sm"
                onPress={() => (typeof href === "string" && href.startsWith("tab:") ? onTab(href.slice(4) as TabKey) : router.push(href as Href))}
              />
            ) : null}
          </View>
        )
      })}
      <Meta>Category mix is a fixed estimate until LNKup measures category balance.</Meta>
    </CollapsibleSection>
  )
}

const styles = StyleSheet.create({
  page: { gap: spacing.lg },
  header: { flexDirection: "row", gap: spacing.md },
  headerThumb: { width: 76 },
  headerText: { flex: 1, gap: spacing.xs },
  headerChips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.xs },
  headerActions: { flexDirection: "row", justifyContent: "space-between", alignItems: "center" },
  title: { fontFamily: fonts.display, fontSize: typeScale.title, color: colors.black },
  meta: { ...typography.meta },
  section: { gap: spacing.md },
  card: { gap: spacing.md },
  row: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
  slotHeader: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: spacing.sm },
  slotTitle: { flex: 1, fontSize: typeScale.bodyLarge },
  inventory: { gap: 6 },
  inventoryText: { fontFamily: fonts.body, fontSize: typeScale.footnote, color: colors.gray700 },
  inventoryStrong: { fontFamily: fonts.bodyBold, color: colors.black },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  factor: { gap: spacing.xs, paddingVertical: spacing.xs },
  factorHead: { flexDirection: "row", justifyContent: "space-between" },
  factorLabel: { ...typography.label },
  factorValue: { ...typography.label, color: colors.gray700 },
  switcher: { gap: spacing.xs },
  switcherRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 8,
    paddingHorizontal: spacing.md,
    borderRadius: radii.md,
    backgroundColor: colors.gray50,
  },
  switcherCurrent: { backgroundColor: colors.limeMuted },
  switcherTitle: { ...typography.label },
})
