"use client"

import { Alert, AlertDescription } from "@/components/ui/alert"

export function DemoModeNotice({
  children = "Demo sample data — not live marketplace totals. Use Admin Lite cards above or LNKup web for production ops.",
  testId = "demo-mode-notice",
}: {
  children?: React.ReactNode
  testId?: string
}) {
  return (
    <Alert data-testid={testId}>
      <AlertDescription>{children}</AlertDescription>
    </Alert>
  )
}
