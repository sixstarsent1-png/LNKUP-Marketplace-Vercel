import { ListGroup, ListRow } from "@/components/ui/list-row"
import { useAuth } from "@/lib/auth/auth-context"
import type { AppHomeRole } from "@/lib/auth/roles"
import { useSwitchRole } from "@/lib/hooks/use-account-actions"

const WORKSPACES: ReadonlyArray<{ role: AppHomeRole; title: string; meta: string }> = [
  { role: "vendor", title: "Switch to Vendor", meta: "Find events and manage your bookings" },
  { role: "organizer", title: "Switch to Organizer", meta: "Manage your events and vendors" },
  { role: "partner", title: "Switch to Partner", meta: "Referral links, leads and commissions" },
]

/** "Switch to …" rows for every other home this account can open. Renders nothing for single-role accounts. */
export function WorkspaceSwitchGroup({ current, title = "Workspace" }: { current: AppHomeRole; title?: string }) {
  const { isVendor, isOrganizer, isPartner } = useAuth()
  const [switchRole, switching] = useSwitchRole()
  const allowed: Record<AppHomeRole, boolean> = { vendor: isVendor, organizer: isOrganizer, partner: isPartner }
  const options = WORKSPACES.filter((w) => w.role !== current && allowed[w.role])

  if (options.length === 0) return null

  return (
    <ListGroup title={title}>
      {options.map((option) => (
        <ListRow
          key={option.role}
          icon="swap-horizontal-outline"
          iconTone="accent"
          title={option.title}
          meta={switching ? "Switching…" : option.meta}
          onPress={() => void switchRole(option.role)}
        />
      ))}
    </ListGroup>
  )
}
