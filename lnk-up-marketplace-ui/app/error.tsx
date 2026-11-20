"use client"

import { useEffect } from "react"
import Link from "next/link"
import { Zap } from "lucide-react"
import { AppErrorPanel } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"

export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string }
  reset: () => void
}) {
  useEffect(() => {
    console.error("[app/error]", error)
  }, [error])

  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-12">
      <div className="mb-8 flex flex-col items-center gap-2">
        <span className="flex size-10 items-center justify-center rounded-xl bg-primary">
          <Zap className="size-5 text-accent" fill="currentColor" />
        </span>
        <p className="text-sm text-muted-foreground">LNKup Marketplace</p>
      </div>
      <div className="w-full max-w-md">
        <AppErrorPanel
          error={{
            code: "unknown",
            title: "Something went wrong",
            message: "We hit an unexpected error. You can try again or return home.",
            retryable: true,
          }}
          onRetry={reset}
        />
        <div className="mt-4 text-center">
          <Button variant="ghost" size="sm" nativeButton={false} render={<Link href="/" />}>
            Back to role picker
          </Button>
        </div>
      </div>
    </div>
  )
}
