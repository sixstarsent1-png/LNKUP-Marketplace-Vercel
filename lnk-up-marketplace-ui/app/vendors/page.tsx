import Link from "next/link"
import { Zap, ArrowRight, CheckCircle2 } from "lucide-react"
import { vendorCategories } from "@/lib/data"
import { getVendorLoginUrl, getWpRegistrationUrl } from "@/lib/api/config"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { VendorsCtaTracker } from "@/components/marketing/vendors-cta-tracker"

const STEPS = [
  "Create your free vendor profile",
  "Find events near you",
  "Apply for free",
  "Pay only when accepted",
  "Unlock event-day details",
]

const TRUST_LINES = [
  "Stop chasing opportunities through DMs and Facebook groups.",
  "One profile. More event opportunities.",
  "Organizers can review you faster when your profile is complete.",
]

export default function VendorsLandingPage() {
  const registrationUrl = getWpRegistrationUrl({
    role: "vendor",
    redirect: "/vendor/onboarding",
  })
  const loginUrl = getVendorLoginUrl({ intent: "vendor", redirect: "/vendor/onboarding" })

  return (
    <div className="min-h-svh bg-background">
      <VendorsCtaTracker />
      <header className="border-b px-4 py-4">
        <div className="mx-auto flex max-w-5xl items-center justify-between">
          <Link href="/" className="flex items-center gap-2">
            <span className="flex size-9 items-center justify-center rounded-xl bg-primary">
              <Zap className="size-4 text-accent" fill="currentColor" />
            </span>
            <span className="font-heading text-lg font-bold">LNKup</span>
          </Link>
          <Button variant="outline" size="sm" nativeButton={false} render={<Link href={loginUrl} />}>
            Sign in
          </Button>
        </div>
      </header>

      <main className="mx-auto flex max-w-5xl flex-col gap-16 px-4 py-12 md:py-16">
        <section className="flex flex-col items-center gap-6 text-center" data-testid="vendors-hero">
          <h1 className="max-w-2xl font-heading text-4xl font-bold tracking-tight md:text-5xl">
            Find event opportunities. Apply for free.
          </h1>
          <p className="max-w-xl text-lg text-muted-foreground">
            Pay only if accepted. Join early and be ready for launch.
          </p>
          <div className="flex flex-wrap justify-center gap-3">
            <Button size="lg" nativeButton={false} render={<a href={registrationUrl} data-testid="join-vendor-cta" />}>
              Join as a Vendor
              <ArrowRight data-icon="inline-end" />
            </Button>
            <Button
              size="lg"
              variant="outline"
              nativeButton={false}
              render={<Link href="/vendor/find" data-testid="browse-opportunities-cta" />}
            >
              Browse opportunities
            </Button>
          </div>
          <p className="text-sm text-muted-foreground">
            Already have an account?{" "}
            <Link href={loginUrl} className="font-medium text-foreground underline">
              Sign in
            </Link>
          </p>
        </section>

        <section>
          <h2 className="mb-6 text-center font-heading text-2xl font-semibold">How it works</h2>
          <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {STEPS.map((step, i) => (
              <li key={step}>
                <Card className="h-full rounded-2xl">
                  <CardContent className="flex flex-col gap-2 py-5">
                    <span className="text-sm font-semibold text-accent-foreground">
                      {i + 1}
                    </span>
                    <span className="text-sm font-medium">{step}</span>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ol>
        </section>

        <section>
          <h2 className="mb-6 text-center font-heading text-2xl font-semibold">Vendor categories</h2>
          <div className="flex flex-wrap justify-center gap-2">
            {vendorCategories.map((cat) => (
              <span
                key={cat}
                className="rounded-full border bg-card px-4 py-2 text-sm font-medium"
              >
                {cat}
              </span>
            ))}
          </div>
        </section>

        <section className="rounded-2xl bg-muted/50 px-6 py-10">
          <ul className="mx-auto flex max-w-2xl flex-col gap-4">
            {TRUST_LINES.map((line) => (
              <li key={line} className="flex items-start gap-3 text-pretty">
                <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-accent-foreground" />
                <span>{line}</span>
              </li>
            ))}
          </ul>
        </section>

        <section className="rounded-2xl bg-primary px-6 py-10 text-center text-primary-foreground">
          <h2 className="font-heading text-2xl font-bold">Your next booth opportunity is waiting.</h2>
          <p className="mt-2 text-primary-foreground/80">
            Browse opportunities now. Apply when the marketplace opens.
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Button
              size="lg"
              variant="secondary"
              nativeButton={false}
              render={<a href={registrationUrl} />}
            >
              Join as a Vendor
            </Button>
            <Button
              size="lg"
              variant="outline"
              className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
              nativeButton={false}
              render={<Link href="/vendor/find" />}
            >
              Browse opportunities
            </Button>
          </div>
        </section>
      </main>
    </div>
  )
}
