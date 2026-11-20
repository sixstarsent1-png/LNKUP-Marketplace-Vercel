"use client"

import Link from "next/link"
import { LaunchChecklist } from "@/components/marketplace/launch-checklist"
import { Skeleton } from "@/components/ui/skeleton"
import { Button } from "@/components/ui/button"
import { useProfileChecklist } from "@/lib/hooks/use-profile-checklist"
import { ErrorState } from "@/components/marketplace/states"

export function ProfileCompletionChecklist() {
  const { items, loading, error, retry } = useProfileChecklist()

  if (loading) {
    return (
      <div className="flex flex-col gap-2" data-testid="profile-completion-checklist">
        <Skeleton className="h-32 w-full rounded-2xl" />
      </div>
    )
  }

  if (error) {
    return (
      <ErrorState
        title="Could not load checklist"
        description={error}
        onRetry={retry}
      />
    )
  }

  return (
    <div data-testid="profile-completion-checklist">
      <LaunchChecklist
        title="Profile completion"
        items={items.map((item) => ({
          label: item.description ? `${item.label} — ${item.description}` : item.label,
          done: item.done,
        }))}
      />
      <p className="mt-2 text-xs text-muted-foreground">
        Organizers can approve you faster when your profile is complete.
      </p>
      {items.some((i) => !i.done && i.actionHref) && (
        <Button
          variant="outline"
          size="sm"
          className="mt-3"
          nativeButton={false}
          render={<Link href={items.find((i) => !i.done)?.actionHref || "/vendor/profile"} />}
        >
          Continue profile
        </Button>
      )}
    </div>
  )
}
