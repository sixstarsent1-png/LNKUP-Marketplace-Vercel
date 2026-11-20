/** "apply_clicked" → "Apply clicked". */
export function humanizeKey(value: string | null | undefined): string {
  const text = String(value ?? "")
    .replace(/[_-]+/g, " ")
    .replace(/\s+/g, " ")
    .trim()
  if (!text) return ""
  return text.charAt(0).toUpperCase() + text.slice(1).toLowerCase()
}

const EXPERIMENT_STATUS: Record<string, string> = {
  active: "Running",
  running: "Running",
  paused: "Paused",
  draft: "Not started",
  completed: "Finished",
  stopped: "Stopped",
}

export function experimentStatusLabel(status: string): string {
  return EXPERIMENT_STATUS[status.toLowerCase()] ?? humanizeKey(status)
}

const DECISION: Record<string, string> = {
  ship: "Keep the change",
  iterate: "Adjust and retest",
  stop: "Stop the test",
  pending: "Still collecting data",
}

export function decisionLabel(decision: string): string {
  return DECISION[decision.toLowerCase()] ?? humanizeKey(decision)
}

/** "city" + "charlotte" → "City: Charlotte". */
export function segmentLabel(type: string, value: string): string {
  const v = humanizeKey(value)
  return type ? `${humanizeKey(type)}: ${v}` : v
}
