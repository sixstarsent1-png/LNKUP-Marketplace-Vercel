import { Pressable, StyleSheet, View } from "react-native"

import { ProfileMark } from "@/components/profile-mark"
import { AppText as Text } from "@/components/ui/app-text"
import { StatusBadge } from "@/components/ui/status-badge"
import type { InviteStatus, OrgInvite } from "@/lib/api/organizer-mobile"
import { formatDateTime, formatShortDate } from "@/lib/format/date"
import { colors, layout, spacing, typography, type StatusTone } from "@/lib/theme"

export function inviteStatusLabel(status: InviteStatus): { label: string; tone: StatusTone } {
  switch (status) {
    case "sent":
      return { label: "Sent", tone: "review" }
    case "opened":
      return { label: "Opened", tone: "review" }
    case "accepted":
      return { label: "Accepted", tone: "paid" }
    case "declined":
      return { label: "Declined", tone: "rejected" }
    case "expired":
      return { label: "Expired", tone: "neutral" }
    case "cancelled":
      return { label: "Cancelled", tone: "neutral" }
    default: {
      const exhaustive: never = status
      return exhaustive
    }
  }
}

/** "Sent Nov 2, 2026" — the send date, never confused with the event date. */
export function inviteSentLine(invite: Pick<OrgInvite, "sentAt">): string {
  const sent = formatDateTime(invite.sentAt, { gmt: true })
  return sent ? `Sent ${sent.split(",").slice(0, 2).join(",")}` : "Sent"
}

/** Invitation row: vendor, event (with its own date) and when the invite was sent. */
export function InviteRow({ invite, onPress }: { invite: OrgInvite; onPress: () => void }) {
  const status = inviteStatusLabel(invite.status)
  const eventDate = formatShortDate(invite.eventDate)
  const eventLine = [invite.eventTitle || "Event", eventDate ? `event ${eventDate}` : ""].filter(Boolean).join(" · ")
  return (
    <Pressable
      onPress={onPress}
      accessibilityRole="button"
      accessibilityLabel={`${invite.businessName}. ${eventLine}. ${inviteSentLine(invite)}. ${status.label}`}
      testID={`invite-row-${invite.id}`}
      style={({ pressed }) => [styles.row, pressed && styles.pressed]}
    >
      <ProfileMark name={invite.businessName || "Vendor"} logoUrl={invite.logoUrl || undefined} size={40} />
      <View style={styles.text}>
        <Text style={styles.title} numberOfLines={1}>
          {invite.businessName || "Vendor"}
        </Text>
        <Text style={styles.meta} numberOfLines={1}>
          {eventLine}
        </Text>
        <Text style={styles.meta} numberOfLines={1}>
          {inviteSentLine(invite)}
          {invite.reminderCount > 0 ? ` · reminded ${invite.reminderCount}×` : ""}
        </Text>
      </View>
      <StatusBadge label={status.label} tone={status.tone} />
    </Pressable>
  )
}

const styles = StyleSheet.create({
  row: {
    flexDirection: "row",
    alignItems: "center",
    gap: spacing.md,
    minHeight: layout.hitTargetMin + 16,
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
})
