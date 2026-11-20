import Link from "next/link"
import { Zap, Search } from "lucide-react"
import { PageHeader } from "@/components/marketplace/page-header"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"

export default function NotFound() {
  return (
    <div className="flex min-h-svh flex-col items-center justify-center bg-background px-4 py-12">
      <div className="flex w-full max-w-md flex-col gap-8">
        <div className="flex flex-col items-center gap-3 text-center">
          <Link href="/" className="flex items-center gap-2">
            <span className="flex size-10 items-center justify-center rounded-xl bg-primary">
              <Zap className="size-5 text-accent" fill="currentColor" />
            </span>
          </Link>
          <PageHeader title="Page not found" subtitle="This route doesn't exist in the LNKup app." />
        </div>

        <Card className="rounded-2xl border-dashed">
          <CardContent className="flex flex-col items-center gap-4 py-8 text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-muted">
              <Search className="size-6 text-muted-foreground" />
            </span>
            <p className="text-sm text-muted-foreground">
              Check the URL or pick a dashboard to continue.
            </p>
            <div className="flex flex-wrap justify-center gap-2">
              <Button nativeButton={false} render={<Link href="/vendor" />}>
                Vendor dashboard
              </Button>
              <Button variant="outline" nativeButton={false} render={<Link href="/organizer" />}>
                Organizer dashboard
              </Button>
              <Button variant="ghost" nativeButton={false} render={<Link href="/" />}>
                Role picker
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  )
}
