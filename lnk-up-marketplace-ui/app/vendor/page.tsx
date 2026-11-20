"use client"

import { Suspense, useEffect, useMemo } from "react"
import Link from "next/link"
import {
  Calendar,
  MapPin,
  ArrowRight,
  Ticket,
  Send,
  CheckCircle2,
  DollarSign,
  Bookmark,
} from "lucide-react"
import { currentVendor } from "@/lib/data"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { useVendorRequests, filterPaymentRequired, filterBooked } from "@/lib/hooks/use-vendor-requests"
import { useFavorites } from "@/lib/hooks/use-favorites"
import { useVendorReadiness } from "@/lib/hooks/use-vendor-readiness"
import { useVendorOnboarding } from "@/lib/hooks/use-vendor-onboarding"
import { useMarketplaceRails } from "@/lib/hooks/use-marketplace-rails"
import { useVendorReferrals } from "@/lib/hooks/use-vendor-referrals"
import { useOrganizerRecruitReferrals } from "@/lib/hooks/use-organizer-recruit-referrals"
import { useVendorReputation } from "@/lib/hooks/use-vendor-reputation"
import { useVendorWallet } from "@/lib/hooks/use-vendor-wallet"
import { useVendorReviewEligibility } from "@/lib/hooks/use-vendor-review-eligibility"
import { composeVendorRetentionCards } from "@/lib/api/notifications"
import { PwaInstallNudge } from "@/components/notifications/pwa-install-nudge"
import { useSavedSearches } from "@/lib/hooks/use-saved-searches"
import { isEventDay, isEventWithinHours } from "@/lib/vendor/event-readiness"
import { resolveVendorNextAction } from "@/lib/vendor/next-action"
import { submitVendorReviewFeedback } from "@/lib/vendor/review-feedback"
import { SectionHeader } from "@/components/marketplace/page-header"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { OpportunityRailList } from "@/components/marketplace/opportunity-rail"
import { StatusBadge } from "@/components/marketplace/badges"
import { VendorReadinessCard } from "@/components/vendor/vendor-readiness-card"
import { VendorReferralCard } from "@/components/vendor/vendor-referral-card"
import { InviteOrganizersCard } from "@/components/referrals/invite-organizers-card"
import { VendorReputationSnapshotCard } from "@/components/vendor/vendor-reputation-snapshot-card"
import { ReviewEligibilityBanner } from "@/components/reputation/review-eligibility-banner"
import { SkeletonList } from "@/components/marketplace/states"
import { ExecutiveHero, greetingLabel } from "@/components/vendor/executive-hero"
import {
  ActionCenter,
  mergeNextActionIntoItems,
  retentionCardsToActionItems,
} from "@/components/vendor/action-center"
import { KpiStrip } from "@/components/vendor/kpi-strip"
import { Surface } from "@/components/vendor/surface"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { GuidedTutorialChecklist } from "@/components/tutorials/guided-tutorial-checklist"
import { buildVendorTutorial } from "@/lib/tutorials/build-vendor-tutorial"
import { useRoleTutorial } from "@/lib/hooks/use-role-tutorial"
import { useAuth } from "@/lib/hooks/use-auth"

function VendorHomeContent() {
  const { user } = useAuth()
  const { items: requests } = useVendorRequests()
  const { items: favorites } = useFavorites()
  const { readiness } = useVendorReadiness()
  const { state: onboarding, isComplete: onboardingComplete } = useVendorOnboarding()
  const { rails, loading: railsLoading } = useMarketplaceRails("home")
  const { state: referrals, loading: referralsLoading, generateInvite } = useVendorReferrals()
  const {
    state: orgRecruit,
    loading: orgRecruitLoading,
    ensureCode: ensureOrgRecruitCode,
  } = useOrganizerRecruitReferrals()
  const { summary: reputation, loading: reputationLoading } = useVendorReputation()
  const { summary: walletSummary } = useVendorWallet()
  const { searches } = useSavedSearches()
  const { items: reviewEligibility } = useVendorReviewEligibility()

  const nextAction = useMemo(
    () =>
      resolveVendorNextAction({
        requests,
        onboarding,
        readiness: readiness ?? null,
        reputation: reputation ?? null,
        reviewEligibility,
      }),
    [requests, onboarding, readiness, reputation, reviewEligibility],
  )

  const activeEligibility = reviewEligibility.filter(
    (item) =>
      item.status === "awaiting_organizer_review" ||
      item.status === "review_received" ||
      item.status === "vendor_feedback_available",
  )

  const kpis = useMemo(() => {
    const underReview = requests.filter(
      (r) => r.status === "submitted" || r.status === "under_review",
    ).length
    const paymentRequired = filterPaymentRequired(requests).length
    const bookedCount = filterBooked(requests).length
    return {
      applied: requests.length,
      underReview,
      paymentRequired,
      booked: bookedCount,
      saved: favorites.length,
    }
  }, [requests, favorites.length])

  const booked = filterBooked(requests)
  const eventDayList = booked.filter(
    (r) => isEventDay(r.eventDate) || isEventWithinHours(r.eventDate, 72),
  )

  const retentionCards = useMemo(
    () =>
      composeVendorRetentionCards({
        requests,
        savedSearches: searches,
        readiness: readiness ?? null,
        reviewEligibility,
        walletSummary,
        favoritesCount: favorites.length,
      }),
    [requests, searches, readiness, reviewEligibility, walletSummary, favorites.length],
  )

  const actionItems = useMemo(
    () => mergeNextActionIntoItems(retentionCardsToActionItems(retentionCards), nextAction),
    [retentionCards, nextAction],
  )

  const heroMetrics = useMemo(() => {
    const metrics: { label: string; value: string }[] = []
    const profilePct =
      readiness?.profileCompletion ?? onboarding?.profileCompletion ?? null
    if (profilePct != null) {
      metrics.push({ label: "Profile", value: `${profilePct}%` })
    }
    if (reputation) {
      const repLabel =
        reputation.tier === "new"
          ? "New vendor"
          : reputation.tierLabel ??
            (reputation.score != null ? `${reputation.score}/100` : "—")
      metrics.push({ label: "Reputation", value: repLabel })
    }
    if (walletSummary?.totalPaid != null && walletSummary.totalPaid > 0) {
      const formatted = new Intl.NumberFormat("en-US", {
        style: "currency",
        currency: walletSummary.currency ?? "USD",
        maximumFractionDigits: 0,
      }).format(walletSummary.totalPaid)
      metrics.push({ label: "Paid", value: formatted })
    }
    const nextBooked = booked[0]
    if (nextBooked?.eventDate) {
      metrics.push({ label: "Next event", value: nextBooked.eventDate })
    }
    return metrics
  }, [readiness, onboarding, reputation, walletSummary, booked])

  useEffect(() => {
    if (!readiness?.blockers?.length) return
    trackLnkupEvent({
      event: "vendor_readiness_blocker_viewed",
      source: "vendor_home",
      metadata: { blocker_count: readiness.blockers.length, risk_bucket: readiness.riskBucket },
    })
  }, [readiness?.blockers?.length, readiness?.riskBucket])

  const secondaryHref =
    nextAction.priority === "payment" || nextAction.priority === "pass"
      ? "/vendor/find"
      : kpis.paymentRequired > 0
        ? "/vendor/wallet"
        : "/vendor/find"
  const secondaryLabel =
    secondaryHref === "/vendor/wallet" ? "Open wallet" : "Find events"

  const vendorTutorial = useMemo(
    () =>
      buildVendorTutorial({
        requests,
        favoritesCount: favorites.length,
        onboarding,
        readiness: readiness ?? null,
      }),
    [requests, favorites.length, onboarding, readiness],
  )
  const { visible: showVendorTutorial, dismiss, onStepClick } = useRoleTutorial(
    "vendor",
    vendorTutorial,
    user?.id ?? user?.username,
  )

  return (
    <div className="flex flex-col gap-6">
      <ExecutiveHero
        title={`${greetingLabel()}, ${currentVendor.name}`}
        subtitle={`${currentVendor.city}, ${currentVendor.state} · One profile. More event opportunities.`}
        metrics={heroMetrics}
        primaryCta={{
          label: nextAction.label,
          href: nextAction.href,
          onClick: () => {
            if (nextAction.priority === "profile") {
              trackLnkupEvent({ event: "profile_completion_clicked", source: "vendor_home" })
            }
          },
        }}
        secondaryCta={{
          label: secondaryLabel,
          href: secondaryHref,
        }}
      />

      <ActionCenter items={actionItems} testId="vendor-home-retention" />

      {showVendorTutorial ? (
        <GuidedTutorialChecklist
          tutorial={vendorTutorial}
          onStepClick={onStepClick}
          onDismiss={dismiss}
          testId="vendor-home-tutorial"
        />
      ) : null}

      {!onboardingComplete && onboarding && onboarding.currentStep !== "complete" && (
        <Surface
          variant="secondary"
          className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
          data-testid="onboarding-resume-card"
        >
          <div>
            <p className="font-heading font-semibold">Finish your vendor setup</p>
            <p className="text-sm text-muted-foreground">
              {onboarding.profileCompletion}% complete · Get reviewed faster with a complete
              profile.
            </p>
          </div>
          <Button
            className="min-h-11"
            nativeButton={false}
            render={<Link href="/vendor/onboarding" />}
            onClick={() =>
              trackLnkupEvent({ event: "vendor_onboarding_started", source: "vendor_home" })
            }
          >
            Continue setup
          </Button>
        </Surface>
      )}

      <KpiStrip
        items={[
          { label: "Applied", value: kpis.applied, icon: Send },
          { label: "Under review", value: kpis.underReview, icon: CheckCircle2 },
          {
            label: "Payment required",
            value: kpis.paymentRequired,
            icon: DollarSign,
            accent: kpis.paymentRequired > 0,
          },
          { label: "Booked", value: kpis.booked, icon: Calendar },
          { label: "Saved", value: kpis.saved, icon: Bookmark },
        ]}
      />

      <VendorReadinessCard />

      {readiness?.blockers?.length ? (
        <Card className="rounded-2xl border-dashed">
          <CardContent className="flex flex-col gap-2 py-4">
            <p className="text-sm font-medium">
              {readiness.riskBucket === "red"
                ? "Event-day readiness is at risk"
                : "Follow-up items before event day"}
            </p>
            <ul className="text-sm text-muted-foreground">
              {readiness.blockers.slice(0, 3).map((item) => (
                <li key={item.id}>
                  - {item.label}
                  {item.documentUrgency === "expired"
                    ? " (expired)"
                    : item.documentUrgency === "expiring_soon"
                      ? " (expiring soon)"
                      : ""}
                </li>
              ))}
            </ul>
            {readiness.nextAction ? (
              <Button
                size="sm"
                className="w-fit"
                nativeButton={false}
                render={<Link href={readiness.nextAction.href} />}
                onClick={() =>
                  trackLnkupEvent({
                    event: "vendor_readiness_action_clicked",
                    source: "vendor_home",
                    metadata: { href: readiness.nextAction?.href ?? "/vendor/profile" },
                  })
                }
              >
                {readiness.nextAction.label}
              </Button>
            ) : null}
          </CardContent>
        </Card>
      ) : null}

      <VendorReputationSnapshotCard summary={reputation} loading={reputationLoading} />

      <InviteOrganizersCard
        state={orgRecruit}
        loading={orgRecruitLoading}
        onEnsureCode={ensureOrgRecruitCode}
      />

      {activeEligibility.length > 0 && (
        <section className="flex flex-col gap-3" data-testid="vendor-home-review-eligibility">
          {activeEligibility.slice(0, 2).map((item) => (
            <ReviewEligibilityBanner
              key={item.applicationId}
              item={item}
              onFeedback={submitVendorReviewFeedback}
            />
          ))}
        </section>
      )}

      <OpportunityRailList rails={rails} loading={railsLoading} />

      <PwaInstallNudge source="vendor_home" />

      {(booked.length > 0 || eventDayList.length > 0) && (
        <div className="flex justify-end">
          <Button
            variant="ghost"
            size="sm"
            className="min-h-11"
            nativeButton={false}
            render={<Link href="/vendor/operations" />}
          >
            View all operations
          </Button>
        </div>
      )}

      {favorites.length > 0 && (
        <section>
          <SectionHeader title="Saved opportunities" href="/vendor/favorites" />
          <div className="grid gap-4 md:grid-cols-2">
            {favorites.slice(0, 2).map((e) => (
              <OpportunityCard key={e.id} event={e} />
            ))}
          </div>
        </section>
      )}

      {booked.length > 0 && (
        <section>
          <SectionHeader title="Upcoming booked events" href="/vendor/requests" />
          <div className="flex flex-col gap-3">
            {booked.map((r) => (
              <Surface
                key={r.id}
                variant="quiet"
                className="flex flex-row items-center gap-3 py-4"
              >
                <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-accent">
                  <Ticket className="size-5 text-accent-foreground" />
                </div>
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                  <div className="flex items-center justify-between gap-2">
                    <span className="truncate font-semibold">{r.eventName}</span>
                    <StatusBadge variant="paid">Booked</StatusBadge>
                  </div>
                  <div className="flex flex-wrap gap-x-3 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                      <Calendar className="size-3" />
                      {r.eventDate}
                    </span>
                    <span className="inline-flex items-center gap-1">
                      <MapPin className="size-3" />
                      {r.city}
                    </span>
                  </div>
                </div>
                {r.id && (
                  <Button
                    variant="ghost"
                    size="icon"
                    className="min-h-11 min-w-11"
                    nativeButton={false}
                    render={<Link href={`/vendor/pass/${r.id}`} aria-label="View pass" />}
                  >
                    <ArrowRight />
                  </Button>
                )}
              </Surface>
            ))}
          </div>
        </section>
      )}

      <VendorReferralCard
        state={referrals}
        loading={referralsLoading}
        onGenerate={generateInvite}
      />
    </div>
  )
}

export default function VendorHome() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorHomeContent />
    </Suspense>
  )
}
