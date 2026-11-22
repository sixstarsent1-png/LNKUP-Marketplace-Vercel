"use client"

import { Suspense, useEffect } from "react"
import Link from "next/link"
import { ArrowLeft, LogIn, Bookmark } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useNotificationPreferences } from "@/lib/hooks/use-notification-preferences"
import { useSavedSearches } from "@/lib/hooks/use-saved-searches"
import { trackLnkupEvent } from "@/lib/analytics/track-event"
import { PageHeader } from "@/components/marketplace/page-header"
import { CommsPreferencesForm } from "@/components/notifications/comms-preferences-form"
import { NotificationPreferencesForm } from "@/components/notifications/notification-preferences-form"
import { SavedSearchAlertCard } from "@/components/notifications/saved-search-alert-card"
import { PanelContent, SkeletonList, EmptyState } from "@/components/marketplace/states"
import { Button } from "@/components/ui/button"

function PreferencesContent() {
  const live = isLiveApiEnabled()
  const { preferences, loading, error, unauthenticated, saving, retry, updatePreference, updateComms } =
    useNotificationPreferences()
  const {
    searches,
    loading: searchesLoading,
    updateSearch,
    pauseSearch,
    resumeSearch,
    runSearch,
    deleteSearch,
  } = useSavedSearches()

  useEffect(() => {
    trackLnkupEvent({ event: "notification_preferences_viewed", source: "preferences_page" })
  }, [])

  if (live && unauthenticated) {
    return (
      <div className="flex flex-col items-center gap-4 rounded-2xl border border-dashed p-8 text-center">
        <LogIn className="size-8 text-muted-foreground" />
        <p className="text-sm text-muted-foreground">Sign in to manage notification preferences.</p>
        <Button nativeButton={false} render={<Link href="/login?redirect=/notifications/preferences" />}>
          Sign in
        </Button>
      </div>
    )
  }

  const panelState = loading ? "loading" : error ? "error" : preferences ? "content" : "empty"

  return (
    <div className="flex flex-col gap-6" data-testid="notification-preferences-page">
      <Button variant="ghost" size="sm" className="w-fit -ml-2" nativeButton={false} render={<Link href="/notifications" />}>
        <ArrowLeft data-icon="inline-start" />
        Back to notifications
      </Button>

      <PageHeader title="Notification preferences" subtitle="Manage alerts and saved search digests." />

      <PanelContent state={panelState} retry={retry}>
        {preferences?.comms ? (
          <CommsPreferencesForm
            comms={preferences.comms}
            saving={saving}
            onSave={(update, optimistic) => {
              trackLnkupEvent({ event: "notification_preference_changed", source: "preferences_page" })
              return updateComms(update, optimistic)
            }}
          />
        ) : null}
        {preferences && !preferences.comms && (
          <NotificationPreferencesForm
            preferences={preferences}
            saving={saving}
            onToggle={(itemId, channel, enabled) => {
              trackLnkupEvent({
                event: "notification_preference_changed",
                source: "preferences_page",
                metadata: { itemId, channel, enabled: String(enabled) },
              })
              updatePreference(itemId, channel, enabled)
            }}
          />
        )}
      </PanelContent>

      <section>
        <h2 className="mb-3 text-sm font-semibold">Saved search alerts</h2>
        {searchesLoading ? (
          <SkeletonList count={2} />
        ) : searches.length === 0 ? (
          <EmptyState
            icon={Bookmark}
            title="No saved searches"
            description="Save a search on Find Events to get alert emails from LNKup."
            action={
              <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/vendor/find" />}>
                Find events
              </Button>
            }
          />
        ) : (
          <div className="flex flex-col gap-3">
            {searches.map((search) => (
              <SavedSearchAlertCard
                key={search.id}
                search={search}
                onFrequencyChange={(id, frequency) => updateSearch(id, { frequency })}
                onPause={pauseSearch}
                onResume={resumeSearch}
                onRun={runSearch}
                onDelete={deleteSearch}
              />
            ))}
          </div>
        )}
      </section>
    </div>
  )
}

export default function NotificationPreferencesPage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <PreferencesContent />
    </Suspense>
  )
}
