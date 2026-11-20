"use client"

import Link from "next/link"
import { Check, Circle } from "lucide-react"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Progress } from "@/components/ui/progress"
import { cn } from "@/lib/utils"

export type ChecklistItem = {
  label: string
  done: boolean
  href?: string
}

export function LaunchChecklist({
  title = "Launch checklist",
  items,
}: {
  title?: string
  items: ChecklistItem[]
}) {
  const done = items.filter((i) => i.done).length
  const pct = Math.round((done / items.length) * 100)

  return (
    <Card className="rounded-2xl">
      <CardHeader>
        <CardTitle className="flex items-center justify-between text-base">
          {title}
          <span className="text-sm font-semibold text-muted-foreground">
            {done}/{items.length}
          </span>
        </CardTitle>
        <Progress value={pct} className="mt-2" />
      </CardHeader>
      <CardContent className="flex flex-col gap-1">
        {items.map((item) => (
          <div
            key={item.label}
            className="flex items-center gap-3 rounded-xl py-2"
          >
            <span
              className={cn(
                "flex size-6 shrink-0 items-center justify-center rounded-full",
                item.done ? "bg-success text-primary-foreground" : "bg-muted text-muted-foreground",
              )}
            >
              {item.done ? <Check className="size-3.5" /> : <Circle className="size-3" />}
            </span>
            {!item.done && item.href ? (
              <Link
                href={item.href}
                className={cn("text-sm font-medium text-foreground underline-offset-2 hover:underline")}
              >
                {item.label}
              </Link>
            ) : (
              <span
                className={cn(
                  "text-sm",
                  item.done ? "text-muted-foreground line-through" : "font-medium text-foreground",
                )}
              >
                {item.label}
              </span>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  )
}
