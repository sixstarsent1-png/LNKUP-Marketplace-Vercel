import { useCallback, useEffect, useMemo, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, type Href } from "expo-router"

import { InviteRow, inviteSentLine, inviteStatusLabel } from "@/components/organizer/invite-row"
import { InvitesList } from "@/components/organizer-vendors/invites-list"
import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { BottomSheet } from "@/components/ui/bottom-sheet"
import { Button } from "@/components/ui/button"
import { FilterChipRow, type ChipOption } from "@/components/ui/chip"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { StatusBadge } from "@/components/ui/status-badge"
import { Meta } from "@/components/ui/typography"
import { fetchOrganizerInvites, type OrganizerInvite } from "@/lib/api/organizer-crm"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import {
  cancelInvite,
  fetchInviteOverview,
  remindInvite,
  type InviteOverview,
  type OrgInvite,
} from "@/lib/api/organizer-mobile"
import type { WpAuth } from "@/lib/api/wp-client"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { formatDateTime, formatShortDate } from "@/lib/format/date"
import { spacing } from "@/lib/theme"

type InviteFilter = "all" | "awaiting" | "accepted" | "declined" | "expired" | "cancelled"

function matches(invite: OrgInvite, filter: InviteFilter): boolean {
  switch (filter) {
    case "all":
      return true
    case "awaiting":
      return invite.status === "sent" || invite.status === "opened"
    case "accepted":
      return invite.status === "accepted"
    case "declined":
      return invite.status === "declined"
    case "expired":
      return invite.status === "expired"
    case "cancelled":
      return invite.status === "cancelled"
    default: {
      const exhaustive: never = filter
      return exhaustive
    }
  }
}

type InvitesPanelProps = {
  auth: WpAuth | undefined
  eventFilter: string | null
  openInviteId: string | null
  onDiscover: () => void
}

/** Invitations 2.0: counts, filters, detail, remind and cancel. Older servers get the previous list. */
export function InvitesPanel({ auth, eventFilter, openInviteId, onDiscover }: InvitesPanelProps) {
  const [overview, setOverview] = useState<InviteOverview | null>(null)
  const [legacy, setLegacy] = useState<OrganizerInvite[] | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [filter, setFilter] = useState<InviteFilter>("awaiting")
  const [selectedId, setSelectedId] = useState<string | null>(openInviteId)
  const [busy, setBusy] = useState<"remind" | "cancel" | null>(null)

  const load = useCallback(
    async (force = false) => {
      if (!auth) return
      setError(null)
      try {
        const next = await fetchInviteOverview(auth, force)
        if (next) setOverview(next)
        else setLegacy(await fetchOrganizerInvites(auth, { force }))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load()
  }, [load])

  const scoped = useMemo(
    () => (overview?.items ?? []).filter((invite) => !eventFilter || invite.eventId === eventFilter),
    [overview, eventFilter],
  )
  const visible = useMemo(() => scoped.filter((invite) => matches(invite, filter)), [scoped, filter])
  const selected = scoped.find((invite) => invite.id === selectedId) ?? null

  const chips = useMemo<ChipOption<InviteFilter>[]>(() => {
    const count = (key: InviteFilter) => scoped.filter((invite) => matches(invite, key)).length
    return [
      { value: "awaiting", label: "Awaiting reply", count: count("awaiting") },
      { value: "accepted", label: "Accepted", count: count("accepted") },
      { value: "declined", label: "Declined", count: count("declined") },
      { value: "expired", label: "Expired", count: count("expired") },
      { value: "cancelled", label: "Cancelled", count: count("cancelled") },
      { value: "all", label: "All", count: scoped.length },
    ]
  }, [scoped])

  async function handleRemind(invite: OrgInvite) {
    if (!auth || busy) return
    setBusy("remind")
    try {
      await remindInvite(invite.id, auth)
      showToast(`Reminder sent to ${invite.businessName}.`, "success")
      await load(true)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusy(null)
    }
  }

  async function handleCancel(invite: OrgInvite) {
    if (!auth || busy) return
    const ok = await confirmAction({
      title: "Cancel this invitation?",
      message: `${invite.businessName} won't be able to use the invite link anymore. They can still find the event and apply on their own.`,
      confirmLabel: "Cancel invitation",
      cancelLabel: "Keep it",
      destructive: true,
    })
    if (!ok) return
    setBusy("cancel")
    try {
      await cancelInvite(invite.id, auth)
      showToast("Invitation cancelled.", "success")
      await load(true)
    } catch (err) {
      showToast(appErrorFromWp(err).message, "error")
    } finally {
      setBusy(null)
    }
  }

  if (legacy) {
    return (
      <InvitesList invites={legacy} loading={false} error={null} eventFilter={eventFilter} onRetry={() => void load(true)} onDiscover={onDiscover} />
    )
  }

  return (
    <View style={styles.stack} testID="invites-panel">
      {loading && !overview ? <LoadingPanel label="Loading invitations…" variant="skeleton" count={3} /> : null}
      {!loading && error && !overview ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load(true)} /> : null}

      {overview ? (
        <>
          <FilterChipRow options={chips} value={filter} onChange={setFilter} />
          {visible.length === 0 ? (
            <EmptyPanel
              icon="mail-outline"
              title={scoped.length === 0 ? "No invitations yet" : "Nothing in this view"}
              message={scoped.length === 0 ? "Invite vendors who fit your events from Discover." : "Try another filter."}
              actionLabel={scoped.length === 0 ? "Discover vendors" : undefined}
              onAction={scoped.length === 0 ? onDiscover : undefined}
            />
          ) : (
            <ListGroup>
              {visible.map((invite) => (
                <InviteRow key={invite.id} invite={invite} onPress={() => setSelectedId(invite.id)} />
              ))}
            </ListGroup>
          )}
        </>
      ) : null}

      <BottomSheet
        visible={Boolean(selected)}
        onClose={() => setSelectedId(null)}
        title={selected?.businessName ?? "Invitation"}
        footer={
          selected && (selected.canRemind || selected.canCancel) ? (
            <View style={styles.row}>
              {selected.canRemind ? (
                <Button label="Send reminder" icon="notifications-outline" style={styles.flex} loading={busy === "remind"} disabled={Boolean(busy)} onPress={() => void handleRemind(selected)} testID="invite-remind" />
              ) : null}
              {selected.canCancel ? (
                <Button label="Cancel invite" variant="danger" style={styles.flex} loading={busy === "cancel"} disabled={Boolean(busy)} onPress={() => void handleCancel(selected)} testID="invite-cancel" />
              ) : null}
            </View>
          ) : undefined
        }
      >
        {selected ? (
          <View style={styles.stack} testID="invite-detail">
            <StatusBadge label={inviteStatusLabel(selected.status).label} tone={inviteStatusLabel(selected.status).tone} />
            <ListGroup>
              <ListRow
                icon="calendar-outline"
                title={selected.eventTitle || "Event"}
                meta={[selected.opportunityTitle, formatShortDate(selected.eventDate) ? `Event ${formatShortDate(selected.eventDate)}` : ""].filter(Boolean).join(" · ")}
                onPress={selected.eventId ? () => router.push(`/(organizer)/events/${selected.eventId}` as Href) : undefined}
              />
              <ListRow icon="paper-plane-outline" title={inviteSentLine(selected)} meta={selected.expiresAt ? `Link expires ${formatDateTime(selected.expiresAt, { gmt: true })}` : undefined} />
              {selected.reminderCount > 0 ? (
                <ListRow icon="notifications-outline" title={`Reminded ${selected.reminderCount}×`} meta={selected.remindedAt ? `Last ${formatDateTime(selected.remindedAt, { gmt: true })}` : undefined} />
              ) : null}
              {selected.respondedAt ? <ListRow icon="chatbubble-outline" title="Responded" meta={formatDateTime(selected.respondedAt, { gmt: true })} /> : null}
              {selected.applicationId ? (
                <ListRow
                  icon="document-text-outline"
                  title="View their application"
                  meta={selected.applicationStatus ? `Status: ${selected.applicationStatus.replace(/_/g, " ")}` : undefined}
                  onPress={() => {
                    setSelectedId(null)
                    router.push(`/(organizer)/vendors/review/${selected.applicationId}` as Href)
                  }}
                />
              ) : null}
            </ListGroup>
            {selected.message ? <Meta>“{selected.message}”</Meta> : null}
            {!selected.canRemind && (selected.status === "sent" || selected.status === "opened") ? (
              <Meta>A reminder was sent recently. You can send another in a couple of days.</Meta>
            ) : null}
          </View>
        ) : null}
      </BottomSheet>
    </View>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.md },
  row: { flexDirection: "row", gap: spacing.sm },
  flex: { flex: 1 },
})
