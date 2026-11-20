import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type AccountDeletionResult = {
  success: boolean
  message: string
  grace_days?: number
  scheduled_for?: string
  signed_out_required?: boolean
}

/**
 * Initiate account deletion (App Store Guideline 5.1.1(v)).
 * Requires confirm === "DELETE".
 */
export async function requestAccountDeletion(
  auth: WpAuth | undefined,
  confirm: string,
): Promise<AccountDeletionResult> {
  return wpFetchJson<AccountDeletionResult>("/account/delete", {
    method: "POST",
    auth,
    body: { confirm },
  })
}
