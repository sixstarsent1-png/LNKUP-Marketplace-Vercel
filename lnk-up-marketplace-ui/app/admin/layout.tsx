import { AppShell } from "@/components/app-shell"
import { notFound } from "next/navigation"
import { isOpsAnalyticsEnabled } from "@/lib/ops/gate"

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  if (!isOpsAnalyticsEnabled()) {
    notFound()
  }
  return <AppShell role="admin">{children}</AppShell>
}
