import { Pressable, StyleSheet, View } from "react-native"
import { AppText as Text } from "@/components/ui/app-text"

import { canRequestInfo, canReview, canWaitlist } from "@/components/organizer-vendors/vendor-queue"
import { AppIcon } from "@/components/ui/app-icon"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/ui/status-badge"
import { formatMoney } from "@/lib/api/organizer-financial"
import {
  organizerRequestStatusLabel,
  organizerRequestStatusTone,
  type OrganizerReviewAction,
  type OrganizerVendorRequest,
} from "@/lib/api/organizer-requests"
import { colors, fonts, radii, spacing, typography, typeScale } from "@/lib/theme"

/** Vendor application card for the organizer review queues, with approve/waitlist/reject actions. */
export function OrganizerRequestCard({
  item,
  acting,
  onReview,
  onRequestInfo,
  onOpenProfile,
}: {
  item: OrganizerVendorRequest
  acting: boolean
  onReview?: (item: OrganizerVendorRequest, action: OrganizerReviewAction) => void
  onRequestInfo?: (item: OrganizerVendorRequest) => void
  onOpenProfile?: () => void
}) {
  const statusLabel = organizerRequestStatusLabel(item.status)
  const tone = organizerRequestStatusTone(item.status)
  const subtitle = [item.eventName, item.vendorCategory].filter(Boolean).join(" · ")
  const showActions = Boolean(onReview) && canReview(item.status)
  // Same vendor can hold several requests on one event; the category tells screen-reader users which.
  const who = item.vendorCategory ? `${item.vendorBusinessName}, ${item.vendorCategory}` : item.vendorBusinessName

  return (
    <View style={[styles.card, tone === "due" && styles.cardDue]} testID={`organizer-request-${item.id}`}>
      <Pressable
        onPress={onOpenProfile}
        disabled={!onOpenProfile}
        accessibilityRole={onOpenProfile ? "button" : undefined}
        accessibilityLabel={
          onOpenProfile ? `View profile for ${item.vendorBusinessName}, ${statusLabel}` : undefined
        }
        style={({ pressed }) => [styles.head, pressed && styles.pressed]}
      >
        <View style={styles.titleRow}>
          <Text style={styles.title} numberOfLines={2}>
            {item.vendorBusinessName}
          </Text>
          <StatusBadge label={statusLabel} tone={tone} />
        </View>
        {subtitle ? <Text style={styles.meta}>{subtitle}</Text> : null}
        <View style={styles.footRow}>
          {item.submittedAt ? (
            <View style={styles.metaRow}>
              <AppIcon name="time-outline" size={13} color={colors.gray500} />
              <Text style={styles.meta}>{item.submittedAt}</Text>
            </View>
          ) : (
            <View />
          )}
          {onOpenProfile ? (
            <View style={styles.metaRow}>
              <Text style={styles.viewHint}>View profile</Text>
              <AppIcon name="chevron-forward" size={14} color={colors.black} />
            </View>
          ) : null}
        </View>
      </Pressable>

      {item.amountDue != null ? (
        <View style={styles.amountPill}>
          <Text style={styles.amountDue}>
            {formatMoney(item.amountDue, item.currency ?? "USD")} due
          </Text>
        </View>
      ) : null}

      {item.message ? (
        <View style={styles.messageBox}>
          <AppIcon name="chatbubble-ellipses-outline" size={14} color={colors.gray500} />
          <Text style={styles.message} numberOfLines={4}>
            {item.message}
          </Text>
        </View>
      ) : null}

      {showActions && onReview ? (
        <View style={styles.actions}>
          <Button
            label="Approve"
            icon="checkmark"
            style={styles.actionPrimary}
            disabled={acting}
            loading={acting}
            onPress={() => onReview(item, "approve")}
            accessibilityLabel={`Approve ${who}`}
          />
          {canWaitlist(item.status) ? (
            <Button
              label="Waitlist"
              variant="secondary"
              style={styles.action}
              disabled={acting}
              onPress={() => onReview(item, "waitlist")}
              accessibilityLabel={`Waitlist ${who}`}
            />
          ) : null}
          <Button
            label="Decline"
            variant="danger"
            style={styles.action}
            disabled={acting}
            onPress={() => onReview(item, "reject")}
            accessibilityLabel={`Decline ${who}`}
          />
        </View>
      ) : null}

      {onRequestInfo && canRequestInfo(item.status) ? (
        <Button
          label="Ask for more info"
          variant="ghost"
          icon="help-circle-outline"
          disabled={acting}
          onPress={() => onRequestInfo(item)}
          accessibilityLabel={`Ask ${who} for more information`}
        />
      ) : null}
    </View>
  )
}

const styles = StyleSheet.create({
  card: {
    backgroundColor: colors.white,
    borderRadius: radii.lg,
    borderWidth: 1,
    borderColor: colors.gray200,
    padding: spacing.lg,
    gap: spacing.sm,
  },
  cardDue: {
    backgroundColor: colors.amber50,
    borderColor: colors.amber200,
  },
  head: {
    gap: spacing.xs,
  },
  pressed: {
    opacity: 0.75,
  },
  titleRow: {
    flexDirection: "row",
    alignItems: "flex-start",
    gap: spacing.sm,
  },
  title: {
    flex: 1,
    fontFamily: fonts.displaySemi,
    fontSize: typeScale.bodyLarge,
    lineHeight: 22,
    color: colors.black,
  },
  meta: {
    ...typography.meta,
  },
  metaRow: {
    flexDirection: "row",
    alignItems: "center",
    gap: 4,
  },
  footRow: {
    flexDirection: "row",
    justifyContent: "space-between",
    alignItems: "center",
  },
  viewHint: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.black,
  },
  amountPill: {
    alignSelf: "flex-start",
    backgroundColor: colors.white,
    borderRadius: radii.sm,
    borderWidth: 1,
    borderColor: colors.amber200,
    paddingHorizontal: spacing.sm,
    paddingVertical: 4,
  },
  amountDue: {
    fontFamily: fonts.bodyBold,
    fontSize: typeScale.body,
    color: colors.amber700,
  },
  messageBox: {
    flexDirection: "row",
    gap: spacing.sm,
    backgroundColor: colors.gray50,
    borderRadius: radii.md,
    padding: spacing.md,
  },
  message: {
    ...typography.body,
    flex: 1,
  },
  actions: {
    flexDirection: "row",
    gap: spacing.sm,
    marginTop: spacing.xs,
  },
  actionPrimary: {
    flex: 1.3,
    paddingHorizontal: spacing.sm,
  },
  action: {
    flex: 1,
    paddingHorizontal: spacing.sm,
  },
})
