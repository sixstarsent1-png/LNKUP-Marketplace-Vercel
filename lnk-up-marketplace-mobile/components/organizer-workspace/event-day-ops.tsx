import { useCallback, useEffect, useState } from "react"
import { StyleSheet, View } from "react-native"

import { ErrorPanel, LoadingPanel } from "@/components/panel-states"
import { Button } from "@/components/ui/button"
import { ListGroup, ListRow } from "@/components/ui/list-row"
import { Meta } from "@/components/ui/typography"
import { appErrorFromWp, type AppErrorShape } from "@/lib/api/errors"
import { completeEventTask, fetchEventOps, type EventOps, type EventTask } from "@/lib/api/organizer-ops"
import type { WpAuth } from "@/lib/api/wp-client"
import { showToast } from "@/lib/feedback/feedback"
import { spacing } from "@/lib/theme"

type LoadState = { kind: "loading" } | { kind: "error"; error: AppErrorShape } | { kind: "loaded"; ops: EventOps }

/**
 * Readiness and open tasks for staff on site. Hidden when the site does not run Organizer OS
 * event operations; editing tasks and readiness marks stays on LNKup web.
 */
export function EventDayOps({ eventId, auth }: { eventId: string; auth?: WpAuth }) {
  const [state, setState] = useState<LoadState>({ kind: "loading" })
  const [completing, setCompleting] = useState<number | null>(null)

  const load = useCallback(async () => {
    if (!auth) return
    setState({ kind: "loading" })
    try {
      setState({ kind: "loaded", ops: await fetchEventOps(eventId, auth) })
    } catch (error) {
      setState({ kind: "error", error: appErrorFromWp(error) })
    }
  }, [auth, eventId])

  useEffect(() => {
    void load()
  }, [load])

  const handleComplete = useCallback(
    async (task: EventTask) => {
      if (!auth) return
      setCompleting(task.id)
      try {
        await completeEventTask(task.id, auth)
        showToast("Task marked done.", "success")
        await load()
      } catch (error) {
        showToast(appErrorFromWp(error).message, "error")
      } finally {
        setCompleting(null)
      }
    },
    [auth, load],
  )

  if (!auth) return null
  if (state.kind === "loading") return <LoadingPanel label="Loading readiness and tasks…" />
  if (state.kind === "error") {
    return <ErrorPanel title="Readiness and tasks unavailable" message={state.error.message} onAction={() => void load()} />
  }
  if (state.ops.kind === "off") return null

  const { readiness, tasks, overdue } = state.ops
  return (
    <View style={styles.wrap}>
      {readiness ? (
        <ListGroup title={`Readiness ${readiness.percent}%`}>
          {readiness.nextActions.length > 0 ? (
            readiness.nextActions.map((label) => <ListRow key={label} icon="alert-circle-outline" title={label} />)
          ) : (
            <ListRow icon="checkmark-circle-outline" iconTone="accent" title="Everything on the checklist is done" />
          )}
        </ListGroup>
      ) : null}
      <ListGroup title={overdue > 0 ? `Open tasks · ${overdue} overdue` : "Open tasks"}>
        {tasks.length > 0 ? (
          tasks.map((task) => (
            <ListRow
              key={task.id}
              icon={task.overdue ? "alarm-outline" : "checkbox-outline"}
              iconTone={task.overdue ? "danger" : "default"}
              title={task.title}
              meta={[task.assignee, task.dueAt ? `Due ${task.dueAt.slice(0, 10)}` : null].filter(Boolean).join(" · ") || undefined}
              rightSlot={
                task.canComplete ? (
                  <Button
                    label={completing === task.id ? "Saving…" : "Done"}
                    size="sm"
                    variant="secondary"
                    disabled={completing === task.id}
                    onPress={() => void handleComplete(task)}
                  />
                ) : undefined
              }
            />
          ))
        ) : (
          <ListRow icon="checkmark-done-outline" iconTone="accent" title="No open tasks for this event" />
        )}
      </ListGroup>
      <Meta>Add tasks, assign people and confirm checklist items on LNKup web.</Meta>
    </View>
  )
}

const styles = StyleSheet.create({
  wrap: { gap: spacing.sm },
})
