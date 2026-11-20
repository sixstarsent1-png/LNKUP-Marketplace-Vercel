"use client"

import Link from "next/link"
import { Store, ArrowRight } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export function OrganizerRoleGate({ variant = "forbidden" }: { variant?: "forbidden" | "unauthenticated" }) {
  const isAuth = variant === "unauthenticated"

  return (
    <Card className="rounded-2xl border-dashed" data-testid="organizer-role-gate">
      <CardContent className="flex flex-col items-center gap-4 py-10 text-center">
        <span className="flex size-12 items-center justify-center rounded-full bg-muted">
          <Store className="size-6 text-muted-foreground" />
        </span>
        <div className="flex flex-col gap-1">
          <h2 className="font-heading text-lg font-semibold">
            {isAuth ? "Sign in to continue" : "This section is for organizers"}
          </h2>
          <p className="max-w-sm text-sm text-muted-foreground">
            {isAuth
              ? "Use your LNKup account credentials or app password to continue."
              : "This section is for organizers. Go to the vendor dashboard or sign in with an organizer account."}
          </p>
        </div>
        <div className="flex flex-wrap justify-center gap-2">
          {isAuth ? (
            <Button nativeButton={false} render={<Link href="/login?intent=organizer&redirect=/organizer" />}>
              Sign in
            </Button>
          ) : (
            <>
              <Button variant="outline" nativeButton={false} render={<Link href="/vendor" />}>
                Go to Vendor Dashboard
                <ArrowRight data-icon="inline-end" />
              </Button>
              <Button variant="ghost" nativeButton={false} render={<Link href="/login?intent=organizer&redirect=/organizer" />}>
                Sign in as organizer
              </Button>
            </>
          )}
        </div>
      </CardContent>
    </Card>
  )
}
