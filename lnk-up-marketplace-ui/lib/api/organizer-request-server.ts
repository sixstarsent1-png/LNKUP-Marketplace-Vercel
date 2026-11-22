import { WpFetchError, wpFetchJson } from "@/lib/api/wp-fetch"
import {
  mapOrganizerRequestList,
  type OrganizerVendorRequest,
  type WpOrganizerApplicationItem,
} from "@/lib/api/organizer-requests"
import type { WpAuth } from "@/lib/api/wp-fetch"

export { organizerAuthError } from "@/lib/api/normalize-wp-error"

export async function fetchOrganizerRequestById(
  auth: WpAuth,
  applicationId: string,
): Promise<OrganizerVendorRequest | null> {
  const data = await wpFetchJson<{ items?: WpOrganizerApplicationItem[]; total?: number }>("/applications/organizer", { auth })
  const items = mapOrganizerRequestList(data)
  return items.find((item) => item.id === applicationId) ?? null
}
