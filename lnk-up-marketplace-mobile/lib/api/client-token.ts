/**
 * Idempotency key for create/money actions. Keep one per user intent and reuse it on retry
 * so WordPress returns the original record instead of creating a duplicate.
 */
export function newClientToken(): string {
  const random = () => Math.random().toString(36).slice(2, 10)
  return `app-${Date.now().toString(36)}-${random()}${random()}`
}
