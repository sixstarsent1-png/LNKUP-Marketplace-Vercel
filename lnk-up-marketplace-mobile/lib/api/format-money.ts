/**
 * Shared money display helpers (amounts are dollars from WP Financial OS).
 */

export function normalizeCurrency(currency?: string | null): string {
  const raw = String(currency ?? "USD").trim().toUpperCase()
  return raw || "USD"
}

/**
 * Format a dollar amount. Unknown → em dash. Never returns bare "$".
 */
export function formatMoney(amount?: number | null, currency = "USD"): string {
  if (amount == null || Number.isNaN(Number(amount))) return "—"
  const n = Number(amount)
  if (!Number.isFinite(n)) return "—"
  const code = normalizeCurrency(currency)
  try {
    const fraction = Number.isInteger(n) ? 0 : 2
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: code,
      minimumFractionDigits: fraction,
      maximumFractionDigits: 2,
    }).format(n)
  } catch {
    return `$${n.toFixed(2)}`
  }
}

/** Prefer `$0` when value is known zero; dash only when unknown. */
export function formatMoneyOrZero(amount: number | undefined | null, currency = "USD"): string {
  if (amount == null || Number.isNaN(Number(amount))) return "—"
  return formatMoney(Number(amount), currency)
}

/** Keep finite numbers including 0; skip missing/invalid. */
export function pickNumber(...values: unknown[]): number | undefined {
  for (const value of values) {
    if (value === undefined || value === null || value === "") continue
    const n = typeof value === "number" ? value : Number(value)
    if (Number.isFinite(n)) return n
  }
  return undefined
}
