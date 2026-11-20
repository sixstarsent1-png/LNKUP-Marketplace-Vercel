"use client"

import { Suspense, useMemo } from "react"
import Link from "next/link"
import {
  Plus,
  Inbox,
  DollarSign,
  Users,
  CalendarPlus,
  CheckCircle2,
  ExternalLink,
} from "lucide-react"
import { isLiveApiEnabled, getOrganizerDashboardUrl } from "@/lib/api/config"
import { composeOrganizerRetentionCards } from "@/lib/api/notifications"
import { useOrganizerMoney } from "@/lib/hooks/use-organizer-money"
import { useOrganizerHome } from "@/lib/hooks/use-organizer-home"
import {
  filterOrganizerRequestsByStatus,
  useOrganizerRequests,
} from "@/lib/hooks/use-organizer-requests"
import { usePanelState, type PanelState } from "@/lib/use-panel-state"
import { PageHeader, SectionHeader } from "@/components/marketplace/page-header"
import { StatCard } from "@/components/marketplace/stat-card"
import { LaunchChecklist } from "@/components/marketplace/launch-checklist"
import { OrganizerEventCard } from "@/components/organizer/organizer-event-card"
import { OrganizerVendorRequestCard } from "@/components/organizer/organizer-vendor-request-card"
import { OrganizerFinancialSnapshot } from "@/components/organizer/organizer-financial-snapshot"
import { OrganizerIntelligencePanel } from "@/components/organizer/organizer-intelligence-panel"
import { RetentionCardList } from "@/components/notifications/retention-card-list"
import { PwaInstallNudge } from "@/components/notifications/pwa-install-nudge"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { resolveOrganizerPrimaryCta } from "@/lib/organizer/primary-cta"
import { useOrganizerLaunchDraftPresence } from "@/lib/hooks/use-organizer-launch-draft-presence"
import { GuidedTutorialChecklist } from "@/components/tutorials/guided-tutorial-checklist"
import { buildOrganizerTutorial } from "@/lib/tutorials/build-organizer-tutorial"
import { useRoleTutorial } from "@/lib/hooks/use-role-tutorial"
import { useAuth } from "@/lib/hooks/use-auth"
import { useOrganizerRecruitReferrals } from "@/lib/hooks/use-organizer-recruit-referrals"
import { InviteOrganizersCard } from "@/components/referrals/invite-organizers-card"

function OrganizerHomeContent() {
  const { user } = useAuth()
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { summary, loading, error, retry } = useOrganizerHome()
  const { hasDraft: hasLaunchDraft } = useOrganizerLaunchDraftPresence()

  const qaOverride = qaState !== "content"

  const panelState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (!summary) return "empty"
    const hasContent =
      summary.stats.events > 0 ||
      summary.stats.vendorRequests > 0 ||
      (summary.upcomingEvents?.length ?? 0) > 0
    if (!hasContent) return "empty"
    return "content"
  }, [qaState, live, loading, error, summary])

  const { items: requests, retry: retryRequests } = useOrganizerRequests(!qaOverride)

  const pendingRequests = filterOrganizerRequestsByStatus(requests, "under_review").slice(0, 2)

  const handleRetry = () => {
    if (qaState === "error") {
      qaRetry()
      return
    }
    retry()
    retryRequests()
  }

  const stats = summary?.stats
  const { summary: moneySummary } = useOrganizerMoney(!qaOverride)
  const wpDashboard = getOrganizerDashboardUrl()
  const primaryCta = resolveOrganizerPrimaryCta(summary ?? null)

  const retentionCards = useMemo(
    () =>
      composeOrganizerRetentionCards({
        homeSummary: summary ?? null,
        pendingRequests: requests,
        paymentRequiredCount: moneySummary?.paymentRequiredCount,
      }),
    [summary, requests, moneySummary?.paymentRequiredCount],
  )

  const showAttention = retentionCards.length > 0

  const organizerTutorial = useMemo(
    () =>
      buildOrganizerTutorial({
        summary: summary ?? null,
        requests,
        paymentRequiredCount: moneySummary?.paymentRequiredCount,
      }),
    [summary, requests, moneySummary?.paymentRequiredCount],
  )
  const { visible: showOrganizerTutorial, dismiss, onStepClick } = useRoleTutorial(
    "organizer",
    organizerTutorial,
    user?.id ?? user?.username,
  )
  const {
    state: orgRecruit,
    loading: orgRecruitLoading,
    ensureCode: ensureOrgRecruitCode,
  } = useOrganizerRecruitReferrals()

  const tutorialCard = showOrganizerTutorial ? (
    <GuidedTutorialChecklist
      tutorial={organizerTutorial}
      onStepClick={onStepClick}
      onDismiss={dismiss}
      testId="organizer-home-tutorial"
    />
  ) : null

  return (
    <PanelContent
      state={panelState}
      retry={handleRetry}
      empty={
        <div className="flex flex-col gap-6">
          {tutorialCard}
          <EmptyState
            icon={CalendarPlus}
            title="Welcome, organizer"
            description="Launch your first vendor opportunity to see events and requests here."
            action={
              <Button nativeButton={false} render={<Link href="/organizer/launch" />}>
                <Plus data-icon="inline-start" />
                Launch first event
              </Button>
            }
          />
        </div>
      }
    >
      <div className="flex flex-col gap-6">
        <PageHeader
          title="Organizer Command Center"
          subtitle="Launch events, fill vendor rows, and collect fees — all in one place."
          action={
            <div className="flex flex-wrap gap-2">
              <Button nativeButton={false} render={<Link href={primaryCta.href} />} data-testid="organizer-primary-cta">
                <Plus data-icon="inline-start" />
                {primaryCta.label}
              </Button>
              {wpDashboard && (
                <Button
                  variant="outline"
                  nativeButton={false}
                  render={<Link href={wpDashboard} target="_blank" rel="noopener noreferrer" />}
                >
                  <ExternalLink data-icon="inline-start" />
                  Open LNKup dashboard
                </Button>
              )}
            </div>
          }
        />

        {hasLaunchDraft && (
          <Button
            variant="outline"
            className="w-full justify-start sm:w-auto"
            nativeButton={false}
            render={<Link href="/organizer/launch" />}
            data-testid="continue-launch-draft"
          >
            Continue launch draft
          </Button>
        )}

        {tutorialCard}

        <InviteOrganizersCard
          state={orgRecruit}
          loading={orgRecruitLoading}
          onEnsureCode={ensureOrgRecruitCode}
        />

        {stats && (
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
            <StatCard label="Active events" value={stats.events} hint="Your events" icon={CalendarPlus} accent />
            <StatCard label="Vendor requests" value={stats.vendorRequests} hint="Across your events" icon={Inbox} />
            <StatCard label="Approved" value={stats.approvedVendors} hint="Ready to pay" icon={CheckCircle2} />
            <StatCard label="Paid" value={stats.paidVendors} hint="Booked vendors" icon={Users} />
            <StatCard
              label="Payment due"
              value={stats.paymentRequired}
              hint="Awaiting vendor payment"
              icon={DollarSign}
            />
            <StatCard
              label="Open opportunities"
              value={stats.openOpportunities}
              hint="Accepting vendors"
              icon={CalendarPlus}
            />
          </div>
        )}

        {summary?.launchChecklist && summary.launchChecklist.length > 0 && (
          <LaunchChecklist
            title="Launch checklist"
            items={summary.launchChecklist.map((item) => ({
              label: item.label,
              done: item.complete,
              href: item.href,
            }))}
          />
        )}

        {showAttention && (
          <section>
            <SectionHeader title="Needs attention" />
            <RetentionCardList cards={retentionCards} testId="organizer-home-retention" />
            <PwaInstallNudge source="organizer_home" />
          </section>
        )}

        {summary?.intelligence && (
          <OrganizerIntelligencePanel
            diagnostics={summary.intelligence.diagnostics}
            experiments={summary.intelligence.experiments}
            recommendations={summary.intelligence.recommendations}
          />
        )}

        <section>
          <SectionHeader title="Recent requests" href="/organizer/vendors" />
          {!qaOverride && pendingRequests.length > 0 ? (
            <div className="flex flex-col gap-3">
              {pendingRequests.map((r) => (
                <OrganizerVendorRequestCard key={r.id} request={r} reviewDisabled />
              ))}
            </div>
          ) : (
            <Card>
              <CardContent className="py-6 text-center text-sm text-muted-foreground">
                You&apos;re all caught up — no requests waiting.
              </CardContent>
            </Card>
          )}
        </section>

        {summary?.financial && (
          <OrganizerFinancialSnapshot snapshot={summary.financial} compact />
        )}

        {summary && summary.upcomingEvents.length > 0 && (
          <section>
            <SectionHeader title="Your events" href="/organizer/events" />
            <div className="grid gap-4 md:grid-cols-2">
              {summary.upcomingEvents.map((e) => (
                <OrganizerEventCard
                  key={e.id}
                  event={{
                    id: e.id,
                    title: e.title,
                    date: e.date,
                    city: e.city,
                    state: e.state,
                    status: e.status,
                    requestsCount: e.requestsCount,
                    approvedCount: e.approvedCount,
                    paidCount: e.paidCount,
                  }}
                />
              ))}
            </div>
          </section>
        )}
      </div>
    </PanelContent>
  )
}

export default function OrganizerHome() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <OrganizerHomeContent />
    </Suspense>
  )
}
