import { Pressable, StyleSheet, View } from "react-native"

import { ProfileMark } from "@/components/profile-mark"
import { AppText as Text } from "@/components/ui/app-text"
import { StatusPill } from "@/components/ui/status-pill"
import { formatMoney } from "@/lib/api/format-money"
import type { OrganizerVendorRequest } from "@/lib/api/organizer-requests"
import { colors, fonts, layout, spacing, typeScale, typography } from "@/lib/theme"

type ApplicationRowProps = {
  request: OrganizerVendorRequest
  onPress: () => void
  logoUrl?: string
  /** Documents waiting for organizer review on this application. */
  hasDocumentsToReview?: boolean
}

/** "$200 booth fee" while deciding, "$200 due" once approved and unpaid, nothing once paid. */
export function applicationFeeLabel(request: Pick<OrganizerVendorRequest, "status" | "amountDue" | "currency">): string | null {
  if (request.amountDue == null || request.amountDue <= 0) return null
  const amount = formatMoney(request.amountDue, request.currency)
  if (request.status === "approved" || request.status === "payment_required") return `${amount} due`
  if (request.status === "paid" || request.status === "completed" || request.status === "rejected" || request.status === "cancelled") return null
  return `${amount} booth fee`
}

/** Compact application row for the review queue. */
export function ApplicationRow({ request, onPress, logoUrl, hasDocumentsToReview }: ApplicationRowProps) {
  const eventLine = [request.eventName, request.opportunityName].filter(Boolean).join(" · ")
  const metaLine = [request.vendorCategory, request.submittedAt ? `Applied ${request.submittedAt}` : ""].filter(Boolean).join(" · ")
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${request.vendorBusinessName}. ${eventLine}. ${metaLine}`}
      accessibilityHint="Opens the application review"
      testID={`organizer-request-${request.id}`}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      <ProfileMark name={request.vendorBusinessName} logoUrl={logoUrl} size={40} />
      <View style={styles.text}>
        <Text style={styles.title} numberOfLines={1}>
          {request.vendorBusinessName}
        </Text>
        <Text style={styles.meta} numberOfLines={1}>
          {eventLine}
        </Text>
        {metaLine ? (
          <Text style={styles.meta} numberOfLines={1}>
            {metaLine}
          </Text>
        ) : null}
        {hasDocumentsToReview ? <Text style={styles.docs}>Documents to review</Text> : null}
      </View>
      <View style={styles.right}>
        <StatusPill status={request.status} audience="organizer" />
        {applicationFeeLabel(request) ? <Text style={styles.fee}>{applicationFeeLabel(request)}</Text> : null}
      </View>
    </Pressable>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 20,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.md,
    backgroundColor: colors.white,
  },
  pressed: {
    backgroundColor: colors.gray50,
  },
  text: {
    flex: 1,
    gap: 2,
  },
  title: {
    ...typography.label,
  },
  meta: {
    ...typography.meta,
  },
  docs: {
    ...typography.caption,
    color: colors.amber700,
  },
  right: {
    alignItems: "flex-end",
    gap: spacing.xs,
  },
  fee: {
    fontFamily: fonts.bodySemi,
    fontSize: typeScale.footnote,
    color: colors.gray700,
  },
})
