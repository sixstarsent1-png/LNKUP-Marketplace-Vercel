"use client"

import { useEffect, useState } from "react"
import Link from "next/link"
import { MapPin, ArrowRight, Zap } from "lucide-react"
import type { MarketDefinition } from "@/lib/api/markets"
import type { MarketPageData } from "@/lib/api/market-page"
import { buildMockMarketPage } from "@/lib/api/market-page"
import { isLiveApiEnabled, getWpRegistrationUrl } from "@/lib/api/config"
import { parseApiResponse } from "@/lib/api/parse-api-error"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { EmptyState, ErrorState, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { trackLnkupEvent } from "@/lib/analytics/track-event"

export function MarketPageClient({ slug, market }: { slug: string; market: MarketDefinition }) {
  const [data, setData] = useState<MarketPageData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    trackLnkupEvent({ event: "market_page_viewed", source: `market_${slug}`, metadata: { city: market.city } })
    async function load() {
      if (!isLiveApiEnabled()) {
        setData(buildMockMarketPage(market))
        setLoading(false)
        return
      }
      try {
        const response = await fetch(`/api/marketplace/markets/${slug}`)
        const { data: payload, error: apiError } = await parseApiResponse<MarketPageData>(response)
        if (apiError) throw new Error(apiError.message)
        setData(payload)
      } catch (err) {
        setError(err instanceof Error ? err.message : "Could not load market")
      } finally {
        setLoading(false)
      }
    }
    load()
  }, [slug, market])

  const registrationUrl = getWpRegistrationUrl({
    role: "vendor",
    redirect: "/vendor/onboarding",
  })
  const findUrl = `/vendor/find?city=${encodeURIComponent(market.city)}&state=${encodeURIComponent(market.state)}`
  const organizerUrl = process.env.NEXT_PUBLIC_WORDPRESS_SITE_URL
    ? `${process.env.NEXT_PUBLIC_WORDPRESS_SITE_URL}/organizer-dashboard/`
    : "/organizer"

  return (
    <div className="min-h-svh bg-background">
      <header className="border-b px-4 py-4">
        <div className="mx-auto flex max-w-5xl items-center gap-2">
          <Link href="/" className="flex items-center gap-2">
            <span className="flex size-9 items-center justify-center rounded-xl bg-primary">
              <Zap className="size-4 text-accent" fill="currentColor" />
            </span>
            <span className="font-heading text-lg font-bold">LNKup</span>
          </Link>
        </div>
      </header>

      <main className="mx-auto flex max-w-5xl flex-col gap-10 px-4 py-10">
        <section className="flex flex-col gap-3" data-testid="market-hero">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <MapPin className="size-4" />
            {market.region ?? market.state}
          </div>
          <h1 className="font-heading text-3xl font-bold tracking-tight md:text-4xl">
            Vendor opportunities in {market.displayName}
          </h1>
          <p className="text-muted-foreground">
            Search by city, category, or event type. Apply for free — pay only when accepted.
          </p>
          {data && data.total > 0 && (
            <p className="text-sm font-medium" data-testid="market-opportunity-count">
              {data.total} live opportunit{data.total === 1 ? "y" : "ies"} in {market.displayName}
            </p>
          )}
          <div className="flex flex-wrap gap-3 pt-2">
            <Button
              nativeButton={false}
              render={<a href={registrationUrl} />}
              onClick={() =>
                trackLnkupEvent({
                  event: "landing_cta_clicked",
                  source: `market_${slug}`,
                  metadata: { cta: "join" },
                })
              }
            >
              Join as a Vendor
            </Button>
            <Button
              variant="outline"
              nativeButton={false}
              render={<Link href={findUrl} />}
              onClick={() =>
                trackLnkupEvent({ event: "browse_opportunities_clicked", source: `market_${slug}` })
              }
            >
              Browse {market.displayName} opportunities
              <ArrowRight data-icon="inline-end" />
            </Button>
            <Button
              variant="outline"
              nativeButton={false}
              render={<Link href={organizerUrl} />}
            >
              Post vendor opportunities in {market.displayName}
            </Button>
          </div>
        </section>

        {loading && <SkeletonList count={2} />}
        {error && <ErrorState description={error} />}
        {!loading && !error && data && (
          <>
            {data.prelaunchMessage && (
              <p className="rounded-xl bg-muted px-4 py-3 text-sm text-muted-foreground">
                {data.prelaunchMessage}
              </p>
            )}

            {data.categories.length > 0 && (
              <section>
                <h2 className="mb-3 font-heading text-lg font-semibold">Active categories</h2>
                <div className="flex flex-wrap gap-2">
                  {data.categories.map((cat) => (
                    <span key={cat} className="rounded-full border px-3 py-1 text-sm">
                      {cat}
                    </span>
                  ))}
                </div>
              </section>
            )}

            {data.opportunities.length > 0 ? (
              <section>
                <h2 className="mb-4 font-heading text-lg font-semibold">Live opportunities</h2>
                <div className="grid gap-4 md:grid-cols-2">
                  {data.opportunities.map((e) => (
                    <OpportunityCard key={e.id} event={e} />
                  ))}
                </div>
              </section>
            ) : (
              <EmptyState
                icon={MapPin}
                title="No live opportunities yet"
                description={`Join early in ${market.displayName} and be ready when events open.`}
                action={
                  <Button nativeButton={false} render={<Link href={findUrl} />}>
                    Browse all opportunities
                  </Button>
                }
              />
            )}
          </>
        )}
      </main>
    </div>
  )
}
