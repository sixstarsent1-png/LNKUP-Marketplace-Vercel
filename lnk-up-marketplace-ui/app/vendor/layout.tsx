import { AppShell } from "@/components/app-shell"

export default function VendorLayout({ children }: { children: React.ReactNode }) {
  return <AppShell role="vendor">{children}</AppShell>
}
