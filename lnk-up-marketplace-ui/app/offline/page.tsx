"use client"

import Link from "next/link"
import { WifiOff } from "lucide-react"
import { PageHeader } from "@/components/marketplace/page-header"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export default function OfflinePage() {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-12">
      <div className="flex w-full max-w-md flex-col gap-8">
        <div className="flex flex-col items-center gap-3 text-center">
          <PageHeader
            title="You're offline"
            subtitle="LNKup needs a network connection to load events and requests."
          />
        </div>

        <Card className="rounded-2xl border-dashed">
          <CardContent className="flex flex-col items-center gap-4 py-8 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-muted">
              <WifiOff className="size-6 text-muted-foreground" />
            </span>
            <p className="text-sm text-muted-foreground">
              Reconnect and try again. Your LNKup account data stays safe on thelnkup.com.
            </p>
            <div className="flex flex-wrap justify-center gap-2">
              <Button onClick={() => window.location.reload()}>Retry</Button>
              <Button variant="outline" nativeButton={false} render={<Link href="/" />}>
                Home
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
