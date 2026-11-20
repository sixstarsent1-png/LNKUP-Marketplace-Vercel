"use client"

import { ExternalLink } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"

export function AdminHandoffCard({
  title = "WordPress handoff",
  description,
  href,
  cta = "Open in WordPress",
  testId = "admin-handoff-card",
}: {
  title?: string
  description: string
  href?: string
  cta?: string
  testId?: string
}) {
  if (!href) return null

  return (
    <Card className="rounded-2xl border-dashed" data-testid={testId}>
      <CardHeader className="pb-2">
        <CardTitle className="text-base">{title}</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        <p className="text-sm text-muted-foreground">{description}</p>
        <Button
          variant="outline"
          size="sm"
          className="w-fit"
          nativeButton={false}
          render={<a href={href} target="_blank" rel="noopener noreferrer" />}
        >
          <ExternalLink data-icon="inline-start" />
          {cta}
        </Button>
      </CardContent>
    </Card>
  )
}
