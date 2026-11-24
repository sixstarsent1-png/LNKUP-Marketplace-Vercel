import { Pressable, StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { AppIcon, type AppIconName } from "@/components/ui/app-icon"
import { AppText as Text } from "@/components/ui/app-text"
import { Meta } from "@/components/ui/typography"
import { formatMoney } from "@/lib/api/format-money"
import type { VendorReadiness } from "@/lib/api/vendor-readiness"
import type { VendorRequest } from "@/lib/api/vendor-requests"
import { isEventOver, isPastEventInvoice, isUpcomingPaymentDue, payCtaLabel } from "@/lib/vendor/booking-lifecycle"
import { colors, fonts, layout, radii, shadow, spacing, typeScale } from "@/lib/theme"

export type ActionItem = {
  key: string
  icon: AppIconName
  title: string
  context: string
  href: Href
  urgent: boolean
}

const MAX_ROWS = 4

/**
 * Everything that needs the vendor, from real records: organizer questions and payments that
 * confirm an upcoming spot (one row each, with the event), required documents (one consolidated
 * row) and past-event invoices (one consolidated row, never framed as confirming a spot).
 */
export function buildActionItems(requests: VendorRequest[], readiness: VendorReadiness | null): ActionItem[] {
  const items: ActionItem[] = []
  for (const r of requests) {
    if (r.status === "info_requested" && !isEventOver(r)) {
      items.push({
        key: `reply:${r.id}`,
        icon: "chatbubble-ellipses-outline",
        title: `Reply to ${r.organizerName || "the organizer"}`,
        context: r.eventName,
        href: `/booking/${r.id}` as Href,
        urgent: true,
      })
    }
  }
  for (const r of requests) {
    if (!isUpcomingPaymentDue(r)) continue
    items.push({
      key: `pay:${r.id}`,
      icon: "card-outline",
      title: `${payCtaLabel(r)} to confirm your booth`,
      context: [r.eventName, r.eventDate].filter(Boolean).join(" · "),
      href: `/pass/${r.id}` as Href,
      urgent: true,
    })
  }
  const docs = (readiness?.missingItems ?? []).filter((m) => m.type === "document" && m.severity === "required")
  if (docs.length > 0) {
    items.push({
      key: "documents",
      icon: "document-attach-outline",
      title: docs.length === 1 ? `Upload ${docs[0].label}` : `${docs.length} required documents to upload`,
      context: docs.length === 1 ? "Organizers check this before approving" : docs.slice(0, 3).map((d) => d.label).join(", "),
      href: "/(vendor)/documents" as Href,
      urgent: false,
    })
  }
  const pastInvoices = requests.filter((r) => isPastEventInvoice(r))
  if (pastInvoices.length > 0) {
    const total = pastInvoices.reduce((sum, r) => sum + (r.amountDue ?? 0), 0)
    items.push({
      key: "past-invoices",
      icon: "receipt-outline",
      title: pastInvoices.length === 1 ? "Outstanding invoice · past event" : `${pastInvoices.length} outstanding invoices · past events`,
      context: [pastInvoices.length === 1 ? pastInvoices[0].eventName : null, total > 0 ? `${formatMoney(total)} open` : null].filter(Boolean).join(" · "),
      href: "/(vendor)/wallet" as Href,
      urgent: false,
    })
  }
  return items
}

export function ActionCenter({ items }: { items: ActionItem[] }) {
  if (items.length === 0) return null
  const shown = items.slice(0, MAX_ROWS)
  const hidden = items.length - shown.length
  return (
    <View style={styles.card} testID="vendor-next-step" accessibilityRole="summary">
      <View style={styles.head}>
        <Text style={styles.heading} accessibilityRole="header">
          Needs action
        </Text>
        <View style={styles.count}>
          <Text style={styles.countText}>{items.length}</Text>
        </View>
      </View>
      {shown.map((item, index) => (
        <Pressable
          key={item.key}
          onPress={() => router.push(item.href)}
          accessibilityRole="button"
          accessibilityLabel={`${item.title}. ${item.context}`}
          style={({ pressed }) => [styles.row, index > 0 && styles.divider, pressed && styles.pressed]}
        >
          <View style={[styles.icon, item.urgent && styles.iconUrgent]}>
            <AppIcon name={item.icon} size={18} color={item.urgent ? colors.amber700 : colors.ink} />
          </View>
          <View style={styles.text}>
            <Text style={styles.title} numberOfLines={2}>
              {item.title}
            </Text>
            {item.context ? <Meta numberOfLines={1}>{item.context}</Meta> : null}
          </View>
          <AppIcon name="chevron-forward" size={16} color={colors.gray400} />
        </Pressable>
      ))}
      {hidden > 0 ? (
        <Pressable
          onPress={() => router.push("/(vendor)/requests?filter=action" as Href)}
          accessibilityRole="button"
          style={({ pressed }) => [styles.more, pressed && styles.pressed]}
        >
          <Text style={styles.moreText}>
            {hidden} more in Bookings
          </Text>
        </Pressable>
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.line,
    paddingHorizontal: spacing.md,
    paddingTop: spacing.md,
    paddingBottom: spacing.xs,
    ...shadow("sm"),
  },
  head: { flexDirection: "row", alignItems: "center", gap: spacing.sm, marginBottom: spacing.xs },
  heading: { fontFamily: fonts.displaySemi, fontSize: typeScale.headline, color: colors.ink },
  count: { minWidth: 22, height: 22, borderRadius: 11, paddingHorizontal: 6, backgroundColor: colors.lime, alignItems: "center", justifyContent: "center" },
  countText: { fontFamily: fonts.bodyBold, fontSize: typeScale.caption, color: colors.black },
  row: { flexDirection: "row", alignItems: "center", gap: spacing.md, minHeight: layout.hitTargetMin + 8, paddingVertical: spacing.sm },
  divider: { borderTopWidth: StyleSheet.hairlineWidth, borderTopColor: colors.gray200 },
  pressed: { opacity: 0.7 },
  icon: { width: 34, height: 34, borderRadius: radii.md, backgroundColor: colors.gray100, alignItems: "center", justifyContent: "center" },
  iconUrgent: { backgroundColor: colors.amber50 },
  text: { flex: 1, gap: 2 },
  title: { fontFamily: fonts.bodySemi, fontSize: typeScale.callout, color: colors.ink },
  more: { paddingVertical: spacing.sm, alignItems: "center" },
  moreText: { fontFamily: fonts.bodySemi, fontSize: typeScale.footnote, color: colors.gray700 },
})
