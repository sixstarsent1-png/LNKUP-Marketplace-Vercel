import { useCallback, useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { InlineBanner } from "@/components/ui/banner"
import { Button } from "@/components/ui/button"
import { StatusBadge } from "@/components/ui/status-badge"
import { Surface } from "@/components/ui/surface"
import { Body, Meta, SectionTitle } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchVendorInvites, respondToVendorInvite, type VendorInvite } from "@/lib/api/vendor-invites"
import { useAuth } from "@/lib/auth/auth-context"
import { confirmAction, showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

function whereAndWhen(invite: VendorInvite): string {
  const place = [invite.city, invite.state].filter(Boolean).join(", ")
  const date = invite.eventDate ? new Date(`${invite.eventDate}T12:00:00`).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" }) : ""
  return [date, place, invite.opportunityTitle].filter(Boolean).join(" · ")
}

function expiresLabel(invite: VendorInvite): string {
  if (!invite.expiresAt) return ""
  const at = Date.parse(`${invite.expiresAt.replace(" ", "T")}Z`)
  return Number.isNaN(at) ? "" : `Expires ${new Date(at).toLocaleDateString(undefined, { month: "short", day: "numeric" })}`
}

export default function VendorInvitationsScreen() {
  const { auth } = useAuth()
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [error, setError] = useState<AppErrorShape | null>(null)
  const [items, setItems] = useState<VendorInvite[]>([])
  const [busy, setBusy] = useState<{ id: number; accept: boolean } | null>(null)

  const load = useCallback(
    async (mode: "initial" | "refresh" = "initial") => {
      if (!auth) {
        setLoading(false)
        return
      }
      if (mode === "refresh") setRefreshing(true)
      else setLoading(true)
      setError(null)
      try {
        setItems(await fetchVendorInvites(auth, "open"))
      } catch (err) {
        setError(appErrorFromWp(err))
      } finally {
        setLoading(false)
        setRefreshing(false)
      }
    },
    [auth],
  )

  useEffect(() => {
    void load("initial")
  }, [load])

  async function respond(invite: VendorInvite, accept: boolean) {
    if (busy || !auth) return
    if (!accept) {
      const ok = await confirmAction({
        title: "Decline this invitation?",
        message: `${invite.organizerName || "The organizer"} will see that you can't join ${invite.eventTitle || "this event"}.`,
        confirmLabel: "Decline",
        destructive: true,
      })
      if (!ok) return
    }
    setBusy({ id: invite.id, accept })
    try {
      const result = await respondToVendorInvite(auth, invite.id, accept)
      setItems((prev) => prev.filter((i) => i.id !== invite.id))
      if (accept) {
        const target = result.opportunityId ?? invite.opportunityId
        if (target) {
          router.push(`/apply/${target}` as never)
          return
        }
        showToast("Invitation accepted.", "success")
        return
      }
      showToast("Invitation declined.", "success")
    } catch (err) {
      showToast(`Couldn't update this invitation. ${appErrorFromWp(err).message}`, "error")
      void load("refresh")
    } finally {
      setBusy(null)
    }
  }

  return (
    <Screen
      title="Invitations"
      subtitle="Organizers who want you at their events"
      refreshing={refreshing}
      onRefresh={() => void load("refresh")}
    >
      <BackLink label="Notifications" fallbackHref="/(vendor)/notifications" />

      {loading && items.length === 0 ? <LoadingPanel label="Loading invitations…" variant="skeleton" /> : null}

      {!loading && error && items.length === 0 ? (
        <ErrorPanel title={error.title} message={error.message} onAction={() => void load("initial")} />
      ) : null}

      {error && items.length > 0 ? (
        <InlineBanner tone="error" message={`Couldn't refresh. ${error.message}`} actionLabel="Retry" onAction={() => void load("refresh")} />
      ) : null}

      {!loading && !error && items.length === 0 ? (
        <EmptyPanel
          icon="mail-open-outline"
          title="No open invitations"
          message="Organizers can invite you when your profile matches their events. Keep your categories and markets up to date."
          actionLabel="Explore events"
          onAction={() => router.push("/(vendor)/find")}
        />
      ) : null}

      {items.length > 0 ? (
        <View style={styles.list}>
          {items.map((invite) => {
            const accepting = busy?.id === invite.id && busy.accept
            const declining = busy?.id === invite.id && !busy.accept
            const expires = expiresLabel(invite)
            return (
              <Surface key={invite.id} elevated style={styles.card}>
                <View style={styles.titleRow}>
                  <SectionTitle style={styles.title} numberOfLines={2}>
                    {invite.eventTitle || "Event invitation"}
                  </SectionTitle>
                  {expires ? <StatusBadge label={expires} tone="due" /> : null}
                </View>
                <Meta numberOfLines={2}>{whereAndWhen(invite)}</Meta>
                {invite.organizerName ? <Meta>{`Invited by ${invite.organizerName}`}</Meta> : null}
                {invite.message ? <Body>{invite.message}</Body> : null}
                {invite.opportunityId ? (
                  <Button label="View opportunity" variant="ghost" size="sm" onPress={() => router.push(`/opportunity/${invite.opportunityId}` as never)} />
                ) : null}
                {invite.canRespond ? (
                  <View style={styles.actions}>
                    <Button
                      label="Accept & apply"
                      variant="accent"
                      style={styles.flex}
                      loading={accepting}
                      disabled={Boolean(busy) && !accepting}
                      onPress={() => void respond(invite, true)}
                      accessibilityLabel={`Accept invitation to ${invite.eventTitle}`}
                    />
                    <Button
                      label="Decline"
                      variant="secondary"
                      loading={declining}
                      disabled={Boolean(busy) && !declining}
                      onPress={() => void respond(invite, false)}
                      accessibilityLabel={`Decline invitation to ${invite.eventTitle}`}
                    />
                  </View>
                ) : null}
              </Surface>
            )
          })}
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  list: { gap: spacing.md },
  card: { gap: spacing.sm },
  titleRow: { flexDirection: "row", alignItems: "flex-start", justifyContent: "space-between", gap: spacing.sm },
  title: { flex: 1 },
  actions: { flexDirection: "row", gap: spacing.sm, marginTop: spacing.xs },
  flex: { flex: 1 },
})
