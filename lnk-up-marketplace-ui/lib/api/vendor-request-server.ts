import { wpFetchJson } from "@/lib/api/wp-fetch"
import {
  mapVendorRequestList,
  mapWpVendorApplication,
  type VendorRequest,
} from "@/lib/api/vendor-requests"
import type { WpVendorApplicationsResponse } from "@/lib/api/types"
import type { WpAuth } from "@/lib/api/wp-fetch"

export { vendorRequestAuthError } from "@/lib/api/normalize-wp-error"

export async function fetchVendorRequestById(
  auth: WpAuth,
  applicationId: string,
): Promise<VendorRequest | null> {
  const data = await wpFetchJson<WpVendorApplicationsResponse>("/applications/vendor", { auth })
  const items = mapVendorRequestList(data)
  return items.find((item) => item.id === applicationId) ?? null
}

export function mapSingleVendorApplication(
  auth: WpAuth,
  applicationId: string,
  item: Parameters<typeof mapWpVendorApplication>[0],
): VendorRequest {
  return mapWpVendorApplication({
    ...item,
    application_id: Number(applicationId) || item.application_id,
    id: Number(applicationId) || item.id,
  })
}
