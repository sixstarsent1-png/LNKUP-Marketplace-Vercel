import { AppShell } from "@/components/app-shell"

export default function OrganizerLayout({ children }: { children: React.ReactNode }) {
  return <AppShell role="organizer">{children}</AppShell>
}
