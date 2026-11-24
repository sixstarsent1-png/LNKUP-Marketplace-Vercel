import { isLiveApiEnabled } from "@/lib/api/config"
import { WpFetchError } from "@/lib/api/errors"
import type { BuilderDraftPayload } from "@/lib/api/event-builder"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

/**
 * Import an event the organizer already lists elsewhere (lnkup/v1/organizer/import). WordPress
 * fetches the page safely and reads only public structured data; the app never fetches listings
 * itself and never sees provider credentials.
 */

export type ImportField = "name" | "date" | "end_date" | "start_time" | "end_time" | "venue_name" | "address" | "city" | "state" | "zip" | "description" | "image_url"

export type ImportPreview = {
  url: string
  status: "complete" | "partial" | "nothing" | "link_only" | "unreachable"
  message: string
  provider: { key: string; label: string; level: string; limitations: string }
  relation: { state: "none" | "own" | "other" | "claimed_other" | "unclaimed_listing" | "own_listing"; message: string; event_id: number; event_title?: string }
  fields: Partial<Record<ImportField, string>>
  provenance: Partial<Record<ImportField, string>>
  missing: Array<{ field: string; label: string }>
  warnings: string[]
  occurrences: Array<{ date: string; start_time: string; city: string; state: string }>
  duplicates: Array<{ event_id: number; title: string; date: string; city: string }>
}

export const IMPORT_FIELD_LABELS: ReadonlyArray<[ImportField, string]> = [
  ["name", "Event name"],
  ["date", "Date"],
  ["end_date", "End date"],
  ["start_time", "Starts"],
  ["end_time", "Ends"],
  ["venue_name", "Venue"],
  ["address", "Address"],
  ["city", "City"],
  ["state", "State"],
  ["zip", "ZIP"],
  ["description", "Description"],
]

const PROVIDERS: ReadonlyArray<[string, string]> = [
  ["posh.vip", "POSH"],
  ["eventbrite.", "Eventbrite"],
  ["eventeny.com", "Eventeny"],
  ["ticketleap.", "TicketLeap"],
  ["lu.ma", "Luma"],
  ["luma.com", "Luma"],
  ["tickettailor.com", "Ticket Tailor"],
  ["humanitix.com", "Humanitix"],
  ["facebook.com", "Facebook"],
]

/** Label for a pasted link (display only — WordPress decides what can be imported). */
export function providerLabel(url: string): string | null {
  const match = /^https?:\/\/([^/?#]+)/i.exec(url.trim())
  if (!match) return null
  const host = match[1].toLowerCase().replace(/^www\./, "")
  const found = PROVIDERS.find(([needle]) => (needle.endsWith(".") ? host.startsWith(needle) || host.includes(`.${needle}`) : host === needle || host.endsWith(`.${needle}`)))
  return found ? found[1] : "Event website"
}

/** Conflicts that block creating a new LNKup event from this link. */
export function importBlocked(preview: ImportPreview): boolean {
  return ["other", "claimed_other", "own", "own_listing"].includes(preview.relation.state)
}

/** Fields found on the listing that the organizer can keep. */
export function keepableFields(preview: ImportPreview): ImportField[] {
  return IMPORT_FIELD_LABELS.map(([key]) => key).filter((key) => Boolean(preview.fields[key]))
}

function requireLive(auth: WpAuth | undefined): asserts auth is WpAuth {
  if (!isLiveApiEnabled()) throw new WpFetchError("Connect to LNKup to import events.", "wordpress_unavailable", 0)
  if (!auth) throw new WpFetchError("Sign in as an organizer to continue.", "auth_required", 401)
}

export async function previewImport(auth: WpAuth | undefined, url: string): Promise<ImportPreview> {
  requireLive(auth)
  return wpFetchJson<ImportPreview>("/organizer/import/preview", { method: "POST", auth, body: { url: url.trim() }, timeoutMs: 30_000 })
}

export async function createImportDraft(
  auth: WpAuth | undefined,
  input: { url: string; keep: ImportField[]; useImage: boolean; occurrence?: number },
): Promise<BuilderDraftPayload & { warnings?: string[] }> {
  requireLive(auth)
  return wpFetchJson("/organizer/import/draft", {
    method: "POST",
    auth,
    timeoutMs: 30_000,
    body: { url: input.url, keep: input.keep, use_image: input.useImage, occurrence: input.occurrence ?? -1 },
  })
}
