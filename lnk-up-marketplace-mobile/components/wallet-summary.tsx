import { StatGrid, StatTile } from "@/components/ui/stat-tile"
import {
  formatMoneyOrZero,
  type VendorWalletSummary,
} from "@/lib/api/vendor-wallet"

export function WalletSummary({ summary }: { summary: VendorWalletSummary }) {
  const currency = summary.currency ?? "USD"
  const hasDue = (summary.amountDue ?? 0) > 0

  return (
    <StatGrid>
      <StatTile
        icon="card-outline"
        label="Amount due"
        value={formatMoneyOrZero(summary.amountDue, currency)}
        tone={hasDue ? "urgent" : "accent"}
      />
      <StatTile
        icon="checkmark-circle-outline"
        label="Paid"
        value={formatMoneyOrZero(summary.totalPaid, currency)}
      />
      <StatTile
        icon="gift-outline"
        label="Credits"
        value={formatMoneyOrZero(summary.availableCredits, currency)}
      />
    </StatGrid>
  )
}
