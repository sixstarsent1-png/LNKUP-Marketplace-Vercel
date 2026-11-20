"use client"

import { Inbox } from "lucide-react"
import { EmptyState } from "@/components/marketplace/states"

export function NotificationEmpty() {
  return (
    <div data-testid="notifications-empty">
      <EmptyState
        icon={Inbox}
        title="You're all caught up"
        description="New updates about applications, payments, and events will appear here."
      />
    </div>
  )
}
