import { useCallback, useState } from "react"
import { StyleSheet, View } from "react-native"
import { router, useFocusEffect, type Href } from "expo-router"

import { EmptyPanel, ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Screen } from "@/components/screen"
import { BackLink } from "@/components/ui/back-link"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Meta } from "@/components/ui/typography"
import { isLiveApiEnabled } from "@/lib/api/config"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { fetchOrganizerEvents, type OrgEventCard } from "@/lib/api/organizer-mobile"
import { wpFetchJson, type WpAuth } from "@/lib/api/wp-client"
import { useAuth } from "@/lib/auth/auth-context"
import { formatShortDate } from "@/lib/format/date"
import { spacing } from "@/lib/theme"

type Member = { id: string; name: string; role: string }
type EventStaff = { event: OrgEventCard; staff: { name: string; roleLabel: string }[] }
type TeamData = { enabled: boolean; members: Member[]; roles: Record<string, string>; events: EventStaff[] }

const EVENT_STAFF_LIMIT = 5

async function loadTeam(auth: WpAuth): Promise<TeamData> {
  if (!isLiveApiEnabled()) return { enabled: false, members: [], roles: {}, events: [] }
  const org = (await wpFetchJson<Record<string, unknown>>("/org/team", { auth }).catch(() => ({}))) as Record<string, unknown>
  const roles = (org.roles && typeof org.roles === "object" ? org.roles : {}) as Record<string, string>
  const members = (Array.isArray(org.members) ? org.members : []).map((m) => {
    const x = (m ?? {}) as Record<string, unknown>
    return { id: String(x.user_id ?? x.id ?? ""), name: String(x.name ?? x.display_name ?? "Team member"), role: String(x.role ?? "") }
  })
  const upcoming = (await fetchOrganizerEvents(auth, { chronology: "upcoming" }).catch(() => null))?.events.slice(0, EVENT_STAFF_LIMIT) ?? []
  const events = await Promise.all(
    upcoming.map(async (event) => {
      const res = await wpFetchJson<{ items?: Array<{ name?: string; role_label?: string }> }>(`/operations/events/${event.id}/staff`, { auth }).catch(() => ({ items: [] }))
      return { event, staff: (res.items ?? []).map((s) => ({ name: String(s.name ?? ""), roleLabel: String(s.role_label ?? "") })) }
    }),
  )
  return { enabled: Boolean(org.enabled), members, roles, events }
}

/** Who helps run your events: organization team (when enabled) and per-event staff. Read-only. */
export default function OrganizerTeamScreen() {
  const { auth } = useAuth()
  const [data, setData] = useState<TeamData | null>(null)
  const [error, setError] = useState<AppErrorShape | null>(null)

  const load = useCallback(async () => {
    if (!auth) return
    setError(null)
    try {
      setData(await loadTeam(auth))
    } catch (err) {
      setError(appErrorFromWp(err))
    }
  }, [auth])

  useFocusEffect(
    useCallback(() => {
      void load()
    }, [load]),
  )

  return (
    <Screen title="Team" subtitle="People who help run your events" refreshing={false} onRefresh={() => void load()} header={<BackLink label="More" fallbackHref={"/(organizer)/more" as Href} />}>
      {!data && !error ? <LoadingPanel label="Loading team…" variant="skeleton" count={2} /> : null}
      {error && !data ? <ErrorPanel title={error.title} message={error.message} onAction={() => void load()} /> : null}

      {data ? (
        <View style={styles.stack} testID="organizer-team">
          {data.enabled ? (
            data.members.length === 0 ? (
              <EmptyPanel icon="people-outline" title="Just you so far" message="Invite teammates from LNKup web to share event work." />
            ) : (
              <ListGroup title="Organization team">
                {data.members.map((m) => (
                  <ListRow key={m.id} icon="person-outline" title={m.name} meta={data.roles[m.role] ?? m.role} />
                ))}
              </ListGroup>
            )
          ) : (
            <Meta>Staff are added per event. Each role only sees what it needs — for example, security can check vendors in but can’t see money.</Meta>
          )}

          <ListGroup title="Event staff">
            {data.events.length === 0 ? (
              <ListRow icon="calendar-outline" title="No upcoming events" />
            ) : (
              data.events.map(({ event, staff }) => (
                <ListRow
                  key={event.id}
                  icon="calendar-outline"
                  title={event.title}
                  meta={[formatShortDate(event.date), staff.length ? staff.map((s) => `${s.name} (${s.roleLabel})`).join(", ") : "No staff assigned"].filter(Boolean).join(" · ")}
                  onPress={() => router.push(`/(organizer)/events/operations?eventId=${event.id}` as Href)}
                />
              ))
            )}
          </ListGroup>
          <Meta>Adding or removing staff is done on LNKup web.</Meta>
        </View>
      ) : null}
    </Screen>
  )
}

const styles = StyleSheet.create({
  stack: { gap: spacing.lg },
})
