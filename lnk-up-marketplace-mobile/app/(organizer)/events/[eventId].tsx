import { useCallback, useEffect, useMemo, useRef, useState } from "react"
import { Share, StyleSheet, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { IsoDatePickerField } from "@/components/iso-date-picker-field"
import { OrganizerRequestCard } from "@/components/organizer-vendors/organizer-request-card"
import { vendorProfileHref } from "@/components/organizer-vendors/vendor-queue"
import { PacketForm } from "@/components/organizer-workspace/packet-form"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { FilterChip } from "@/components/ui/chip"
import { CollapsibleSection } from "@/components/ui/collapsible-section"
import { EventImage } from "@/components/ui/event-image"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { SegmentedTabs, type SegmentedTab } from "@/components/ui/segmented-tabs"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { newClientToken } from "@/lib/api/client-token"
import { getOpportunityApplyUrl } from "@/lib/api/config"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
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
import { fetchOrganizerRequests, type OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { confirmedVendors, fetchEventRoster, type EventRoster } from "@/lib/api/organizer-roster"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, fonts, radii, spacing } from "@/lib/theme"

type TabKey = "overview" | "slots" | "applications" | "vendors" | "payments" | "dayof" | "packet"
const TAB_KEYS: readonly TabKey[] = ["overview", "slots", "applications", "vendors", "payments", "dayof", "packet"]

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
  return TAB_KEYS.find((key) => key === raw) ?? "overview"
}

/** Event workspace: one event's setup, applications, vendors, money and event-day tools. */
export default function OrganizerEventWorkspaceScreen() {
  const { eventId, tab: tabParam } = useLocalSearchParams<{ eventId: string; tab?: string }>()
  const { auth } = useAuth()
  const id = String(eventId ?? "")
  const [tab, setTab] = useState<TabKey>(() => parseTab(tabParam))
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
  const [pricingDraft, setPricingDraft] = useState<PricingDraft>({})
  const [savingPricingId, setSavingPricingId] = useState<string | null>(null)
  const [statusBusyId, setStatusBusyId] = useState<string | null>(null)
  const [editTitle, setEditTitle] = useState("")
  const [editDate, setEditDate] = useState("")
  const [editCity, setEditCity] = useState("")
  const [editState, setEditState] = useState("")
  const [editVenue, setEditVenue] = useState("")
  const [editDescription, setEditDescription] = useState("")
  const [savingEvent, setSavingEvent] = useState(false)
  const [newCategory, setNewCategory] = useState("")
  const [newFee, setNewFee] = useState("250")
  const [newSpots, setNewSpots] = useState("10")
  const [addingOpp, setAddingOpp] = useState(false)
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
      try {
        const [data, requestResult] = await Promise.all([
          fetchOrganizerEvent(id, auth),
          fetchOrganizerRequests(auth, { force: mode === "refresh" }).catch(() => null),
        ])
        setEvent(data)
        if (data) syncEditFields(data)
        if (requestResult) setRequests(requestResult.items.filter((item) => item.eventId === id))
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
    if (tab === "vendors" || tab === "dayof") void loadRoster()
    if (tab === "payments") void loadMoney()
  }, [tab, loadRoster, loadMoney])

  const opportunities = event ? opportunitiesFromEvent(event) : []
  const state = event ? eventListingState(event) : "live"
  const pendingRequests = useMemo(
    () => requests.filter((r) => r.status === "submitted" || r.status === "under_review" || r.status === "info_requested"),
    [requests],
  )
  const confirmed = useMemo(() => (roster ? confirmedVendors(roster) : []), [roster])
  const inboxHref = `/(organizer)/vendors?tab=requests&event=${encodeURIComponent(id)}` as Href

  const tabs = useMemo<SegmentedTab<TabKey>[]>(
    () => [
      { value: "overview", label: "Overview" },
      { value: "slots", label: "Slots", count: opportunities.length || undefined },
      {
        value: "applications",
        label: "Applications",
        count: pendingRequests.length || undefined,
        attention: pendingRequests.length > 0,
      },
      { value: "vendors", label: "Vendors", count: event?.paidCount || undefined },
      {
        value: "payments",
        label: "Payments",
        count: event?.paymentRequiredCount || undefined,
        attention: (event?.paymentRequiredCount ?? 0) > 0,
      },
      { value: "dayof", label: "Day-of" },
      { value: "packet", label: "Packet" },
    ],
    [opportunities.length, pendingRequests.length, event?.paidCount, event?.paymentRequiredCount],
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
      showToast(result.message ?? (closing ? "Slot closed." : "Slot reopened."), "success")
      await load("refresh")
    } catch (err) {
      showToast(`Couldn't update the slot. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setStatusBusyId(null)
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
      setNewFee("250")
      setNewSpots("10")
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
    const url = getOpportunityApplyUrl(oppId)
    void Share.share({ message: `Apply to vend at ${event.title} on LNKup\n${url}`, url }).catch(() => undefined)
  }

  const showLoader = initialLoad && !event

  return (
    <Screen
      title={event?.title ?? "Event"}
      hideTitle
      refreshing={refreshing}
      onRefresh={() => {
        void load("refresh")
        if (tab === "vendors" || tab === "dayof") void loadRoster(true)
        if (tab === "payments") void loadMoney()
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
        <>
          {refreshError ? (
            <InlineBanner tone="error" message={`Couldn't refresh: ${refreshError}`} actionLabel="Retry" onAction={() => void load("refresh")} />
          ) : null}

          <View style={styles.hero}>
            <EventImage uri={event.imageUrl} label={event.title} height={140} icon="calendar-outline" />
            <View style={styles.heroRow}>
              <StatusBadge label={eventListingLabel(state)} tone={state === "draft" ? "waitlist" : state === "past" ? "neutral" : "approved"} />
              <Button label="Share" icon="share-outline" variant="secondary" size="sm" onPress={handleShare} testID="organizer-share-event" />
            </View>
            <DisplayTitle accessibilityRole="header" style={styles.title}>
              {event.title}
            </DisplayTitle>
            <Meta>
              {[event.date, [event.venueName, event.city, event.state].filter(Boolean).join(", ")].filter(Boolean).join(" · ") ||
                "Date and location TBD"}
            </Meta>
          </View>

          <SegmentedTabs tabs={tabs} value={tab} onChange={setTab} testID="event-workspace-tabs" />

          {tab === "overview" ? (
            <View style={styles.section}>
              {state === "draft" ? (
                <InlineBanner
                  tone="attention"
                  title="This event is a draft"
                  message="Vendors can't see it yet. Publish it from LNKup web or the Create flow."
                />
              ) : null}
              <StatGrid>
                <StatTile icon="file-tray-outline" label="To review" value={event.toReviewCount ?? pendingRequests.length} tone={pendingRequests.length > 0 ? "urgent" : "default"} onPress={() => setTab("applications")} />
                <StatTile icon="card-outline" label="Awaiting pay" value={event.paymentRequiredCount ?? 0} onPress={() => setTab("payments")} />
                <StatTile icon="ticket-outline" label="Confirmed" value={event.paidCount ?? 0} onPress={() => setTab("vendors")} />
              </StatGrid>
              <ListGroup title="Next steps">
                {pendingRequests.length > 0 ? (
                  <ListRow icon="people-outline" iconTone="accent" title={`Review ${pendingRequests.length} application${pendingRequests.length === 1 ? "" : "s"}`} meta="Approve, decline, waitlist or ask a question" onPress={() => router.push(inboxHref)} />
                ) : null}
                {opportunities.length === 0 ? (
                  <ListRow icon="pricetag-outline" iconTone="accent" title="Add a vendor slot" meta="Set booth types, prices and spots" onPress={() => setTab("slots")} />
                ) : null}
                <ListRow icon="qr-code-outline" title="Event-day check-in" meta="Check vendors in and see who's arrived" onPress={() => router.push(`/(organizer)/events/checkin?eventId=${id}` as Href)} />
                <ListRow icon="document-text-outline" title="Pass details" meta="Arrival, gate and rules on vendor passes" onPress={() => setTab("packet")} />
              </ListGroup>
              <CollapsibleSection title="Edit event details" subtitle="Name, date, location, and description" icon="create-outline">
                <FormField label="Event name" value={editTitle} onChangeText={setEditTitle} />
                <IsoDatePickerField label="Event date" value={editDate || undefined} onChange={setEditDate} optional />
                <View style={styles.row}>
                  <View style={styles.flex}>
                    <FormField label="City" value={editCity} onChangeText={setEditCity} />
                  </View>
                  <View style={styles.stateField}>
                    <FormField label="State" value={editState} onChangeText={(v) => setEditState(v.toUpperCase().slice(0, 2))} autoCapitalize="characters" maxLength={2} />
                  </View>
                </View>
                <FormField label="Venue" value={editVenue} onChangeText={setEditVenue} />
                <FormField label="Description" value={editDescription} onChangeText={setEditDescription} multiline hint="Booked vendors are notified when the date or venue changes." />
                <Button label="Save event details" loadingLabel="Saving…" variant="accent" loading={savingEvent} onPress={() => void handleSaveEvent()} />
              </CollapsibleSection>
            </View>
          ) : null}

          {tab === "slots" ? (
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
                    <Surface key={opp.id} style={styles.slot}>
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
                        {opp.spotsTotal ? (
                          <View style={styles.inventoryTrack}>
                            <View style={[styles.inventoryFill, { width: `${Math.min(100, (held / opp.spotsTotal) * 100)}%` }]} />
                          </View>
                        ) : null}
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

              <Surface style={styles.slot}>
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
                    <FormField label="Fee ($)" value={newFee} onChangeText={(raw) => setNewFee(raw.replace(/[^0-9.]/g, ""))} keyboardType="decimal-pad" />
                  </View>
                  <View style={styles.flex}>
                    <FormField label="Spots" value={newSpots} onChangeText={(raw) => setNewSpots(raw.replace(/[^0-9]/g, ""))} keyboardType="number-pad" />
                  </View>
                </View>
                <Button label="Add vendor type" loadingLabel="Adding…" icon="add" loading={addingOpp} onPress={() => void handleAddVendorType()} />
              </Surface>
            </View>
          ) : null}

          {tab === "applications" ? (
            <View style={styles.section}>
              <Button label={pendingRequests.length > 0 ? `Review ${pendingRequests.length} in the inbox` : "Open the inbox"} icon="people-outline" onPress={() => router.push(inboxHref)} />
              {requests.length === 0 ? (
                <EmptyPanel icon="file-tray-outline" title="No applications yet" message="Share your apply link or invite vendors from Discover." actionLabel="Share apply link" onAction={handleShare} />
              ) : (
                requests.map((request) => (
                  <OrganizerRequestCard
                    key={request.id}
                    item={request}
                    acting={false}
                    onOpenProfile={request.vendorProfileId ? () => router.push(vendorProfileHref(request.vendorProfileId!, request.id) as Href) : undefined}
                  />
                ))
              )}
            </View>
          ) : null}

          {tab === "vendors" ? (
            <View style={styles.section}>
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
            </View>
          ) : null}

          {tab === "payments" ? (
            <View style={styles.section}>
              {moneyError ? <ErrorPanel title={moneyError.title} message={moneyError.message} onAction={() => void loadMoney()} /> : null}
              {!money && !moneyError ? <LoadingPanel label="Loading payments…" variant="skeleton" count={2} /> : null}
              {money ? (
                <>
                  <StatGrid>
                    <StatTile icon="checkmark-circle-outline" label="Collected" value={formatMoney(money.collected, money.currency)} tone="accent" />
                    <StatTile icon="hourglass-outline" label="Outstanding" value={formatMoney(money.pending, money.currency)} tone={(money.pending ?? 0) > 0 ? "urgent" : "default"} />
                  </StatGrid>
                  <Meta>
                    {money.paidVendorCount ?? event.paidCount ?? 0} paid · {money.paymentRequiredCount ?? event.paymentRequiredCount ?? 0} awaiting payment. Booth fees settle through LNKup; payouts are on Money.
                  </Meta>
                  <Button label="Open Money" icon="cash-outline" variant="secondary" onPress={() => router.push("/(organizer)/money")} />
                </>
              ) : null}
            </View>
          ) : null}

          {tab === "dayof" ? (
            <View style={styles.section}>
              <Surface variant="hero" style={styles.dayof}>
                <SectionTitle style={styles.slotTitle}>Event-day check-in</SectionTitle>
                <Meta>
                  {roster
                    ? `${confirmed.filter((v) => v.checkedIn).length} of ${confirmed.length} confirmed vendors checked in`
                    : "Search vendors or enter a pass code to check them in."}
                </Meta>
                <Button label="Open check-in" icon="qr-code-outline" onPress={() => router.push(`/(organizer)/events/checkin?eventId=${id}` as Href)} />
              </Surface>
              <ListGroup title="More tools">
                <ListRow icon="map-outline" title="Maps, staff and incidents" meta="On LNKup web" onPress={event.wordpressUrl ? () => void openExternalUrl(event.wordpressUrl!, "event operations") : undefined} />
              </ListGroup>
            </View>
          ) : null}

          {tab === "packet" ? <PacketForm eventId={id} auth={auth} /> : null}
        </>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  hero: { gap: spacing.sm },
  heroRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  title: { fontSize: 26, lineHeight: 32 },
  section: { gap: spacing.md },
  row: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
  stateField: { width: 96 },
  slot: { gap: spacing.md },
  slotHeader: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: spacing.sm },
  slotTitle: { flex: 1, fontSize: 16 },
  inventory: { gap: 6 },
  inventoryText: { fontFamily: fonts.body, fontSize: 13, color: colors.gray700 },
  inventoryStrong: { fontFamily: fonts.bodyBold, color: colors.black },
  inventoryTrack: { height: 6, borderRadius: radii.pill, backgroundColor: colors.gray100, overflow: "hidden" },
  inventoryFill: { height: 6, backgroundColor: colors.black },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  dayof: { gap: spacing.sm },
})
