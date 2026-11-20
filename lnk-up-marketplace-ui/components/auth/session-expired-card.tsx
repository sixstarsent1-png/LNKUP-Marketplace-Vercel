"use client"

import Link from "next/link"
import { usePathname } from "next/navigation"
import { LogIn } from "lucide-react"
import { Card, CardContent } from "@/components/ui/card"
import { Button } from "@/components/ui/button"

export function SessionExpiredCard({
  title = "Sign in to continue",
  description = "Use your LNKup account to access this section.",
}: {
  title?: string
  description?: string
}) {
  const pathname = usePathname()
  const loginHref = `/login?redirect=${encodeURIComponent(pathname || "/vendor")}`

  return (
    <Card className="rounded-2xl border-dashed" data-testid="session-expired-card">
      <CardContent className="flex flex-col items-center gap-4 py-8 text-center sm:py-10">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
          <LogIn className="size-6 text-muted-foreground" />
        </span>
        <div className="flex flex-col gap-1 px-4">
          <h2 className="font-heading text-lg font-semibold">{title}</h2>
          <p className="text-sm text-muted-foreground">{description}</p>
        </div>
        <Button nativeButton={false} render={<Link href={loginHref} />}>
          Sign in
        </Button>
      </CardContent>
    </Card>
  )
}
