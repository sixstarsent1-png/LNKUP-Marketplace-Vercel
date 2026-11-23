import { useCallback, useEffect, useRef, useState, type ReactNode } from "react"
import { Pressable, ScrollView, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"
import { Redirect, router, useLocalSearchParams, type Href } from "expo-router"
import { SafeAreaView } from "react-native-safe-area-context"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { EventImage } from "@/components/ui/event-image"
import { StatusPill } from "@/components/ui/status-pill"
import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { fetchVendorRequests, type VendorRequest } from "@/lib/api/vendor-requests"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  fetchOpportunityDetail,
  type MarketplaceOpportunity,
} from "@/lib/api/marketplace"
import { fetchApplyContext, type ApplyContext } from "@/lib/api/apply"
import { fetchVendorFavorites, setVendorFavorite } from "@/lib/api/vendor-favorites"
import { useAuth } from "@/lib/auth/auth-context"
import { showToast } from "@/lib/feedback/feedback"
import { constrainedColumnStyle, useBreakpoint } from "@/lib/layout/use-breakpoint"
import { safeBack } from "@/lib/navigation/safe-back"
import { colors, fonts, layout, radii, spacing, typeScale } from "@/lib/theme"

const PREVIEW_CHARS = 280

function FactRow({
  icon,
  label,
  value,
  trailing,
}: {
  icon: AppIconName
  label: string
  value: string
  trailing?: ReactNode
}) {
  return (
    <View style={styles.factRow}>
      <View style={styles.factIcon}>
        <AppIcon name={icon} size={18} color={colors.gray700} />
      </View>
      <View style={styles.factCopy}>
        <Text style={styles.factLabel}>{label}</Text>
        <Text style={styles.factValue}>{value}</Text>
      </View>
      {trailing}
    </View>
  )
}

export default function OpportunityDetailScreen() {
  const { opportunityId } = useLocalSearchParams<{ opportunityId: string }>()
  const { auth, isAuthenticated } = useAuth()
  const { primaryButtonPaddingVertical } = useBreakpoint()
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [detail, setDetail] = useState<MarketplaceOpportunity | null>(null)
  const [applyCtx, setApplyCtx] = useState<ApplyContext | null>(null)
  const [applyCtxFailed, setApplyCtxFailed] = useState(false)
  const [expanded, setExpanded] = useState(false)
  const [saved, setSaved] = useState(false)
  const [existing, setExisting] = useState<VendorRequest | null>(null)
  const savingRef = useRef(false)

  const id = Number(opportunityId)

  const load = useCallback(async () => {
    if (!id) {
      setError({
        code: "validation_error",
        title: "Invalid opportunity",
        message: "Missing opportunity id.",
      })
      setLoading(false)
      return
    }
    setLoading(true)
    setError(null)
    setApplyCtxFailed(false)
    try {
      const [opp, ctxResult, favs, mine] = await Promise.all([
        fetchOpportunityDetail(id, auth),
        fetchApplyContext(id, auth)
          .then((ctx) => ({ ok: true as const, ctx }))
          .catch((err) => ({ ok: false as const, err })),
        fetchVendorFavorites(auth).catch(() => ({ opportunityIds: [] as number[], items: [] })),
        fetchVendorRequests(auth).catch(() => ({ items: [] as VendorRequest[], total: 0 })),
      ])
      setExisting(mine.items.find((item) => item.opportunityId === String(id)) ?? null)
      if (!opp) {
        setError({
          code: "not_found",
          title: "Not found",
          message: "We couldn't find this opportunity.",
        })
        setDetail(null)
        return
      }
      setDetail(opp)
      if (ctxResult.ok) {
        setApplyCtx(ctxResult.ctx)
      } else {
        setApplyCtx(null)
        setApplyCtxFailed(true)
      }
      setSaved(favs.opportunityIds.includes(id))
    } catch (err) {
      setError(appErrorFromWp(err))
    } finally {
      setLoading(false)
    }
  }, [auth, id])

  useEffect(() => {
    void load()
  }, [load])

  if (!isAuthenticated) {
    return <Redirect href={{ pathname: "/login", params: { returnTo: `/opportunity/${id}` } }} />
  }

  async function handleToggleSave() {
    if (!id || savingRef.current) return
    savingRef.current = true
    const was = saved
    setSaved(!was)
    try {
      setSaved(await setVendorFavorite(id, !was, auth))
    } catch (err) {
      setSaved(was)
      showToast(`Couldn't update saved events. ${appErrorFromWp(err).message}`, "error")
    } finally {
      savingRef.current = false
    }
  }

  const about = detail?.description || detail?.excerpt || applyCtx?.excerpt || ""
  const showExpand = about.length > PREVIEW_CHARS
  const aboutText =
    !expanded && showExpand ? `${about.slice(0, PREVIEW_CHARS).trim()}…` : about

  const fee =
    detail?.vendorFee == null && applyCtx?.vendorFee == null
      ? "Fee unavailable"
      : (detail?.vendorFee ?? applyCtx?.vendorFee ?? 0) > 0
        ? `$${detail?.vendorFee ?? applyCtx?.vendorFee}`
        : "Free"

  const canApply = applyCtx ? applyCtx.canApply && !applyCtx.buttonDisabled : false
  const location = detail ? [detail.city, detail.state].filter(Boolean).join(", ") : ""
  const heroImage = detail?.gallery?.find((m) => m.type === "image")
  const extraGallery = detail?.gallery?.filter((m) => m !== heroImage).slice(0, 8) ?? []
  const category = detail?.categories[0]
  const spotsTotal = detail?.spotsTotal ?? 0
  const spotsRemaining = detail?.spotsRemaining ?? 0
  const filledRatio =
    spotsTotal > 0 ? Math.min(1, Math.max(0, (spotsTotal - spotsRemaining) / spotsTotal)) : 0
  const attendance =
    detail && detail.expectedAttendance > 0
      ? detail.expectedAttendance.toLocaleString("en-US")
      : "—"

  const logistics: Array<{ icon: AppIconName; label: string }> = []
  if (detail?.indoor) logistics.push({ icon: "home-outline", label: "Indoor" })
  if (detail?.outdoor) logistics.push({ icon: "sunny-outline", label: "Outdoor" })
  if (detail?.powerAvailable) logistics.push({ icon: "flash-outline", label: "Power" })
  if (detail?.loadInTime) logistics.push({ icon: "time-outline", label: `Load-in ${detail.loadInTime}` })

  return (
    <SafeAreaView style={styles.safe} edges={["top", "bottom"]}>
      <View style={styles.header}>
        <Pressable
          onPress={() => safeBack("/(vendor)/find")}
          style={styles.back}
          accessibilityRole="button"
          accessibilityLabel="Back"
          hitSlop={8}
        >
          <AppIcon name="chevron-back" size={22} color={colors.black} />
        </Pressable>
        <Text style={styles.headerTitle}>Event details</Text>
        <Pressable
          onPress={() => void handleToggleSave()}
          style={styles.saveBtn}
          accessibilityRole="button"
          accessibilityLabel={`${saved ? "Unsave" : "Save"}${detail?.title ? ` ${detail.title}` : ""}`}
          hitSlop={8}
        >
          <AppIcon
            name={saved ? "heart" : "heart-outline"}
            size={22}
            color={saved ? colors.red600 : colors.gray400}
          />
        </Pressable>
      </View>

      <ScrollView
        contentContainerStyle={[
          styles.body,
          constrainedColumnStyle(layout.contentMaxWidth),
        ]}
        keyboardShouldPersistTaps="handled"
      >
        {loading ? <LoadingPanel label="Loading event…" variant="skeleton" withMedia count={1} /> : null}

        {!loading && error ? (
          <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} />
        ) : null}

        {!loading && detail ? (
          <View style={[styles.content, constrainedColumnStyle(layout.contentMaxWidth)]}>
            <View style={styles.hero}>
              <EventImage
                uri={heroImage?.url || heroImage?.thumb || detail.imageUrl}
                title={detail.title}
                height={260}
                fit="contain"
                accessibilityLabel={`${detail.title} event photo`}
              />
              <View style={styles.heroChips}>
                {category ? (
                  <View style={styles.heroChip}>
                    <Text style={styles.heroChipText}>{category}</Text>
                  </View>
                ) : null}
                {detail.organizerVerified ? (
                  <View style={[styles.heroChip, styles.heroChipLime]}>
                    <Text style={styles.heroChipTextDark}>Verified</Text>
                  </View>
                ) : null}
                {detail.trending ? (
                  <View style={[styles.heroChip, styles.heroChipLime]}>
                    <Text style={styles.heroChipTextDark}>Trending</Text>
                  </View>
                ) : null}
              </View>
            </View>

            <Text style={styles.brand}>LNKup</Text>
            <Text style={styles.title}>{detail.title}</Text>

            <View style={styles.facts}>
              {detail.eventDate ? (
                <FactRow icon="calendar-outline" label="Date" value={detail.eventDate} />
              ) : null}
              {location ? (
                <FactRow icon="location-outline" label="Location" value={location} />
              ) : null}
              {detail.venue ? (
                <FactRow icon="storefront-outline" label="Venue" value={detail.venue} />
              ) : null}
              {detail.organizerName ? (
                <FactRow
                  icon="person-outline"
                  label="Organizer"
                  value={detail.organizerName}
                  trailing={
                    detail.organizerVerified ? (
                      <View style={styles.verifiedPill}>
                        <AppIcon name="checkmark-circle" size={14} color={colors.green700} />
                        <Text style={styles.verifiedText}>Verified</Text>
                      </View>
                    ) : null
                  }
                />
              ) : null}
            </View>

            {extraGallery.length > 0 ? (
              <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                contentContainerStyle={styles.galleryRow}
                testID="opportunity-gallery"
              >
                {extraGallery.map((media) => (
                  <View key={`${media.id}-${media.url}`} style={styles.galleryItem}>
                    {media.type === "video" ? (
                      <View style={styles.galleryVideo}>
                        <Text style={styles.galleryVideoText}>Video</Text>
                      </View>
                    ) : (
                      <EventImage uri={media.thumb || media.url} height={96} radius={radii.md} style={styles.galleryImage} />
                    )}
                  </View>
                ))}
              </ScrollView>
            ) : null}

            <View style={styles.statsRow}>
              <View style={styles.stat}>
                <AppIcon name="pricetag-outline" size={16} color={colors.gray500} />
                <Text style={styles.statLabel}>Vendor fee</Text>
                <Text style={styles.statValue}>{fee}</Text>
              </View>
              <View style={styles.stat}>
                <AppIcon name="people-outline" size={16} color={colors.gray500} />
                <Text style={styles.statLabel}>Spots left</Text>
                <Text style={styles.statValue}>
                  {spotsRemaining}/{spotsTotal || "—"}
                </Text>
                {spotsTotal > 0 ? (
                  <View style={styles.capacityTrack}>
                    <View style={[styles.capacityFill, { width: `${filledRatio * 100}%` }]} />
                  </View>
                ) : null}
              </View>
              <View style={styles.stat}>
                <AppIcon name="walk-outline" size={16} color={colors.gray500} />
                <Text style={styles.statLabel}>Attendance</Text>
                <Text style={styles.statValue}>{attendance}</Text>
              </View>
            </View>

            {detail.applicationDeadline ? (
              <View style={styles.deadlineChip}>
                <AppIcon name="alarm-outline" size={14} color={colors.amber700} />
                <Text style={styles.deadlineText}>{detail.applicationDeadline}</Text>
              </View>
            ) : null}

            <View style={styles.aboutCard}>
              <Text style={styles.aboutTitle}>About this event</Text>
              {aboutText ? (
                <>
                  <Text style={styles.aboutBody}>{aboutText}</Text>
                  {showExpand ? (
                    <Pressable onPress={() => setExpanded((v) => !v)} hitSlop={8}>
                      <Text style={styles.showMore}>
                        {expanded ? "Show less" : "Show more"}
                      </Text>
                    </Pressable>
                  ) : null}
                </>
              ) : (
                <Text style={styles.aboutEmpty}>No description provided.</Text>
              )}
            </View>

            {logistics.length > 0 ? (
              <View style={styles.logistics}>
                <Text style={styles.aboutTitle}>Logistics</Text>
                <View style={styles.logisticsGrid}>
                  {logistics.map((item) => (
                    <View key={item.label} style={styles.logisticsTile}>
                      <AppIcon name={item.icon} size={18} color={colors.black} />
                      <Text style={styles.logisticsItem}>{item.label}</Text>
                    </View>
                  ))}
                </View>
              </View>
            ) : null}

            {detail.categories.length > 1 ? (
              <View style={styles.chips}>
                {detail.categories.slice(1).map((cat) => (
                  <Text key={cat} style={styles.chip}>
                    {cat}
                  </Text>
                ))}
              </View>
            ) : null}
          </View>
        ) : null}
      </ScrollView>

      {!loading && detail ? (
        <View style={styles.footer}>
          <View style={constrainedColumnStyle(layout.contentMaxWidth)}>
            {existing ? (
              <View style={styles.appliedRow} testID="opportunity-already-applied">
                <View style={styles.appliedCopy}>
                  <Text style={styles.appliedTitle}>You applied</Text>
                  <StatusPill status={existing.status} audience="vendor" />
                </View>
                <Button
                  label="View booking"
                  icon="ticket-outline"
                  onPress={() => router.push(`/booking/${existing.id}` as Href)}
                />
              </View>
            ) : applyCtxFailed ? (
              <View style={styles.blocked}>
                <Text style={styles.blockedText}>
                  We couldn't verify if applications are open. Check your connection and try again.
                </Text>
                <Pressable style={styles.secondary} onPress={() => void load()}>
                  <Text style={styles.secondaryText}>Retry</Text>
                </Pressable>
              </View>
            ) : canApply ? (
              <Pressable
                style={[styles.primary, { paddingVertical: primaryButtonPaddingVertical }]}
                onPress={() => router.push(`/apply/${id}` as Href)}
              >
                <Text style={styles.primaryText}>
                  {applyCtx?.buttonLabel ?? "Apply"}
                </Text>
              </Pressable>
            ) : (
              <View style={styles.blocked}>
                <Text style={styles.blockedText}>
                  {applyCtx?.blockReason ?? "Applications are not open for this opportunity."}
                </Text>
                <Pressable
                  style={styles.secondary}
                  onPress={() => safeBack("/(vendor)/find")}
                >
                  <Text style={styles.secondaryText}>Back to Explore</Text>
                </Pressable>
              </View>
            )}
          </View>
        </View>
      ) : null}
    </SafeAreaView>
  )
}

const styles = StyleSheet.create({
  safe: { flex: 1, backgroundColor: colors.white },
  header: {
    flexDirection: "row",
    alignItems: "center",
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray200,
  },
  back: { minWidth: 56, paddingVertical: 4 },
  headerTitle: {
    flex: 1,
    textAlign: "center",
    fontWeight: "700",
    fontSize: typeScale.bodyLarge,
    color: colors.black,
  },
  saveBtn: { minWidth: 56, alignItems: "flex-end", paddingVertical: 4 },
  body: { paddingBottom: 120 },
  content: { gap: spacing.sm, paddingHorizontal: spacing.lg, paddingTop: spacing.md },
  hero: {
    marginHorizontal: -spacing.lg,
    marginTop: -spacing.md,
    height: 200,
    backgroundColor: colors.limeMuted,
    overflow: "hidden",
  },
  heroImage: { width: "100%", height: "100%" },
  heroFallback: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    gap: spacing.xs,
  },
  heroLetter: {
    fontFamily: fonts.display,
    fontSize: typeScale.jumbo,
    color: colors.black,
  },
  heroFallbackLabel: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
  heroChips: {
    position: "absolute",
    left: spacing.lg,
    bottom: spacing.md,
    flexDirection: "row",
    flexWrap: "wrap",
    gap: spacing.xs,
  },
  heroChip: {
    backgroundColor: "rgba(10,10,10,0.72)",
    borderRadius: radii.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
  },
  heroChipLime: {
    backgroundColor: colors.lime,
  },
  heroChipText: {
    color: colors.white,
    fontSize: typeScale.micro,
    fontWeight: "700",
  },
  heroChipTextDark: {
    color: colors.black,
    fontSize: typeScale.micro,
    fontWeight: "700",
  },
  brand: {
    fontSize: typeScale.caption,
    fontWeight: "800",
    letterSpacing: 1.2,
    textTransform: "uppercase",
    color: colors.gray500,
    marginTop: spacing.sm,
  },
  title: { fontSize: typeScale.display, fontWeight: "800", color: colors.black },
  facts: {
    marginTop: spacing.sm,
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    overflow: "hidden",
  },
  factRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.md,
    borderBottomWidth: 1,
    borderBottomColor: colors.gray100,
  },
  factIcon: {
    width: 32,
    height: 32,
    borderRadius: radii.sm,
    backgroundColor: colors.gray50,
    alignItems: "center",
    justifyContent: "center",
  },
  factCopy: { flex: 1, gap: 2 },
  factLabel: {
    fontSize: typeScale.micro,
    fontWeight: "600",
    color: colors.gray500,
    textTransform: "uppercase",
    letterSpacing: 0.4,
  },
  factValue: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black },
  verifiedPill: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
    backgroundColor: colors.green50,
    borderRadius: radii.sm,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
  },
  verifiedText: { fontSize: typeScale.micro, fontWeight: "700", color: colors.green700 },
  galleryRow: { gap: spacing.sm, paddingVertical: spacing.sm },
  galleryItem: {
    width: 96,
    height: 96,
    borderRadius: radii.md,
    overflow: "hidden",
    backgroundColor: colors.gray100,
  },
  galleryImage: { width: "100%", height: "100%" },
  galleryVideo: {
    flex: 1,
    alignItems: "center",
    justifyContent: "center",
    backgroundColor: colors.gray900,
    width: "100%",
    height: "100%",
  },
  galleryVideoText: {
    color: colors.white,
    fontSize: typeScale.caption,
    fontWeight: "700",
    textTransform: "uppercase",
  },
  statsRow: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.md },
  stat: {
    flex: 1,
    backgroundColor: colors.gray50,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.md,
    gap: 4,
  },
  statLabel: { fontSize: typeScale.micro, color: colors.gray500, fontWeight: "600" },
  statValue: { fontSize: typeScale.bodyLarge, fontWeight: "800", color: colors.black },
  capacityTrack: {
    marginTop: 4,
    height: 4,
    borderRadius: 2,
    backgroundColor: colors.gray200,
    overflow: "hidden",
  },
  capacityFill: {
    height: "100%",
    backgroundColor: colors.lime,
    borderRadius: 2,
  },
  deadlineChip: {
    alignSelf: "flex-start",
    marginTop: spacing.sm,
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    backgroundColor: colors.amber50,
    borderRadius: radii.sm,
    borderWidth: 1,
    borderColor: colors.amber200,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
  },
  deadlineText: { fontSize: typeScale.caption, fontWeight: "700", color: colors.amber700 },
  aboutCard: {
    marginTop: spacing.md,
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  aboutTitle: { fontSize: typeScale.bodyLarge, fontWeight: "800", color: colors.black },
  aboutBody: { fontSize: typeScale.callout, color: colors.gray700, lineHeight: 22 },
  aboutEmpty: { fontSize: typeScale.body, color: colors.gray500, fontStyle: "italic" },
  showMore: { fontSize: typeScale.body, fontWeight: "700", color: colors.black },
  logistics: {
    marginTop: spacing.sm,
    gap: spacing.sm,
    padding: spacing.lg,
    borderRadius: radii.lg,
    backgroundColor: colors.gray50,
    borderWidth: 1,
    borderColor: colors.gray200,
  },
  logisticsGrid: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm },
  logisticsTile: {
    flexDirection: "row",
    alignItems: "center",
    gap: 6,
    backgroundColor: colors.white,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.sm,
  },
  logisticsItem: { fontSize: typeScale.footnote, fontWeight: "600", color: colors.gray700 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.sm, marginTop: spacing.sm },
  chip: {
    fontSize: typeScale.caption,
    fontWeight: "700",
    color: colors.gray700,
    backgroundColor: colors.limeMuted,
    overflow: "hidden",
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs,
    borderRadius: radii.sm,
  },
  footer: {
    borderTopWidth: 1,
    borderTopColor: colors.gray200,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    backgroundColor: colors.white,
  },
  primary: {
    backgroundColor: colors.black,
    borderRadius: radii.md,
    borderWidth: 2,
    borderColor: colors.lime,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  primaryText: { color: colors.white, fontWeight: "700", fontSize: typeScale.bodyLarge },
  blocked: { gap: spacing.sm },
  appliedRow: { flexDirection: "row", alignItems: "center", gap: spacing.md },
  appliedCopy: { flex: 1, gap: 4, alignItems: "flex-start" },
  appliedTitle: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.black },
  blockedText: { fontSize: typeScale.body, color: colors.gray700, textAlign: "center" },
  secondary: {
    borderWidth: 1,
    borderColor: colors.gray200,
    borderRadius: radii.md,
    paddingVertical: spacing.md,
    alignItems: "center",
  },
  secondaryText: { fontWeight: "700", color: colors.black },
})
