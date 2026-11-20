"use client"

import { Suspense, useMemo } from "react"
import Link from "next/link"
import { Heart, Bookmark, LogIn } from "lucide-react"
import { isLiveApiEnabled } from "@/lib/api/config"
import { useFavorites } from "@/lib/hooks/use-favorites"
import { useSavedSearches } from "@/lib/hooks/use-saved-searches"
import { usePanelState, type PanelState } from "@/lib/use-panel-state"
import { PageHeader } from "@/components/marketplace/page-header"
import { OpportunityCard } from "@/components/marketplace/opportunity-card"
import { SavedSearchAlertCard } from "@/components/notifications/saved-search-alert-card"
import { PanelContent, EmptyState, SkeletonList } from "@/components/marketplace/states"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Button } from "@/components/ui/button"

function FavoritesContent() {
  const live = isLiveApiEnabled()
  const { state: qaState, retry: qaRetry } = usePanelState()
  const { items, loading, error, unauthenticated, retry } = useFavorites()
  const {
    searches,
    loading: searchesLoading,
    error: searchesError,
    unauthenticated: searchesUnauth,
    retry: retrySearches,
    deleteSearch,
    updateSearch,
    pauseSearch,
    resumeSearch,
    runSearch,
    saving,
  } = useSavedSearches()

  const qaOverride = qaState !== "content"

  const favoritesState: PanelState = useMemo(() => {
    if (qaState === "error") return "error"
    if (qaState === "loading") return "loading"
    if (qaState === "empty") return "empty"
    if (live && loading) return "loading"
    if (live && error) return "error"
    if (items.length === 0) return "empty"
    return "content"
  }, [qaState, live, loading, error, items.length])

  const searchesState: PanelState = useMemo(() => {
    if (live && searchesLoading) return "loading"
    if (live && searchesError) return "error"
    if (searches.length === 0) return "empty"
    return "content"
  }, [live, searchesLoading, searchesError, searches.length])

  const handleFavoritesRetry = () => {
    if (qaState === "error") qaRetry()
    else retry()
  }

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Favorites"
        subtitle="Saved opportunities and searches."
        action={
          <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/notifications/preferences" />}>
            Alert preferences
          </Button>
        }
      />

      {(unauthenticated || searchesUnauth) && live && !qaOverride && (
        <div className="flex items-center justify-between gap-3 rounded-2xl border bg-muted/40 p-4">
          <p className="text-sm text-muted-foreground">Sign in to sync saved opportunities and searches.</p>
          <Button size="sm" nativeButton={false} render={<Link href="/login?redirect=/vendor/favorites" />}>
            <LogIn data-icon="inline-start" />
            Sign in
          </Button>
        </div>
      )}

      <Tabs defaultValue="opportunities">
        <TabsList variant="line">
          <TabsTrigger value="opportunities">Saved opportunities</TabsTrigger>
          <TabsTrigger value="searches">Saved searches</TabsTrigger>
        </TabsList>

        <TabsContent value="opportunities" className="mt-4">
          <PanelContent
            state={favoritesState}
            retry={handleFavoritesRetry}
            empty={
              <EmptyState
                icon={Heart}
                title="No saved events"
                description="Saved opportunities will appear here."
                action={
                  <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/vendor/find" />}>
                    Find events
                  </Button>
                }
              />
            }
          >
            <div className="grid gap-4 md:grid-cols-2">
              {items.map((event) => (
                <OpportunityCard key={event.id} event={event} />
              ))}
            </div>
          </PanelContent>
        </TabsContent>

        <TabsContent value="searches" className="mt-4">
          <PanelContent
            state={searchesState}
            retry={retrySearches}
            empty={
              <EmptyState
                icon={Bookmark}
                title="No saved searches"
                description="Saved searches help you track new event opportunities."
                action={
                  <Button variant="outline" size="sm" nativeButton={false} render={<Link href="/vendor/find" />}>
                    Find events
                  </Button>
                }
              />
            }
          >
            <div className="flex flex-col gap-3" data-testid="saved-search-alert-list">
              {searches.map((search) => (
                <SavedSearchAlertCard
                  key={search.id}
                  search={search}
                  busy={saving}
                  onFrequencyChange={(id, frequency) => updateSearch(id, { frequency })}
                  onPause={pauseSearch}
                  onResume={resumeSearch}
                  onRun={runSearch}
                  onDelete={deleteSearch}
                />
              ))}
            </div>
          </PanelContent>
        </TabsContent>
      </Tabs>
    </div>
  )
}

export default function VendorFavoritesPage() {
  return (
    <Suspense fallback={<SkeletonList count={3} />}>
      <FavoritesContent />
    </Suspense>
  )
}
