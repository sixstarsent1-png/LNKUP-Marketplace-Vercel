import type { LucideIcon } from "lucide-react"
import { ChevronRight, LogOut } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Separator } from "@/components/ui/separator"

export type SettingsGroup = {
  title: string
  items: { icon: LucideIcon; label: string; hint?: string }[]
}

export function SettingsMenu({ groups }: { groups: SettingsGroup[] }) {
  return (
    <div className="flex flex-col gap-4">
      {groups.map((group) => (
        <Card key={group.title}>
          <CardHeader>
            <CardTitle className="text-base">{group.title}</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-1 pb-2">
            {group.items.map((item) => (
              <button
                key={item.label}
                className="flex items-center gap-3 rounded-lg px-2 py-3 text-left text-sm transition-colors hover:bg-secondary"
              >
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                  <item.icon className="size-4 text-muted-foreground" />
                </span>
                <span className="flex flex-1 flex-col">
                  <span className="font-medium">{item.label}</span>
                  {item.hint && <span className="text-xs text-muted-foreground">{item.hint}</span>}
                </span>
                <ChevronRight className="size-4 text-muted-foreground" />
              </button>
            ))}
          </CardContent>
        </Card>
      ))}
      <Card>
        <CardContent className="py-2">
          <button className="flex w-full items-center gap-3 rounded-lg px-2 py-3 text-left text-sm text-destructive transition-colors hover:bg-destructive/10">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-destructive/10">
              <LogOut className="size-4" />
            </span>
            <span className="flex-1 font-medium">Sign out</span>
          </button>
        </CardContent>
      </Card>
    </div>
  )
}
