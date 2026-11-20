import { AppShell } from "@/components/app-shell"
import { FavoritesProvider } from "@/lib/hooks/use-favorites"
import { VendorGate } from "@/components/vendor/vendor-gate"

export default function VendorLayout({ children }: { children: React.ReactNode }) {
  return (
    <FavoritesProvider>
      <AppShell role="vendor">
        <VendorGate>{children}</VendorGate>
      </AppShell>
    </FavoritesProvider>
  )
}
