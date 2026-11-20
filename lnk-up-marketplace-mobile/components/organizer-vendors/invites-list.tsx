import { StyleSheet, View } from "react-native"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusBadge } from "@/components/ui/status-badge"
import type { AppErrorShape } from "@/lib/api/errors"
import { inviteStatusLabel, type OrganizerInvite } from "@/lib/api/organizer-crm"
import { spacing, type StatusTone } from "@/lib/theme"

const TONE: Record<OrganizerInvite["status"], StatusTone> = {
  sent: "review",
  opened: "review",
  accepted: "paid",
  declined: "rejected",
  expired: "neutral",
  unknown: "neutral",
}

/** Invites the organizer has sent and whether vendors opened or applied. */
export function InvitesList({
  invites,
  loading,
  error,
  eventFilter,
  onRetry,
  onDiscover,
}: {
  invites: OrganizerInvite[] | null
  loading: boolean
  error: AppErrorShape | null
  eventFilter: string | null
  onRetry: () => void
  onDiscover: () => void
}) {
  const visible = (invites ?? []).filter((invite) => !eventFilter || invite.eventId === eventFilter)

  if (loading && !invites) return <LoadingPanel label="Loading invites…" variant="skeleton" count={2} />
  if (error && !invites) return <ErrorPanel title={error.title} message={error.message} onAction={onRetry} />
  if (visible.length === 0) {
    return (
      <EmptyPanel
        icon="paper-plane-outline"
        title="No invites yet"
        message="Find vendors on Discover and invite them to apply to one of your events."
        actionLabel="Discover vendors"
        onAction={onDiscover}
      />
    )
  }

  return (
    <View style={styles.stack} testID="organizer-invites-list">
      <ListGroup title={`${visible.length} invite${visible.length === 1 ? "" : "s"}`}>
        {visible.map((invite) => (
          <ListRow
            key={invite.id}
            icon="paper-plane-outline"
            title={invite.vendorName}
            meta={[invite.eventTitle, invite.createdAt].filter(Boolean).join(" · ")}
            rightSlot={<StatusBadge label={inviteStatusLabel(invite.status)} tone={TONE[invite.status]} />}
          />
        ))}
      </ListGroup>
    </View>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
})
