import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type PushCategoryKey =
  | "applications"
  | "payments"
  | "packets"
  | "discovery"
  | "account"

export type PushCategories = Record<PushCategoryKey, boolean>

export type PushPreferences = {
  pushEnabled: boolean
  categories: PushCategories
}

export const DEFAULT_PUSH_CATEGORIES: PushCategories = {
  applications: true,
  payments: true,
  packets: true,
  discovery: true,
  account: true,
}

export const PUSH_CATEGORY_LABELS: Record<PushCategoryKey, string> = {
  applications: "Applications",
  payments: "Payments",
  packets: "Packets & pass",
  discovery: "Discovery alerts",
  account: "Account & ops",
}

let mockPushEnabled = true
let mockCategories: PushCategories = { ...DEFAULT_PUSH_CATEGORIES }

function normalizeCategories(raw?: Partial<PushCategories> | null): PushCategories {
  return {
    applications: raw?.applications !== false,
    payments: raw?.payments !== false,
    packets: raw?.packets !== false,
    discovery: raw?.discovery !== false,
    account: raw?.account !== false,
  }
}

export async function fetchPushPreferences(auth?: WpAuth): Promise<PushPreferences> {
  if (!isLiveApiEnabled() || !auth) {
    return { pushEnabled: mockPushEnabled, categories: { ...mockCategories } }
  }

  const data = await wpFetchJson<{
    pushEnabled?: boolean
    categories?: Partial<PushCategories>
  }>("/push/preferences", { auth })
  return {
    pushEnabled: data.pushEnabled !== false,
    categories: normalizeCategories(data.categories),
  }
}

export async function updatePushPreferences(
  input: { pushEnabled?: boolean; categories?: Partial<PushCategories> },
  auth?: WpAuth,
): Promise<PushPreferences> {
  if (!isLiveApiEnabled() || !auth) {
    if (typeof input.pushEnabled === "boolean") {
      mockPushEnabled = input.pushEnabled
    }
    if (input.categories) {
      mockCategories = normalizeCategories({ ...mockCategories, ...input.categories })
    }
    return { pushEnabled: mockPushEnabled, categories: { ...mockCategories } }
  }

  const body: Record<string, unknown> = {}
  if (typeof input.pushEnabled === "boolean") {
    body.pushEnabled = input.pushEnabled
  }
  if (input.categories) {
    body.categories = input.categories
  }

  const data = await wpFetchJson<{
    pushEnabled?: boolean
    categories?: Partial<PushCategories>
  }>("/push/preferences", {
    method: "PATCH",
    auth,
    body,
  })
  return {
    pushEnabled: data.pushEnabled !== false,
    categories: normalizeCategories(data.categories),
  }
}

export async function registerPushDevice(
  input: { token: string; platform: string; appVersion?: string },
  auth?: WpAuth,
): Promise<void> {
  if (!isLiveApiEnabled() || !auth) {
    return
  }

  await wpFetchJson("/push/devices", {
    method: "POST",
    auth,
    body: {
      token: input.token,
      platform: input.platform,
      app_version: input.appVersion ?? "",
    },
  })
}

export async function unregisterPushDevice(token: string, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled() || !auth || !token) {
    return
  }

  await wpFetchJson("/push/devices", {
    method: "DELETE",
    auth,
    body: { token },
    query: { token },
  })
}
