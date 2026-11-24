import { useCallback, useEffect, useRef, useState, type ReactNode } from "react"
import { Image, RefreshControl, ScrollView, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { useLocalSearchParams, type Href } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { DetailHeader } from "@/components/detail-header"
import { EmptyPanel, ErrorPanel } from "@/components/panel-states"
import { ProfileMark } from "@/components/profile-mark"
import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Skeleton, SkeletonList } from "@/components/ui/skeleton"
import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { DisplayTitle, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchApplicationIntel,
  fetchOrganizerRequests,
  normalizeOrganizerRequestStatus,
  organizerRequestStatusLabel,
  organizerRequestStatusTone,
  reviewOrganizerRequest,
  type ApplicationIntel,
  type OrganizerReviewAction,
  type OrganizerVendorRequest,
} from "@/lib/api/organizer-requests"
import { ApproveIntelModal } from "@/components/organizer-vendors/approve-intel-modal"
import { VendorListsSheet } from "@/components/organizer/vendor-lists-sheet"
import { fetchApplicationReview, invalidateOrganizerMobile } from "@/lib/api/organizer-mobile"
import { addVendorNote, fetchVendorNotes, type VendorNote } from "@/lib/api/organizer-crm"
import { invalidateOrganizerMoney } from "@/lib/api/organizer-financial"
import { confirmAction, promptText, showToast } from "@/lib/feedback/feedback"
import { InfoRequestSheet } from "@/components/organizer-vendors/info-request-sheet"
import { OrganizerPassportPanel } from "@/components/organizer-vendors/passport-panel"
import { canRequestInfo, canReview, canWaitlist } from "@/components/organizer-vendors/vendor-queue"
import { StatusPill } from "@/components/ui/status-pill"
import {
  fetchOrganizerVendorProfile,
  type OrganizerVendorProfilePeek,
} from "@/lib/api/organizer-vendor-directory"
import { useAuth } from "@/lib/auth/auth-context"
import { openExternalUrl } from "@/lib/navigation/open-external-url"
import { safeBack } from "@/lib/navigation/safe-back"
import { constrainedColumnStyle } from "@/lib/layout/use-breakpoint"
import { colors, fonts, layout, radii, spacing, typography, type StatusTone, typeScale } from "@/lib/theme"

function normalizeWebsiteUrl(raw: string): string {
  if (/^https?:\/\//i.test(raw)) return raw
  return `https://${raw}`
}

function documentTone(status: string): StatusTone {
  const s = status.toLowerCase()
  if (["approved", "verified", "valid", "active"].some((k) => s.includes(k))) return "paid"
  if (["expired", "rejected", "missing"].some((k) => s.includes(k))) return "rejected"
  if (["expiring", "pending", "review", "submitted"].some((k) => s.includes(k))) return "review"
  return "neutral"
}

function historyStatus(raw: string): { label: string; tone: StatusTone } {
  const normalized = normalizeOrganizerRequestStatus(raw)
  if (normalized === "unknown") {
    return { label: raw.replace(/_/g, " ") || "Status pending", tone: "neutral" }
  }
  return {
    label: organizerRequestStatusLabel(normalized),
    tone: organizerRequestStatusTone(normalized),
  }
}

function SetupRow({ label, value }: { label: string; value?: string }) {
  if (!value) return null
  return (
    <View style={styles.setupRow}>
      <Text style={styles.setupLabel}>{label}</Text>
      <Text style={styles.setupValue}>{value}</Text>
    </View>
  )
}

function ProfileSection({
  title,
  icon,
  testID,
  children,
}: {
  title: string
  icon: AppIconName
  testID?: string
  children: ReactNode
}) {
  return (
    <Surface style={styles.card} testID={testID}>
      <View style={styles.sectionHead}>
        <AppIcon name={icon} size={18} color={colors.black} />
        <SectionTitle style={styles.sectionTitle} accessibilityRole="header">
          {title}
        </SectionTitle>
      </View>
      {children}
    </Surface>
  )
}

export default function OrganizerVendorProfileScreen() {
  const { profileId, from, applicationId } = useLocalSearchParams<{
    profileId: string
    from?: string
    applicationId?: string
  }>()
  const id = String(profileId ?? "")
  const appId = applicationId ? String(applicationId) : undefined
  // The stack can reuse this screen for another vendor; keying resets every piece of state.
  return <OrganizerVendorProfile key={`${id}:${appId ?? ""}`} id={id} appId={appId} from={from} />
}

function OrganizerVendorProfile({ id, appId, from }: { id: string; appId?: string; from?: string }) {
  const { auth } = useAuth()
  const loadSeq = useRef(0)
  const sideSeq = useRef(0)
  const [initialLoad, setInitialLoad] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [profile, setProfile] = useState<OrganizerVendorProfilePeek | null>(null)
  const [request, setRequest] = useState<OrganizerVendorRequest | null>(null)
  const [notes, setNotes] = useState<VendorNote[]>([])
  const [notesError, setNotesError] = useState<string | null>(null)
  const [addingNote, setAddingNote] = useState(false)
  const [deciding, setDeciding] = useState(false)
  const [infoOpen, setInfoOpen] = useState(false)
  const [approveOpen, setApproveOpen] = useState(false)
  const [listsOpen, setListsOpen] = useState(false)
  const [approveIntel, setApproveIntel] = useState<ApplicationIntel | null>(null)
  const [approveNote, setApproveNote] = useState<string | undefined>()
  const [approveLoading, setApproveLoading] = useState(false)
  const decideRef = useRef(false)

  const backHref: Href =
    from === "discover"
      ? ("/(organizer)/vendors?tab=discover" as Href)
      : ("/(organizer)/vendors" as Href)

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (!id) return
      const seq = ++loadSeq.current
      if (mode === "refresh") setRefreshing(true)
      else setInitialLoad(true)
      setError(null)
      try {
        const data = await fetchOrganizerVendorProfile(id, { auth, applicationId: appId })
        if (seq !== loadSeq.current) return
        setProfile(data)
      } catch (err) {
        if (seq !== loadSeq.current) return
        setError(appErrorFromWp(err))
        setProfile(null)
      } finally {
        if (seq === loadSeq.current) {
          setInitialLoad(false)
          setRefreshing(false)
        }
      }
    },
    [auth, id, appId],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  const loadSide = useCallback(async () => {
    if (!id) return
    const seq = ++sideSeq.current
    setNotesError(null)
    const [notesResult, requestsResult] = await Promise.allSettled([
      fetchVendorNotes(id, auth),
      appId ? fetchOrganizerRequests(auth, { force: true }) : Promise.resolve(null),
    ])
    if (seq !== sideSeq.current) return
    if (notesResult.status === "fulfilled") setNotes(notesResult.value)
    else setNotesError("Notes didn't load. Pull to refresh.")
    if (requestsResult.status === "fulfilled" && requestsResult.value) {
      setRequest(requestsResult.value.items.find((item) => item.id === appId) ?? null)
    }
  }, [auth, id, appId])

  useEffect(() => {
    void loadSide()
  }, [loadSide])

  function openApprove() {
    if (!request) return
    setApproveOpen(true)
    setApproveIntel(null)
    setApproveLoading(true)
    void Promise.all([
      fetchApplicationIntel(request.id, auth).catch(() => null),
      auth ? fetchApplicationReview(request.id, auth).catch(() => null) : Promise.resolve(null),
    ])
      .then(([intel, review]) => {
        setApproveIntel(intel)
        setApproveNote(review?.approveNote)
      })
      .finally(() => setApproveLoading(false))
  }

  async function decide(action: OrganizerReviewAction, confirmed = false) {
    if (!request || decideRef.current) return
    let reason: string | undefined
    if (action === "reject") {
      const typed = await promptText({
        title: `Decline ${request.vendorBusinessName}?`,
        message: "The vendor sees this reason with the decision.",
        placeholder: "e.g. We already have enough food vendors",
        confirmLabel: "Decline request",
        minLength: 3,
      })
      if (!typed) return
      reason = typed
    } else if (action === "waitlist" && !confirmed) {
      const ok = await confirmAction({
        title: `Waitlist ${request.vendorBusinessName}?`,
        message: "They stay in line for this event and can be approved later if a spot opens.",
        confirmLabel: "Waitlist",
      })
      if (!ok) return
    }
    decideRef.current = true
    setDeciding(true)
    try {
      await reviewOrganizerRequest(request, action, auth, reason)
      invalidateOrganizerMoney()
      invalidateOrganizerMobile(request.eventId)
      showToast(
        action === "approve"
          ? "Approved. We've asked them to pay to confirm."
          : action === "waitlist"
            ? `${request.vendorBusinessName} is on the waitlist.`
            : "Request declined.",
        "success",
      )
      await Promise.all([loadSide(), load("refresh")])
    } catch (err) {
      showToast(`Couldn't update this request. ${appErrorFromWp(err).message}`, "error")
    } finally {
      decideRef.current = false
      setDeciding(false)
    }
  }

  async function handleAddNote() {
    if (addingNote) return
    const content = await promptText({
      title: "Add a private note",
      message: "Only you can see this.",
      placeholder: "e.g. Great setup at the spring market",
      confirmLabel: "Save note",
      minLength: 2,
    })
    if (!content) return
    setAddingNote(true)
    try {
      await addVendorNote(id, content, auth, request?.eventId)
      showToast("Note saved.", "success")
      setNotes(await fetchVendorNotes(id, auth))
    } catch (err) {
      showToast(`Couldn't save the note. ${appErrorFromWp(err).message}`, "error")
    } finally {
      setAddingNote(false)
    }
  }

  const showLoader = initialLoad && !profile
  const setup = profile?.setupNeeds
  const hasSetup =
    setup &&
    Boolean(
      setup.setupType ||
        setup.powerNeeds ||
        setup.waterNeeds ||
        setup.tentSize ||
        setup.trailerSize ||
        setup.truckSize ||
        setup.spaceNeeds,
    )
  const gallery = profile?.gallery?.filter(Boolean) ?? []
  const reputation = profile?.reputation
  const history = profile
    ? [
        ...(profile.upcomingEvents ?? []).slice(0, 4).map((row) => ({ ...row, when: "up" as const })),
        ...(profile.pastEvents ?? []).slice(0, 4).map((row) => ({ ...row, when: "past" as const })),
      ]
    : []

  return (
    <SafeAreaView style={styles.safe} edges={["top"]}>
      <DetailHeader title="Vendor" onBack={() => safeBack(backHref)} backLabel="Back to Vendors" />

      <ScrollView
        style={styles.body}
        contentContainerStyle={[
          styles.bodyContent,
          constrainedColumnStyle(layout.contentMaxWidth),
        ]}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={() => void load("refresh")}
            tintColor={colors.black}
          />
        }
      >
        {showLoader ? (
          <View style={styles.content} accessibilityLabel="Loading vendor">
            <Skeleton height={120} radius={radii.lg} />
            <Skeleton width={72} height={72} radius={36} />
            <Skeleton width="70%" height={26} />
            <Skeleton width="45%" height={14} />
            <SkeletonList count={2} />
          </View>
        ) : null}

        {!showLoader && error && !profile ? (
          <ErrorPanel
            title={error.title}
            message={error.message}
            onAction={() => void load("refresh")}
          />
        ) : null}

        {!showLoader && !error && !profile ? (
          <EmptyPanel
            icon="person-outline"
            title="Vendor not found"
            message="This vendor profile isn’t available in Discover right now."
            actionLabel="Back to Vendors"
            onAction={() => safeBack(backHref)}
          />
        ) : null}

        {!showLoader && profile ? (
          <View style={styles.content} testID="organizer-vendor-profile">
            {profile.coverUrl ? (
              <Image
                source={{ uri: profile.coverUrl }}
                style={styles.cover}
                accessibilityLabel={`${profile.name} cover photo`}
              />
            ) : null}

            <View style={styles.hero}>
              <View style={styles.heroRow}>
                <ProfileMark name={profile.name} logoUrl={profile.logoUrl} size={72} />
                <View style={styles.heroText}>
                  {profile.verified ? <StatusBadge label="Verified" tone="approved" /> : null}
                  <DisplayTitle style={styles.title} accessibilityRole="header">
                    {profile.name}
                  </DisplayTitle>
                </View>
              </View>
              <View style={styles.metaRow}>
                <AppIcon name="pricetag-outline" size={14} color={colors.gray500} />
                <Meta>{profile.category || "Category not set"}</Meta>
              </View>
              <View style={styles.metaRow}>
                <AppIcon name="location-outline" size={14} color={colors.gray500} />
                <Meta>
                  {[profile.city, profile.state].filter(Boolean).join(", ") || "City not set"}
                </Meta>
              </View>
              <View style={styles.metaRow}>
                <AppIcon name="map-outline" size={14} color={colors.gray500} />
                <Meta>
                  Markets:{" "}
                  {profile.citiesServed && profile.citiesServed.length > 0
                    ? profile.citiesServed.join(", ")
                    : "Not listed"}
                </Meta>
              </View>
            </View>

            {profile.rating != null ||
            profile.eventsCompleted != null ||
            profile.profileStrengthScore != null ? (
              <StatGrid>
                {profile.rating != null ? (
                  <StatTile
                    icon="star-outline"
                    label={`${profile.reviewCount ?? 0} reviews`}
                    value={`${profile.rating.toFixed(1)}★`}
                  />
                ) : null}
                {profile.eventsCompleted != null ? (
                  <StatTile
                    icon="calendar-outline"
                    label="Events completed"
                    value={profile.eventsCompleted}
                  />
                ) : null}
                {profile.profileStrengthScore != null ? (
                  <StatTile
                    icon="shield-checkmark-outline"
                    label={`Profile ${profile.profileStrengthTier ?? "strength"}`}
                    value={`${profile.profileStrengthScore}%`}
                    tone="accent"
                  />
                ) : null}
              </StatGrid>
            ) : null}

            {request && canReview(request.status) ? (
              <Surface variant="urgent" style={styles.decision} testID="organizer-vendor-decision">
                <View style={styles.sectionHead}>
                  <AppIcon name="hand-left-outline" size={18} color={colors.black} />
                  <SectionTitle style={styles.sectionTitle} accessibilityRole="header">
                    Decide on {request.eventName}
                  </SectionTitle>
                </View>
                <StatusPill status={request.status} audience="organizer" />
                <View style={styles.decisionRow}>
                  <Button label="Approve" icon="checkmark" style={styles.flex} loading={deciding} onPress={openApprove} />
                  {canWaitlist(request.status) ? (
                    <Button label="Waitlist" variant="secondary" disabled={deciding} onPress={() => void decide("waitlist")} />
                  ) : null}
                  <Button label="Decline" variant="danger" disabled={deciding} onPress={() => void decide("reject")} />
                </View>
                {canRequestInfo(request.status) ? (
                  <Button label="Ask for more info" variant="ghost" icon="help-circle-outline" disabled={deciding} onPress={() => setInfoOpen(true)} />
                ) : null}
              </Surface>
            ) : null}

            {Number(id) > 0 ? <OrganizerPassportPanel profileId={Number(id)} /> : null}

            {profile.applicationMessage ? (
              <ProfileSection title="Application message" icon="chatbubble-ellipses-outline">
                <Text style={styles.bodyText}>{profile.applicationMessage}</Text>
              </ProfileSection>
            ) : null}

            {profile.readinessBlockers && profile.readinessBlockers.length > 0 ? (
              <ProfileSection
                title="Readiness blockers"
                icon="alert-circle-outline"
                testID="organizer-vendor-readiness"
              >
                {profile.readinessBlockers.slice(0, 4).map((blocker) => (
                  <View key={blocker} style={styles.blockerRow}>
                    <AppIcon name="close-circle" size={16} color={colors.amber700} />
                    <Text style={[styles.bodyText, styles.flex]}>{blocker}</Text>
                  </View>
                ))}
              </ProfileSection>
            ) : null}

            {profile.bio ? (
              <ProfileSection title="About" icon="information-circle-outline" testID="organizer-vendor-about">
                <Text style={styles.bodyText}>{profile.bio}</Text>
              </ProfileSection>
            ) : null}

            {gallery.length > 0 ? (
              <ProfileSection title="Gallery" icon="images-outline" testID="organizer-vendor-gallery">
                <ScrollView
                  horizontal
                  showsHorizontalScrollIndicator={false}
                  contentContainerStyle={styles.galleryRow}
                >
                  {gallery.slice(0, 8).map((url, index) => (
                    <Image
                      key={url}
                      source={{ uri: url }}
                      style={styles.galleryImage}
                      accessibilityLabel={`${profile.name} photo ${index + 1}`}
                    />
                  ))}
                </ScrollView>
              </ProfileSection>
            ) : null}

            {reputation ? (
              <ProfileSection title="Reputation" icon="ribbon-outline" testID="organizer-vendor-reputation">
                <Text style={styles.bodyText}>
                  {[
                    reputation.tierLabel ?? reputation.tier,
                    reputation.score != null ? `Score ${reputation.score}` : null,
                    reputation.rating != null ? `${reputation.rating.toFixed(1)}★` : null,
                    reputation.reviewCount != null ? `${reputation.reviewCount} reviews` : null,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                </Text>
                {typeof reputation.wouldBookAgainRate === "number" ? (
                  <StatusBadge
                    label={`${Math.round(reputation.wouldBookAgainRate * 100)}% would book again`}
                    tone="paid"
                  />
                ) : null}
              </ProfileSection>
            ) : null}

            {hasSetup ? (
              <ProfileSection title="Setup & ops" icon="construct-outline" testID="organizer-vendor-setup">
                <SetupRow label="Setup" value={setup?.setupType} />
                <SetupRow label="Power" value={setup?.powerNeeds} />
                <SetupRow label="Water" value={setup?.waterNeeds} />
                <SetupRow label="Tent" value={setup?.tentSize} />
                <SetupRow label="Trailer" value={setup?.trailerSize} />
                <SetupRow label="Truck" value={setup?.truckSize} />
                <SetupRow label="Space" value={setup?.spaceNeeds} />
              </ProfileSection>
            ) : null}

            {profile.documents && profile.documents.length > 0 ? (
              <ProfileSection title="Documents" icon="document-text-outline" testID="organizer-vendor-documents">
                <View style={styles.chipRow}>
                  {profile.documents.map((doc) => (
                    <StatusBadge
                      key={`${doc.type}-${doc.status}`}
                      label={`${doc.type} · ${doc.status}`}
                      tone={documentTone(doc.status)}
                    />
                  ))}
                </View>
              </ProfileSection>
            ) : null}

            {history.length > 0 ? (
              <ProfileSection title="With you" icon="time-outline" testID="organizer-vendor-history">
                {history.map((row) => {
                  const status = historyStatus(row.status)
                  return (
                    <View
                      key={`${row.when}-${row.applicationId ?? row.eventTitle}`}
                      style={styles.historyRow}
                    >
                      <View style={styles.flex}>
                        <Text style={styles.historyTitle}>{row.eventTitle}</Text>
                        <Meta>
                          {row.when === "up" ? "Upcoming" : "Past"}
                          {row.date ? ` · ${row.date}` : ""}
                        </Meta>
                      </View>
                      <StatusBadge label={status.label} tone={status.tone} />
                    </View>
                  )
                })}
              </ProfileSection>
            ) : null}

            <ProfileSection title="Private notes" icon="lock-closed-outline" testID="organizer-vendor-notes">
              <Meta>Only you can see these. Vendors never do.</Meta>
              {notesError ? <Meta style={styles.noteError}>{notesError}</Meta> : null}
              {notes.length === 0 && !notesError ? <Meta>No notes yet.</Meta> : null}
              {notes.map((note) => (
                <View key={note.id} style={styles.noteRow}>
                  <Text style={styles.bodyText}>{note.content}</Text>
                  <Meta>{[note.createdAt, note.eventTitle].filter(Boolean).join(" · ")}</Meta>
                </View>
              ))}
              <Button label="Add note" icon="add" variant="secondary" size="sm" loading={addingNote} onPress={() => void handleAddNote()} />
              <Button label="Save to a list" icon="bookmark-outline" variant="ghost" size="sm" onPress={() => setListsOpen(true)} testID="vendor-save-to-list" />
            </ProfileSection>

            {profile.products ? (
              <ProfileSection title="Offers" icon="basket-outline">
                <Text style={styles.bodyText}>{profile.products}</Text>
              </ProfileSection>
            ) : null}

            {profile.email || profile.phone || profile.website || profile.instagram ? (
              <View testID="organizer-vendor-contact">
                <ListGroup title="Contact">
                  {profile.email ? (
                    <ListRow
                      icon="mail-outline"
                      title="Email"
                      meta={profile.email}
                      onPress={() => void openExternalUrl(`mailto:${profile.email}`, "email")}
                    />
                  ) : null}
                  {profile.phone ? (
                    <ListRow
                      icon="call-outline"
                      title="Phone"
                      meta={profile.phone}
                      onPress={() => void openExternalUrl(`tel:${profile.phone}`, "phone")}
                    />
                  ) : null}
                  {profile.website ? (
                    <ListRow
                      icon="globe-outline"
                      title="Website"
                      meta={profile.website}
                      onPress={() =>
                        void openExternalUrl(normalizeWebsiteUrl(profile.website!), "website")
                      }
                    />
                  ) : null}
                  {profile.instagram ? (
                    <ListRow
                      icon="logo-instagram"
                      title="Instagram"
                      meta={`@${profile.instagram.replace(/^@/, "")}`}
                      onPress={() =>
                        void openExternalUrl(
                          `https://instagram.com/${encodeURIComponent(profile.instagram!.replace(/^@/, ""))}`,
                          "Instagram",
                        )
                      }
                    />
                  ) : null}
                </ListGroup>
              </View>
            ) : null}

            {profile.wordpressUrl ? (
              <Button
                label="Open in LNKup CRM"
                icon="open-outline"
                onPress={() => void openExternalUrl(profile.wordpressUrl!, "crm")}
                testID="organizer-vendor-crm-link"
              />
            ) : null}
          </View>
        ) : null}
      </ScrollView>
      {profile ? (
        <VendorListsSheet visible={listsOpen} onClose={() => setListsOpen(false)} profileId={String(id)} vendorName={profile.name} auth={auth} />
      ) : null}
      <ApproveIntelModal
        item={approveOpen ? request : null}
        intel={approveIntel}
        loading={approveLoading}
        acting={deciding}
        note={approveNote}
        onCancel={() => setApproveOpen(false)}
        onApprove={() => {
          setApproveOpen(false)
          void decide("approve", true)
        }}
      />
      <InfoRequestSheet
        item={infoOpen ? request : null}
        auth={auth}
        onClose={() => setInfoOpen(false)}
        onSent={() => {
          setInfoOpen(false)
          showToast("Question sent. You'll be notified when they reply.", "success")
          void loadSide()
        }}
      />
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  decision: { gap: spacing.sm },
  decisionRow: { flexDirection: "row", gap: spacing.sm },
  noteRow: { gap: 2, paddingVertical: spacing.xs, borderTopWidth: 1, borderTopColor: colors.gray100 },
  noteError: { color: colors.red600 },
  safe: {
    flex: 1,
    backgroundColor: colors.white,
  },
  body: { flex: 1 },
  bodyContent: {
    padding: spacing.lg,
    paddingBottom: spacing.xxl,
  },
  content: {
    gap: spacing.lg,
  },
  flex: {
    flex: 1,
  },
  cover: {
    width: "100%",
    height: 120,
    borderRadius: radii.lg,
    backgroundColor: colors.gray100,
  },
  hero: {
    gap: spacing.sm,
  },
  heroRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
  },
  heroText: {
    flex: 1,
    gap: spacing.xs,
  },
  title: {
    fontSize: typeScale.title,
    lineHeight: 30,
  },
  metaRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
  },
  card: {
    gap: spacing.sm,
  },
  sectionHead: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
  },
  sectionTitle: {
    fontSize: typeScale.bodyLarge,
  },
  bodyText: {
    ...typography.body,
  },
  blockerRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: spacing.sm,
  },
  galleryRow: {
    gap: spacing.sm,
  },
  galleryImage: {
    width: 96,
    height: 96,
    borderRadius: radii.md,
    backgroundColor: colors.gray100,
  },
  setupRow: {
    flexDirection: "row",
    justifyContent: "space-between",
    gap: spacing.md,
    paddingVertical: 2,
  },
  setupLabel: {
    ...typography.meta,
    fontSize: typeScale.body,
  },
  setupValue: {
    flex: 1,
    fontSize: typeScale.body,
    fontFamily: fonts.bodySemi,
    color: colors.black,
    textAlign: "right",
  },
  chipRow: {
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.sm,
  },
  historyRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.sm,
    paddingVertical: spacing.xs,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.gray200,
  },
  historyTitle: {
    ...typography.label,
  },
})
