import Link from "next/link"
import type { LucideIcon } from "lucide-react"
import { ChevronRight } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { SignOutButton } from "@/components/auth/sign-out-button"

export type SettingsGroup = {
  title: string
  items: { icon: LucideIcon; label: string; hint?: string; href?: string }[]
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
            {group.items.map((item) => {
              const inner = (
                <>
                  <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <item.icon className="size-4 text-muted-foreground" />
                  </span>
                  <span className="flex flex-1 flex-col">
                    <span className="font-medium">{item.label}</span>
                    {item.hint && <span className="text-xs text-muted-foreground">{item.hint}</span>}
                  </span>
                  <ChevronRight className="size-4 text-muted-foreground" />
                </>
              )
              if (item.href) {
                return (
                  <Link
                    key={item.label}
                    href={item.href}
                    className="flex items-center gap-3 rounded-lg px-2 py-3 text-sm transition-colors hover:bg-secondary"
                  >
                    {inner}
                  </Link>
                )
              }
              return (
                <button
                  key={item.label}
                  type="button"
                  className="flex items-center gap-3 rounded-lg px-2 py-3 text-left text-sm transition-colors hover:bg-secondary"
                >
                  {inner}
                </button>
              )
            })}
          </CardContent>
        </Card>
      ))}
      <Card>
        <CardContent className="py-2">
          <SignOutButton className="flex w-full items-center gap-3 rounded-lg px-2 py-3 text-left text-sm text-destructive transition-colors hover:bg-destructive/10" />
        </CardContent>
      </Card>
    </div>
  )
}
