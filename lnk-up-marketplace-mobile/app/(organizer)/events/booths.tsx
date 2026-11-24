import { useCallback, useMemo, useState } from "react"
import { Image, ScrollView, StyleSheet, Switch, View } from "react-native"
import { router, useFocusEffect, useLocalSearchParams, type Href } from "expo-router"

import { ProgressBar } from "@/components/organizer/progress-bar"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { AppText as Text } from "@/components/ui/app-text"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { SegmentedControl } from "@/components/ui/chip"
import { FormField } from "@/components/ui/form-field"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { assignBooth, fetchEventBooths, type EventBooth } from "@/lib/api/organizer-checkin"
import {
  createBooth,
  fetchEventMap,
  fetchLoadin,
  fetchPacketOverview,
  fetchParking,
  fetchPassDetails,
  mapBuilderUrl,
  type EventMap,
  type LoadinWindow,
  type PacketOverview,
  type ParkingSpot,
  type PassDetails,
} from "@/lib/api/organizer-event-tools"
import { fetchCheckinSummary, invalidateOrganizerMobile, type CheckinRosterRow } from "@/lib/api/organizer-mobile"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { formatDateTime } from "@/lib/format/date"
import { useKeyedSingleFlight, useSingleFlight } from "@/lib/hooks/use-single-flight"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { colors, radii, spacing, typography } from "@/lib/theme"

type Tab = "booths" | "map" | "logistics" | "packet"
const TABS: readonly Tab[] = ["booths", "map", "logistics", "packet"]

const PASS_LABELS: Array<[keyof PassDetails, string]> = [
  ["venueAddress", "Venue address"],
  ["loadInTime", "Load-in time"],
  ["loadOutTime", "Load-out time"],
  ["vendorGate", "Vendor gate"],
  ["loadinEntrance", "Load-in entrance"],
  ["parkingInfo", "Parking"],
  ["vendorRules", "Rules"],
]

/** Booths, the event map (view only), load-in and parking, and the vendor packet overview. */
export default function OrganizerBoothsScreen() {
  const { eventId, tab: tabParam } = useLocalSearchParams<{ eventId: string; tab?: string }>()
  const id = String(eventId ?? "")
  const { auth } = useAuth()
  const [tab, setTab] = useState<Tab>(() => TABS.find((t) => t === tabParam) ?? "booths")
  const [booths, setBooths] = useState<EventBooth[] | null>(null)
  const [vendors, setVendors] = useState<CheckinRosterRow[]>([])
  const [map, setMap] = useState<EventMap | null>(null)
  const [loadin, setLoadin] = useState<LoadinWindow[] | null>(null)
  const [parking, setParking] = useState<ParkingSpot[] | null>(null)
  const [packet, setPacket] = useState<PacketOverview | null>(null)
  const [pass, setPass] = useState<PassDetails | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [loading, setLoading] = useState(true)
  const [assignFor, setAssignFor] = useState<CheckinRosterRow | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [newNumber, setNewNumber] = useState("")
  const [newSize, setNewSize] = useState("")
  const [newPower, setNewPower] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)

  const load = useCallback(async () => {
    if (!auth || !id) return
    setError(null)
    try {
      const [b, summary, m, l, p, pk, pd] = await Promise.all([
        fetchEventBooths(auth, id),
        fetchCheckinSummary(id, auth, true).catch(() => null),
        fetchEventMap(id, auth).catch(() => null),
        fetchLoadin(id, auth).catch(() => []),
        fetchParking(id, auth).catch(() => []),
        fetchPacketOverview(id, auth).catch(() => null),
        fetchPassDetails(id, auth).catch(() => null),
      ])
      setBooths(b)
      setVendors((summary?.rows ?? []).filter((row) => row.reason !== "invalid_booking"))
      setMap(m)
      setLoadin(l)
      setParking(p)
      setPacket(pk)
      setPass(pd)
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  const unplaced = useMemo(() => vendors.filter((v) => !v.booth && v.reason !== "payment_pending"), [vendors])
  const openBooths = useMemo(() => (booths ?? []).filter((b) => !b.applicationId), [booths])

  const [assign, isAssigning] = useKeyedSingleFlight(async (boothId: string) => {
    if (!auth || !assignFor) return
    const booth = booths?.find((b) => b.id === boothId)
    try {
      await assignBooth(auth, id, boothId, assignFor.applicationId)
      showToast(`${assignFor.vendorName} is now at booth ${booth?.number ?? ""}.`, "success")
      setAssignFor(null)
      invalidateOrganizerMobile(id)
      await load()
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    }
  })

  const [create, creating] = useSingleFlight(async () => {
    if (!auth) return
    setCreateError(null)
    if (!newNumber.trim()) {
      setCreateError("Enter a booth number.")
      return
    }
    try {
      await createBooth(id, { number: newNumber, size: newSize.trim(), hasPower: newPower }, auth)
      showToast(`Booth ${newNumber.trim()} added.`, "success")
      setCreateOpen(false)
      setNewNumber("")
      setNewSize("")
      setNewPower(false)
      await load()
    } catch (err) {
      setCreateError(appErrorFromWp(err).message)
    }
  })

  return (
    <Screen title="Booths & logistics" refreshing={false} onRefresh={() => void load()} header={<BackLink label="Event" fallbackHref={`/(organizer)/events/${id}?tab=operations` as Href} />}>
      {loading && !booths ? <LoadingPanel label="Loading booths…" variant="skeleton" count={3} /> : null}
      {!loading && error && !booths ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {booths ? (
        <View style={styles.stack}>
          <SegmentedControl
            options={[
              { value: "booths", label: "Booths", count: unplaced.length || undefined },
              { value: "map", label: "Map" },
              { value: "logistics", label: "Logistics" },
              { value: "packet", label: "Packet" },
            ]}
            value={tab}
            onChange={setTab}
          />

          {tab === "booths" ? (
            <>
              <Meta>
                {booths.length - openBooths.length} of {booths.length} booths assigned · {unplaced.length} confirmed vendor{unplaced.length === 1 ? "" : "s"} without a booth
              </Meta>
              {unplaced.length > 0 ? (
                <ListGroup title="Needs a booth">
                  {unplaced.map((vendor) => (
                    <ListRow
                      key={vendor.applicationId}
                      icon="storefront-outline"
                      title={vendor.vendorName}
                      meta={vendor.category || undefined}
                      onPress={() => setAssignFor(vendor)}
                      accessibilityLabel={`Assign booth for ${vendor.vendorName}`}
                    />
                  ))}
                </ListGroup>
              ) : null}
              {booths.length === 0 ? (
                <EmptyPanel icon="grid-outline" title="No booths yet" message="Add booths so you can place confirmed vendors." actionLabel="Add booth" onAction={() => setCreateOpen(true)} />
              ) : (
                <ListGroup title="All booths">
                  {booths.map((booth) => (
                    <ListRow
                      key={booth.id}
                      icon="grid-outline"
                      title={`Booth ${booth.number}`}
                      meta={[booth.typeLabel, booth.vendorName].filter(Boolean).join(" · ") || undefined}
                      rightSlot={<StatusBadge label={booth.applicationId ? "Assigned" : "Open"} tone={booth.applicationId ? "approved" : "paid"} />}
                    />
                  ))}
                </ListGroup>
              )}
              {booths.length > 0 ? <Button label="Add booth" icon="add" variant="secondary" onPress={() => setCreateOpen(true)} testID="booth-add" /> : null}
            </>
          ) : null}

          {tab === "map" ? (
            map?.imageUrl ? (
              <View style={styles.stack}>
                <ScrollView style={styles.mapFrame} maximumZoomScale={4} minimumZoomScale={1} contentContainerStyle={styles.mapContent} centerContent>
                  <Image source={{ uri: map.imageUrl }} style={styles.mapImage} resizeMode="contain" accessibilityLabel="Event map" />
                </ScrollView>
                <Meta>Pinch to zoom. The booth list above is the accessible version of this map.</Meta>
                <Button label="Edit map on LNKup web" icon="open-outline" variant="ghost" onPress={() => void openExternalUrl(mapBuilderUrl(id), "the map builder")} />
              </View>
            ) : (
              <EmptyPanel
                icon="map-outline"
                title={map && map.elementCount > 0 ? "Map layout is on LNKup web" : "No map yet"}
                message={map && map.elementCount > 0 ? "This map is drawn in the web map builder. Open it to view and edit the layout." : "Upload a map image or draw a layout in the web map builder. Booth assignments work here without one."}
                actionLabel="Open map builder"
                onAction={() => void openExternalUrl(mapBuilderUrl(id), "the map builder")}
              />
            )
          ) : null}

          {tab === "logistics" ? (
            <>
              <ListGroup title="Load-in windows">
                {(loadin ?? []).length === 0 ? (
                  <ListRow icon="time-outline" title="No load-in windows yet" meta="Vendors see their arrival time once you add one." />
                ) : (
                  (loadin ?? []).map((w) => (
                    <ListRow
                      key={w.id}
                      icon="time-outline"
                      title={w.label || formatDateTime(w.startAt) || "Window"}
                      meta={[w.gate, `${w.assigned} of ${w.maxSlots} assigned`].filter(Boolean).join(" · ")}
                    />
                  ))
                )}
              </ListGroup>
              <ListGroup title="Parking">
                {(parking ?? []).length === 0 ? (
                  <ListRow icon="car-outline" title="No parking set up" />
                ) : (
                  (parking ?? []).map((p) => (
                    <ListRow
                      key={p.id}
                      icon="car-outline"
                      title={p.label}
                      meta={[p.gate, p.vehicleType, p.assigned ? "Assigned" : "Open"].filter(Boolean).join(" · ")}
                    />
                  ))
                )}
              </ListGroup>
            </>
          ) : null}

          {tab === "packet" ? (
            packet ? (
              <>
                <Surface style={styles.card}>
                  <View style={styles.packetHead}>
                    <Text style={styles.label}>Vendor packet</Text>
                    <StatusBadge label={packet.stageLabel || packet.stage} tone={packet.stage === "draft" ? "neutral" : "paid"} />
                  </View>
                  {packet.stageHint ? <Meta>{packet.stageHint}</Meta> : null}
                  <ProgressBar percent={packet.total ? (100 * packet.complete) / packet.total : 0} label={`${packet.complete} of ${packet.total} packet sections filled in`} />
                  <Meta>
                    {packet.complete} of {packet.total} sections filled in · {packet.unlocked} paid vendor{packet.unlocked === 1 ? "" : "s"} can open it
                  </Meta>
                </Surface>
                <ListGroup title="Sections">
                  {packet.sections.map((s) => (
                    <ListRow key={s.key} icon={s.complete ? "checkmark-circle" : "ellipse-outline"} iconTone={s.complete ? "accent" : "default"} title={s.label} />
                  ))}
                </ListGroup>
                {pass ? (
                  <ListGroup title="What vendors see">
                    {PASS_LABELS.map(([key, label]) => (
                      <ListRow key={key} title={label} meta={pass[key] || "Not added yet"} />
                    ))}
                  </ListGroup>
                ) : null}
                <Button label="Edit packet" icon="create-outline" onPress={() => router.push(`/(organizer)/packet?eventId=${id}` as Href)} />
              </>
            ) : (
              <EmptyPanel icon="document-text-outline" title="Packet not available" message="Try again in a moment." actionLabel="Retry" onAction={() => void load()} />
            )
          ) : null}
        </View>
      ) : null}

      <BottomSheet visible={assignFor != null} onClose={() => setAssignFor(null)} title={assignFor ? `Booth for ${assignFor.vendorName}` : "Booth"}>
        {openBooths.length === 0 ? (
          <EmptyPanel icon="grid-outline" title="No open booths" message="Add a booth first." actionLabel="Add booth" onAction={() => { setAssignFor(null); setCreateOpen(true) }} />
        ) : (
          <ListGroup>
            {openBooths.map((booth) => (
              <ListRow
                key={booth.id}
                icon="grid-outline"
                title={`Booth ${booth.number}`}
                meta={booth.typeLabel || undefined}
                rightSlot={<StatusBadge label={isAssigning(booth.id) ? "Assigning…" : "Open"} tone="paid" />}
                onPress={isAssigning(booth.id) ? undefined : () => void assign(booth.id)}
              />
            ))}
          </ListGroup>
        )}
      </BottomSheet>

      <BottomSheet
        visible={createOpen}
        onClose={() => setCreateOpen(false)}
        title="Add booth"
        footer={<Button label="Add booth" loadingLabel="Adding…" fullWidth loading={creating} onPress={() => void create()} testID="booth-create" />}
      >
        <View style={styles.stack}>
          <FormField label="Booth number" value={newNumber} onChangeText={setNewNumber} placeholder="e.g. A12" autoCapitalize="characters" error={createError ?? undefined} />
          <FormField label="Size (optional)" value={newSize} onChangeText={setNewSize} placeholder="e.g. 10x10" />
          <View style={styles.switchRow}>
            <Text style={styles.label}>Has power</Text>
            <Switch value={newPower} onValueChange={setNewPower} accessibilityLabel="Booth has power" />
          </View>
        </View>
      </BottomSheet>
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  card: { gap: spacing.sm },
  packetHead: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  label: { ...typography.label },
  switchRow: { flexDirection: "row", alignItems: "center", justifyContent: "space-between" },
  mapFrame: { height: 360, borderRadius: radii.lg, backgroundColor: colors.gray100 },
  mapContent: { flexGrow: 1, alignItems: "center", justifyContent: "center" },
  mapImage: { width: "100%", height: 360 },
})
