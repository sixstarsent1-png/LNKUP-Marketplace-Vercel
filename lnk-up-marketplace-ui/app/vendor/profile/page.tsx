"use client"

import { Suspense, useState } from "react"
import Image from "next/image"
import { PageHeader } from "@/components/marketplace/page-header"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Avatar, AvatarFallback } from "@/components/ui/avatar"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Separator } from "@/components/ui/separator"
import { ProfileCompletionChecklist } from "@/components/vendor/profile-completion-checklist"
import { VendorDocumentsPanel } from "@/components/vendor/vendor-documents-panel"
import { ShareVendorProfileCard } from "@/components/vendor/share-vendor-profile-card"
import { useVendorDocuments } from "@/lib/hooks/use-vendor-documents"
import { VerificationBadge } from "@/components/marketplace/badges"
import { PanelContent, SkeletonList } from "@/components/marketplace/states"
import { currentVendor, vendorProfileDetails } from "@/lib/data"
import { usePanelState } from "@/lib/use-panel-state"
import { useVendorProfile } from "@/lib/hooks/use-vendor-profile"
import { DemoModeNotice } from "@/components/marketplace/demo-mode-notice"
import { isLiveApiEnabled } from "@/lib/api/config"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Star,
  MapPin,
  ShieldCheck,
  FileCheck,
  Bell,
  CreditCard,
  HelpCircle,
  LogOut,
  ChevronRight,
  Pencil,
  Upload,
  Globe,
  Mail,
  Phone,
  AtSign,
  CheckCircle2,
  Clock,
} from "lucide-react"

const settingsItems = [
  { icon: Bell, label: "Notifications" },
  { icon: CreditCard, label: "Payout & billing" },
  { icon: ShieldCheck, label: "Verification & documents" },
  { icon: HelpCircle, label: "Help & support" },
]

function VerificationStatus({
  label,
  status,
}: {
  label: string
  status: "verified" | "pending" | "connected"
}) {
  const icon =
    status === "verified" || status === "connected" ? (
      <CheckCircle2 className="size-4 text-success" />
    ) : (
      <Clock className="size-4 text-warning" />
    )
  const text =
    status === "verified" ? "Verified" : status === "connected" ? "Connected" : "Pending"

  return (
    <div className="flex items-center gap-3 rounded-xl border p-3">
      <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted">{icon}</span>
      <div className="flex flex-1 flex-col">
        <span className="text-sm font-medium">{label}</span>
        <span className="text-xs text-muted-foreground">{text}</span>
      </div>
    </div>
  )
}

function VendorProfileContent() {
  const { state, retry } = usePanelState()
  const vendor = currentVendor
  const details = vendorProfileDetails
  const { items: docItems, uploading, uploadError, uploadDocument } = useVendorDocuments()
  const { profile, saving, saveError, saveProfile, retry: retryProfile } = useVendorProfile()
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState({
    businessName: "",
    description: "",
    website: "",
    instagram: "",
    contactEmail: "",
    contactPhone: "",
    city: "",
    state: "",
  })
  const live = isLiveApiEnabled()
  const displayName = profile?.businessName || vendor.name
  const displayCity = profile?.city || vendor.city
  const displayState = profile?.state || vendor.state
  const initials = displayName
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("") || vendor.initials

  return (
    <PanelContent
      state={state}
      retry={() => {
        retry()
        retryProfile()
      }}
    >
      <div className="flex flex-col gap-6 pb-4">
        <PageHeader title="Profile" subtitle="Manage your vendor business and account." />

        <ShareVendorProfileCard />

        {!live ? (
          <DemoModeNotice testId="vendor-profile-demo-notice">
            Demo mode — profile identity uses sample data. Enable live API to load and save your WordPress profile.
          </DemoModeNotice>
        ) : null}

        <Card>
          <CardContent className="flex flex-col gap-4 pt-6">
            <div className="flex items-center gap-4">
              <Avatar className="size-16">
                <AvatarFallback className="bg-primary font-heading text-lg text-primary-foreground">
                  {initials}
                </AvatarFallback>
              </Avatar>
              <div className="flex flex-1 flex-col gap-1">
                <div className="flex items-center gap-2">
                  <h2 className="font-heading text-lg font-semibold">{displayName}</h2>
                  {vendor.verified && <VerificationBadge />}
                </div>
                <p className="text-sm text-muted-foreground">{vendor.category}</p>
                <div className="flex items-center gap-3 text-sm text-muted-foreground">
                  <span className="flex items-center gap-1">
                    <Star className="size-3.5 fill-accent text-accent" />
                    <span className="font-medium text-foreground">{vendor.rating}</span> ({vendor.reviews})
                  </span>
                  <span className="flex items-center gap-1">
                    <MapPin className="size-3.5" />
                    {displayCity}
                    {displayState ? `, ${displayState}` : ""}
                  </span>
                </div>
              </div>
              <Button
                variant="outline"
                size="icon"
                aria-label="Edit profile"
                onClick={() => {
                  setDraft({
                    businessName: profile?.businessName ?? "",
                    description: profile?.description ?? details.business.description,
                    website: profile?.website ?? details.business.website,
                    instagram: profile?.instagram ?? details.business.instagram,
                    contactEmail: profile?.contactEmail ?? details.business.email,
                    contactPhone: profile?.contactPhone ?? details.business.phone,
                    city: profile?.city ?? vendor.city,
                    state: profile?.state ?? vendor.state,
                  })
                  setEditing(true)
                }}
              >
                <Pencil />
              </Button>
            </div>

            <Separator />

            <div className="grid grid-cols-3 gap-2 text-center">
              <div className="flex flex-col gap-0.5">
                <span className="font-heading text-xl font-semibold">{vendor.completedEvents}</span>
                <span className="text-xs text-muted-foreground">Events</span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="font-heading text-xl font-semibold">{vendor.rating}</span>
                <span className="text-xs text-muted-foreground">Rating</span>
              </div>
              <div className="flex flex-col gap-0.5">
                <span className="font-heading text-xl font-semibold">{vendor.reviews}</span>
                <span className="text-xs text-muted-foreground">Reviews</span>
              </div>
            </div>

            <div className="flex flex-wrap gap-2">
              {vendor.documentsVerified && (
                <Badge variant="secondary" className="gap-1">
                  <FileCheck className="size-3" /> Docs verified
                </Badge>
              )}
              {vendor.insured && (
                <Badge variant="secondary" className="gap-1">
                  <ShieldCheck className="size-3" /> Insured
                </Badge>
              )}
            </div>
          </CardContent>
        </Card>

        <ProfileCompletionChecklist />

        <Tabs defaultValue="business">
          <div className="-mx-4 overflow-x-auto px-4 md:mx-0 md:px-0">
            <TabsList variant="line" className="w-max">
              <TabsTrigger value="business">Business</TabsTrigger>
              <TabsTrigger value="photos">Photos</TabsTrigger>
              <TabsTrigger value="documents">Documents</TabsTrigger>
              <TabsTrigger value="setup">Setup Needs</TabsTrigger>
              <TabsTrigger value="reviews">Reviews</TabsTrigger>
              <TabsTrigger value="verification">Verification</TabsTrigger>
            </TabsList>
          </div>

          <TabsContent value="business" className="mt-4 flex flex-col gap-4">
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Business info</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-4">
                {editing ? (
                  <form
                    className="flex flex-col gap-3"
                    onSubmit={async (event) => {
                      event.preventDefault()
                      const result = await saveProfile(draft)
                      if (result.success) {
                        setEditing(false)
                      }
                    }}
                  >
                    <label className="flex flex-col gap-1 text-sm">
                      Business name
                      <input
                        className="rounded-lg border bg-background px-3 py-2"
                        value={draft.businessName}
                        onChange={(e) => setDraft((prev) => ({ ...prev, businessName: e.target.value }))}
                      />
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                      Description
                      <textarea
                        className="min-h-24 rounded-lg border bg-background px-3 py-2"
                        value={draft.description}
                        onChange={(e) => setDraft((prev) => ({ ...prev, description: e.target.value }))}
                      />
                    </label>
                    <div className="grid gap-3 sm:grid-cols-2">
                      <label className="flex flex-col gap-1 text-sm">
                        Email
                        <input
                          className="rounded-lg border bg-background px-3 py-2"
                          value={draft.contactEmail}
                          onChange={(e) => setDraft((prev) => ({ ...prev, contactEmail: e.target.value }))}
                        />
                      </label>
                      <label className="flex flex-col gap-1 text-sm">
                        Phone
                        <input
                          className="rounded-lg border bg-background px-3 py-2"
                          value={draft.contactPhone}
                          onChange={(e) => setDraft((prev) => ({ ...prev, contactPhone: e.target.value }))}
                        />
                      </label>
                      <label className="flex flex-col gap-1 text-sm">
                        Website
                        <input
                          className="rounded-lg border bg-background px-3 py-2"
                          value={draft.website}
                          onChange={(e) => setDraft((prev) => ({ ...prev, website: e.target.value }))}
                        />
                      </label>
                      <label className="flex flex-col gap-1 text-sm">
                        Instagram
                        <input
                          className="rounded-lg border bg-background px-3 py-2"
                          value={draft.instagram}
                          onChange={(e) => setDraft((prev) => ({ ...prev, instagram: e.target.value }))}
                        />
                      </label>
                      <label className="flex flex-col gap-1 text-sm">
                        City
                        <input
                          className="rounded-lg border bg-background px-3 py-2"
                          value={draft.city}
                          onChange={(e) => setDraft((prev) => ({ ...prev, city: e.target.value }))}
                        />
                      </label>
                      <label className="flex flex-col gap-1 text-sm">
                        State
                        <input
                          className="rounded-lg border bg-background px-3 py-2"
                          value={draft.state}
                          onChange={(e) => setDraft((prev) => ({ ...prev, state: e.target.value }))}
                        />
                      </label>
                    </div>
                    {saveError ? <p className="text-sm text-destructive">{saveError}</p> : null}
                    <div className="flex gap-2">
                      <Button type="submit" disabled={saving}>
                        {saving ? "Saving…" : "Save profile"}
                      </Button>
                      <Button type="button" variant="outline" onClick={() => setEditing(false)}>
                        Cancel
                      </Button>
                    </div>
                  </form>
                ) : (
                  <>
                <p className="text-sm text-muted-foreground">
                  {profile?.description || details.business.description}
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                  <InfoRow icon={Mail} label="Email" value={profile?.contactEmail || details.business.email} />
                  <InfoRow icon={Phone} label="Phone" value={profile?.contactPhone || details.business.phone} />
                  <InfoRow icon={Globe} label="Website" value={profile?.website || details.business.website} />
                  <InfoRow icon={AtSign} label="Instagram" value={profile?.instagram || details.business.instagram} />
                </div>
                <Separator />
                <div className="flex flex-col gap-2">
                  <span className="text-xs font-medium text-muted-foreground">Cities served</span>
                  <div className="flex flex-wrap gap-2">
                    {details.business.cities.map((city) => (
                      <Badge key={city} variant="outline">
                        {city}
                      </Badge>
                    ))}
                  </div>
                </div>
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="photos" className="mt-4">
            <Card className="rounded-2xl">
              <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="text-base">Photos</CardTitle>
                <Button size="sm" variant="outline">
                  <Upload data-icon="inline-start" />
                  Add photo
                </Button>
              </CardHeader>
              <CardContent className="grid gap-3 sm:grid-cols-3">
                {details.photos.map((photo) => (
                  <div key={photo.id} className="flex flex-col gap-2">
                    <div className="relative aspect-[4/3] overflow-hidden rounded-xl border">
                      <Image src={photo.url} alt={photo.label} fill className="object-cover" />
                    </div>
                    <span className="text-xs text-muted-foreground">{photo.label}</span>
                  </div>
                ))}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="documents" className="mt-4">
            <VendorDocumentsPanel
              items={docItems}
              uploading={uploading}
              uploadError={uploadError}
              onUpload={uploadDocument}
              compact
            />
          </TabsContent>

          <TabsContent value="setup" className="mt-4">
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Setup needs</CardTitle>
              </CardHeader>
              <CardContent className="flex flex-col gap-2">
                {details.setupNeeds.map((need) => (
                  <div
                    key={need.id}
                    className="flex items-center justify-between rounded-xl border px-3 py-2.5"
                  >
                    <div className="flex flex-col">
                      <span className="text-sm font-medium">{need.label}</span>
                      <span className="text-xs text-muted-foreground">
                        {need.required ? "Required at most events" : "Optional"}
                      </span>
                    </div>
                    <Badge variant={need.provided ? "secondary" : "outline"}>
                      {need.provided ? "You provide" : "Need from organizer"}
                    </Badge>
                  </div>
                ))}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="reviews" className="mt-4 flex flex-col gap-3">
            {details.reviews.map((review) => (
              <Card key={review.id} className="rounded-2xl">
                <CardContent className="flex flex-col gap-2 pt-4">
                  <div className="flex items-center justify-between">
                    <span className="text-sm font-semibold">{review.event}</span>
                    <div className="flex items-center gap-0.5">
                      {Array.from({ length: review.rating }).map((_, i) => (
                        <Star key={i} className="size-3.5 fill-accent text-accent" />
                      ))}
                    </div>
                  </div>
                  <p className="text-sm text-muted-foreground">{review.text}</p>
                  <span className="text-xs text-muted-foreground">{review.date}</span>
                </CardContent>
              </Card>
            ))}
          </TabsContent>

          <TabsContent value="verification" className="mt-4 flex flex-col gap-4">
            <Card className="rounded-2xl">
              <CardHeader>
                <CardTitle className="text-base">Verification status</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3 sm:grid-cols-2">
                <VerificationStatus label="Identity" status={details.verification.identity} />
                <VerificationStatus label="Business documents" status={details.verification.documents} />
                <VerificationStatus label="Insurance" status={details.verification.insurance} />
                <VerificationStatus label="Payout method" status={details.verification.payout} />
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        <Card>
          <CardHeader>
            <CardTitle className="text-base">Settings</CardTitle>
          </CardHeader>
          <CardContent className="flex flex-col gap-1 pb-2">
            {settingsItems.map((item) => (
              <button
                key={item.label}
                className="flex items-center gap-3 rounded-lg px-2 py-3 text-left text-sm transition-colors hover:bg-secondary"
              >
                <item.icon className="size-4 text-muted-foreground" />
                <span className="flex-1 font-medium">{item.label}</span>
                <ChevronRight className="size-4 text-muted-foreground" />
              </button>
            ))}
            <Separator className="my-1" />
            <button className="flex items-center gap-3 rounded-lg px-2 py-3 text-left text-sm text-destructive transition-colors hover:bg-destructive/10">
              <LogOut className="size-4" />
              <span className="flex-1 font-medium">Sign out</span>
            </button>
          </CardContent>
        </Card>
      </div>
    </PanelContent>
  )
}

function InfoRow({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Mail
  label: string
  value: string
}) {
  return (
    <div className="flex items-start gap-2">
      <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
      <div className="flex flex-col">
        <span className="text-xs text-muted-foreground">{label}</span>
        <span className="text-sm font-medium">{value}</span>
      </div>
    </div>
  )
}

export default function VendorProfilePage() {
  return (
    <Suspense fallback={<SkeletonList count={2} />}>
      <VendorProfileContent />
    </Suspense>
  )
}
