import { WpFetchError } from "@/lib/api/errors"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/** Event readiness and tasks for on-site staff (Organizer OS, `organizer_ops_v2_enabled`). */

export interface EventReadiness {
  percent: number
  nextActions: string[]
}

export interface EventTask {
  id: number
  title: string
  dueAt: string
  overdue: boolean
  priority: string
  assignee: string | null
  canComplete: boolean
}

export type EventOps =
  | { kind: "off" }
  | { kind: "ready"; readiness: EventReadiness | null; tasks: EventTask[]; overdue: number }

type WpReadiness = { percent?: number; next_actions?: Array<{ label?: string }> }
type WpTask = {
  id?: number
  title?: string
  due_at?: string
  overdue?: boolean
  priority?: string
  assignee?: { name?: string } | null
  can_complete?: boolean
}
type WpTasks = { items?: WpTask[]; counts?: { open?: number; overdue?: number } }

function isFeatureOff(error: unknown): boolean {
  return error instanceof WpFetchError && (error.status === 404 || error.code === "lnkup_feature_off")
}

export function mapEventTask(row: WpTask): EventTask {
  return {
    id: Number(row.id ?? 0),
    title: String(row.title ?? ""),
    dueAt: String(row.due_at ?? ""),
    overdue: Boolean(row.overdue),
    priority: String(row.priority ?? "normal"),
    assignee: row.assignee?.name ?? null,
    canComplete: Boolean(row.can_complete),
  }
}

/**
 * Readiness and open tasks for one event. `off` when the site does not run Organizer OS (or the
 * viewer cannot see this event's operations), so the screen hides the panel instead of erroring.
 */
export async function fetchEventOps(eventId: string, auth: WpAuth): Promise<EventOps> {
  let tasks: WpTasks
  try {
    tasks = await wpFetchJson<WpTasks>(`/events/${encodeURIComponent(eventId)}/tasks`, { auth, query: { status: "open" } })
  } catch (error) {
    if (isFeatureOff(error)) return { kind: "off" }
    throw error
  }
  let readiness: EventReadiness | null = null
  try {
    const raw = await wpFetchJson<WpReadiness>(`/events/${encodeURIComponent(eventId)}/readiness`, { auth })
    readiness = {
      percent: Number(raw.percent ?? 0),
      nextActions: (raw.next_actions ?? []).map((item) => String(item.label ?? "")).filter(Boolean),
    }
  } catch (error) {
    if (!isFeatureOff(error)) throw error
  }
  return {
    kind: "ready",
    readiness,
    tasks: (tasks.items ?? []).map(mapEventTask),
    overdue: Number(tasks.counts?.overdue ?? 0),
  }
}

export async function completeEventTask(taskId: number, auth: WpAuth): Promise<void> {
  await wpFetchJson(`/tasks/${taskId}`, { method: "PATCH", auth, body: { status: "done" } })
}
