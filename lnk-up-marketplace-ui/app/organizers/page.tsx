import Link from "next/link"
import { Zap, ArrowRight, CheckCircle2 } from "lucide-react"
import { getOrganizerDashboardUrl, getWpRegistrationUrl } from "@/lib/api/config"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { OrganizersCtaTracker } from "@/components/marketing/organizers-cta-tracker"
import { OrganizersJoinCta } from "@/components/marketing/organizers-join-cta"

const STEPS = [
  "Create or import your event",
  "Add vendor opportunities",
  "Review vendor requests",
  "Approve, waitlist, or reject vendors",
  "Vendors pay only once accepted",
  "Release vendor packet details",
]

const VALUE_LINES = [
  "Fewer vendor DMs",
  "Cleaner application review",
  "Payment tracking",
  "Document readiness",
  "Load-in and packet organization",
]

const PROBLEM =
  "Stop managing vendors through DMs, Google Forms, spreadsheets, and Cash App."

export default function OrganizersLandingPage() {
  const joinUrl = getWpRegistrationUrl({
    role: "organizer",
    redirect: "/organizer/onboarding",
  })
  const loginUrl = "/login?intent=organizer&redirect=/organizer"
  const wpDash = getOrganizerDashboardUrl()

  return (
    <div className="min-h-svh bg-background">
      <OrganizersCtaTracker />
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
        <section className="flex flex-col items-center gap-6 text-center" data-testid="organizers-hero">
          <h1 className="max-w-2xl font-heading text-4xl font-bold tracking-tight md:text-5xl">
            Find vendors for your next event.
          </h1>
          <p className="max-w-xl text-lg text-muted-foreground">
            Post opportunities, review vendors, collect payments, and manage event-day logistics.
          </p>
          <div className="flex flex-wrap justify-center gap-3">
            <OrganizersJoinCta href={joinUrl} />
            <Button
              size="lg"
              variant="outline"
              nativeButton={false}
              render={<Link href="#how-it-works" data-testid="see-how-it-works-cta" />}
            >
              See how it works
            </Button>
          </div>
          <p className="text-sm text-muted-foreground">
            Vendors apply for free. Payment is only required once accepted.
          </p>
        </section>

        <section className="rounded-2xl bg-muted/50 px-6 py-8 text-center">
          <p className="text-pretty text-lg font-medium">{PROBLEM}</p>
        </section>

        <section id="how-it-works">
          <h2 className="mb-6 text-center font-heading text-2xl font-semibold">How it works</h2>
          <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {STEPS.map((step, i) => (
              <li key={step}>
                <Card className="h-full rounded-2xl">
                  <CardContent className="flex flex-col gap-2 py-5">
                    <span className="text-sm font-semibold text-accent-foreground">{i + 1}</span>
                    <span className="text-sm font-medium">{step}</span>
                  </CardContent>
                </Card>
              </li>
            ))}
          </ol>
        </section>

        <section>
          <h2 className="mb-6 text-center font-heading text-2xl font-semibold">Why organizers use LNKup</h2>
          <ul className="mx-auto flex max-w-2xl flex-col gap-4">
            {VALUE_LINES.map((line) => (
              <li key={line} className="flex items-start gap-3">
                <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-accent-foreground" />
                <span>{line}</span>
              </li>
            ))}
          </ul>
        </section>

        <section className="rounded-2xl bg-primary px-6 py-10 text-center text-primary-foreground">
          <h2 className="font-heading text-2xl font-bold">Launch your first vendor opportunity.</h2>
          <p className="mt-2 text-primary-foreground/80">
            Start in the app or finish advanced setup on LNKup web when you need it.
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Button
              size="lg"
              variant="secondary"
              nativeButton={false}
              render={<Link href="/organizer/launch" />}
            >
              Launch Event
            </Button>
            {wpDash && (
              <Button
                size="lg"
                variant="outline"
                className="border-primary-foreground/30 bg-transparent text-primary-foreground hover:bg-primary-foreground/10"
                nativeButton={false}
                render={
                  <a href={wpDash} target="_blank" rel="noopener noreferrer">
                    Open LNKup Dashboard
                  </a>
                }
              />
            )}
          </div>
        </section>
      </main>
    </div>
  )
}
