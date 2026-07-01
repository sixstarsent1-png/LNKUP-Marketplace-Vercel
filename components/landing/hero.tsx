import Link from 'next/link'
import Image from 'next/image'
import { ArrowRight, MapPin, Star, BadgeCheck } from 'lucide-react'
import { Button } from '@/components/ui/button'

export function Hero() {
  return (
    <section className="relative overflow-hidden">
      <div className="pointer-events-none absolute inset-x-0 -top-40 h-[420px] bg-[radial-gradient(ellipse_at_top,theme(colors.accent/35%),transparent_70%)]" />
      <div className="mx-auto grid max-w-7xl items-center gap-12 px-4 pb-16 pt-14 sm:px-6 lg:grid-cols-[1.05fr_0.95fr] lg:gap-8 lg:px-8 lg:pb-24 lg:pt-20">
        <div className="flex flex-col items-start">
          <span className="inline-flex items-center gap-2 rounded-full border border-border bg-card px-3 py-1 text-xs font-medium text-muted-foreground">
            <span className="size-1.5 rounded-full bg-accent" />
            The opportunity network for live events
          </span>

          <h1 className="mt-5 text-pretty font-display text-5xl font-extrabold leading-[1.02] tracking-tight sm:text-6xl lg:text-7xl">
            Find Your Next Event
          </h1>

          <p className="mt-5 max-w-xl text-pretty text-lg leading-relaxed text-muted-foreground">
            LNKup helps vendors discover festivals, rodeos, block parties,
            homecomings, markets, food festivals, and cultural events across the
            country.
          </p>

          <div className="mt-8 flex flex-col gap-3 sm:flex-row">
            <Button
              size="lg"
              className="h-12 px-6 text-base"
              render={<Link href="/dashboard/find-events" />}
              nativeButton={false}
            >
              Find Events
              <ArrowRight data-icon="inline-end" />
            </Button>
            <Button
              size="lg"
              variant="outline"
              className="h-12 px-6 text-base"
              render={<Link href="/dashboard/profile" />}
              nativeButton={false}
            >
              List My Business
            </Button>
          </div>

          <div className="mt-10 flex flex-wrap items-center gap-x-8 gap-y-3 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-2">
              <BadgeCheck className="size-4 text-accent" /> Free to join &amp; apply
            </span>
            <span className="inline-flex items-center gap-2">
              <Star className="size-4 text-accent" /> Pay only when accepted
            </span>
            <span className="inline-flex items-center gap-2">
              <MapPin className="size-4 text-accent" /> Events in 40+ cities
            </span>
          </div>
        </div>

        <div className="relative">
          <div className="overflow-hidden rounded-3xl border border-border bg-card shadow-2xl shadow-foreground/5">
            <Image
              src="/events/block-party.png"
              alt="A vibrant outdoor block party with vendor booths and a lively crowd"
              width={720}
              height={560}
              priority
              className="h-[300px] w-full object-cover sm:h-[420px]"
            />
          </div>

          <div className="absolute -bottom-5 -left-4 hidden w-56 rounded-2xl border border-border bg-card p-4 shadow-xl sm:block">
            <p className="text-xs font-medium text-muted-foreground">
              New opportunity
            </p>
            <p className="mt-1 font-display text-sm font-bold">
              Soul District Block Party
            </p>
            <div className="mt-2 flex items-center justify-between">
              <span className="text-xs text-muted-foreground">Atlanta, GA</span>
              <span className="rounded-full bg-accent px-2 py-0.5 text-xs font-semibold text-accent-foreground">
                12 spots left
              </span>
            </div>
          </div>

          <div className="absolute -right-3 -top-4 hidden rounded-2xl border border-border bg-primary px-4 py-3 text-primary-foreground shadow-xl sm:block">
            <p className="font-display text-2xl font-extrabold leading-none">
              4,800+
            </p>
            <p className="mt-1 text-xs text-primary-foreground/70">
              active vendors
            </p>
          </div>
        </div>
      </div>
    </section>
  )
}
