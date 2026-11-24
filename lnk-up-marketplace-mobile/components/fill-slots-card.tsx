import { useCallback, useState } from "react"
import { Pressable, StyleSheet, View } from "react-native"
import { router, useFocusEffect, type Href } from "expo-router"

import { AppText as Text } from "@/components/ui/app-text"
import { Button } from "@/components/ui/button"
import { Skeleton } from "@/components/ui/skeleton"
import {
  fetchEventFulfillment,
  fetchEventVendorMatches,
  fetchFindVendorsEnabled,
  openCategoryLabels,
  type EventFulfillment,
  type MatchedVendor,
} from "@/lib/api/match"
import { useAuth } from "@/lib/auth/auth-context"
import { colors, radii, spacing, typeScale } from "@/lib/theme"

type Loaded = { fulfillment: EventFulfillment; vendors: MatchedVendor[] }

type CardState = { status: "loading" } | { status: "empty" } | { status: "error" } | { status: "ready"; data: Loaded }

function hasOpenSlots(f: EventFulfillment): boolean {
  return f.openOpportunities > 0 && (f.remaining > 0 || f.unlimitedSlots > 0)
}

function actionHref(f: EventFulfillment): Href {
  const view = f.actions[0]?.view
  if (view === "applications") return `/(organizer)/vendors?event=${f.eventId}` as Href
  if (view === "events" || view === "operations") return `/(organizer)/events/${f.eventId}` as Href
  return `/(organizer)/vendors/find?event=${f.eventId}` as Href
}

/**
 * "Vendor slots to fill": remaining spots by category for the first listed event that still has
 * open slots, the most useful next action, and up to three recommended vendors. Renders nothing
 * when every slot is filled or no event is accepting vendors.
 */
export function FillSlotsCard({ eventIds, showEventTitle = true }: { eventIds: string[]; showEventTitle?: boolean }) {
  const { auth } = useAuth()
  const [state, setState] = useState<CardState>({ status: "loading" })
  const key = eventIds.join(",")

  const load = useCallback(async () => {
    if (!auth || eventIds.length === 0) {
      setState({ status: "empty" })
      return
    }
    try {
      let fulfillment: EventFulfillment | null = null
      for (const id of eventIds.slice(0, 3)) {
        const candidate = await fetchEventFulfillment(auth, Number(id))
        if (hasOpenSlots(candidate)) {
          fulfillment = candidate
          break
        }
      }
      if (!fulfillment) {
        setState({ status: "empty" })
        return
      }
      let vendors: MatchedVendor[] = []
      if (await fetchFindVendorsEnabled(auth).catch(() => false)) {
        const matches = await fetchEventVendorMatches(auth, fulfillment.eventId, { perPage: 3 }).catch(() => null)
        vendors = matches?.status === "ready" ? matches.items.slice(0, 3) : []
      }
      setState({ status: "ready", data: { fulfillment, vendors } })
    } catch {
      setState({ status: "error" })
    }
    // eventIds is represented by `key`.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [auth, key])

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  if (state.status === "empty") return null
  if (state.status === "loading") {
    return (
      <View style={styles.card} accessibilityLabel="Loading vendor slots">
        <Skeleton height={18} width="50%" />
        <Skeleton height={14} width="80%" />
      </View>
    )
  }
  if (state.status === "error") {
    return (
      <View style={styles.card}>
        <Text style={styles.heading}>Vendor slots to fill</Text>
        <Text style={styles.meta}>We couldn't load your open vendor slots.</Text>
        <Button label="Try again" variant="ghost" size="sm" onPress={() => void load()} />
      </View>
    )
  }

  const { fulfillment, vendors } = state.data
  const categories = openCategoryLabels(fulfillment)
  const action = fulfillment.actions[0]
  const findHref = `/(organizer)/vendors/find?event=${fulfillment.eventId}` as Href
  const summary =
    fulfillment.remaining > 0
      ? `${fulfillment.remaining} vendor spot${fulfillment.remaining === 1 ? "" : "s"} still open`
      : "Accepting vendor applications"

  return (
    <View style={styles.card} testID="fill-slots-card">
      <Text style={styles.heading} accessibilityRole="header">
        Vendor slots to fill
      </Text>
      <Text style={styles.meta}>{showEventTitle ? `${summary} · ${fulfillment.eventTitle}` : summary}</Text>
      {categories.length ? (
        <View style={styles.chips}>
          {categories.slice(0, 6).map((label) => (
            <Text key={label} style={styles.chip}>
              {label}
            </Text>
          ))}
        </View>
      ) : null}
      {action ? (
        <Pressable onPress={() => router.push(actionHref(fulfillment))} accessibilityRole="button" style={styles.action}>
          <Text style={styles.actionText}>{action.label}</Text>
          <Text style={styles.actionCta}>{action.cta} ›</Text>
        </Pressable>
      ) : null}
      {vendors.length ? (
        <View style={styles.vendors}>
          <Text style={styles.subheading}>Recommended vendors</Text>
          {vendors.map((vendor) => (
            <View key={vendor.profileId} style={styles.vendor}>
              <Text style={styles.vendorName} numberOfLines={1}>
                {vendor.businessName}
                {vendor.verified ? "  ✓ Verified" : ""}
              </Text>
              <Text style={styles.meta} numberOfLines={1}>
                {[vendor.primaryCategory, [vendor.city, vendor.state].filter(Boolean).join(", "), vendor.reasons[0]].filter(Boolean).join(" · ")}
              </Text>
            </View>
          ))}
        </View>
      ) : null}
      <Button label="Find vendors" variant="secondary" size="sm" onPress={() => router.push(findHref)} />
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    borderLeftWidth: 4,
    borderLeftColor: colors.lime,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  heading: { fontSize: typeScale.callout, fontWeight: "700", color: colors.black },
  subheading: { fontSize: typeScale.footnote, fontWeight: "700", color: colors.black },
  meta: { fontSize: typeScale.footnote, color: colors.gray500 },
  chips: { flexDirection: "row", flexWrap: "wrap", gap: spacing.xs },
  chip: {
    fontSize: typeScale.footnote,
    fontWeight: "600",
    color: colors.black,
    backgroundColor: colors.limeMuted,
    borderRadius: radii.pill,
    paddingHorizontal: spacing.sm,
    paddingVertical: 2,
    overflow: "hidden",
  },
  action: { gap: 2, paddingVertical: spacing.xs },
  actionText: { fontSize: typeScale.body, fontWeight: "600", color: colors.black },
  actionCta: { fontSize: typeScale.footnote, fontWeight: "700", color: colors.black },
  vendors: { gap: spacing.xs },
  vendor: { gap: 1 },
  vendorName: { fontSize: typeScale.body, fontWeight: "600", color: colors.black },
})
