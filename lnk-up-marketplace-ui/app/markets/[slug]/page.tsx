import { notFound } from "next/navigation"
import { getMarketBySlug } from "@/lib/api/markets"
import { MarketPageClient } from "./market-page-client"

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const market = getMarketBySlug(slug)
  if (!market) return { title: "Market not found · LNKup" }
  return {
    title: `Vendor opportunities in ${market.displayName} · LNKup`,
    description: `Find event opportunities in ${market.displayName}. Apply for free. Pay only if accepted.`,
  }
}

export default async function MarketPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params
  const market = getMarketBySlug(slug)
  if (!market) notFound()
  return <MarketPageClient slug={slug} market={market} />
}
