import { AppShell } from "@/components/app-shell"
import { OrganizerGate } from "@/components/organizer/organizer-gate"
import { OrganizerHomeProvider } from "@/components/organizer/organizer-home-provider"

export default function OrganizerLayout({ children }: { children: React.ReactNode }) {
  return (
    <AppShell role="organizer">
      <OrganizerHomeProvider>
        <OrganizerGate>{children}</OrganizerGate>
      </OrganizerHomeProvider>
    </AppShell>
  )
}
