import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchFormData, wpFetchJson } from "@/lib/api/wp-client"

/**
 * Vendor Passport (lnkup/v1/passport/*). WordPress is the system of record; this module only maps
 * REST payloads. The internal 0–100 reputation score is never part of these contracts.
 */

export type PassportIndicators = {
  verification: { status: string; isVerified: boolean; label: string; explanation: string }
  experience: { confirmedEvents: number; distinctCities: number; distinctOrganizers: number; label: string }
  feedback: { count: number; displayAverage: boolean; average: number | null; label: string; method: string }
  documents: { label: string; currentTypes: string[] }
  achievements: Array<{ slug: string; label: string; rule: string }>
  isNewVendor: boolean
  newVendorNote: string
}

export type PassportPrivacy = {
  publicProfile: boolean
  showHistory: boolean
  showReviews: boolean
  showCities: boolean
  showPortfolio: boolean
  showSocials: boolean
  hiddenEventIds: number[]
}

export type PassportAction = { key: string; label: string; detail: string; target: PassportTab }

export type PortfolioItem = {
  attachmentId: number
  url: string
  thumb: string
  caption: string
  alt: string
  tag: string
  isCover: boolean
  eventTitle?: string
}

export type MyPassport = {
  profileId: number
  businessName: string
  description: string
  logo: string
  cover: string
  city: string
  state: string
  categories: Array<{ id: number; name: string; isPrimary: boolean }>
  publicUrl: string
  isPublic: boolean
  strength: { score: number; tier: string; note: string }
  indicators: PassportIndicators
  privacy: PassportPrivacy
  requiredActions: PassportAction[]
  portfolio: PortfolioItem[]
  capabilities: Array<{ key: string; label: string; group: string }>
  invitations: Array<{ id: number; eventTitle: string; eventDate: string; organizerName: string; message: string }>
}

export type PassportTab = "overview" | "portfolio" | "verification" | "history" | "reviews" | "documents" | "settings"

export type HistoryItem = {
  eventId: number
  eventTitle: string
  eventDate: string
  city: string
  state: string
  organizerName: string
  tourTitle: string
  status: string
  statusLabel: string
  confirmed: boolean
  evidence: string
}

export type HistoryPage = { items: HistoryItem[]; total: number; page: number; perPage: number }

export type PassportReview = {
  id: number
  overallRating: number
  publicText: string
  eventTitle: string
  organizerName: string
  createdAt: string
  verifiedParticipation: boolean
  underReview: boolean
  tags: string[]
  response: string | null
  canRespond: boolean
  canReport: boolean
  disputes: Array<{ id: number; status: string; canAppeal: boolean }>
}

export type ReviewsPage = {
  items: PassportReview[]
  total: number
  page: number
  perPage: number
  summaryLabel: string
  reportReasons: Record<string, string>
}

export type VerificationRecord = { type: string; label: string; status: string; statusLabel: string; expiresAt: string | null; reason: string }

type Raw = Record<string, unknown>

const str = (v: unknown): string => (typeof v === "string" ? v : v == null ? "" : String(v))
const num = (v: unknown): number => (Number.isFinite(Number(v)) ? Number(v) : 0)
const bool = (v: unknown): boolean => v === true || v === 1 || v === "1"
const arr = (v: unknown): Raw[] => (Array.isArray(v) ? (v as Raw[]) : [])
const obj = (v: unknown): Raw => (v && typeof v === "object" && !Array.isArray(v) ? (v as Raw) : {})

function mapIndicators(raw: Raw): PassportIndicators {
  const v = obj(raw.verification)
  const e = obj(raw.experience)
  const f = obj(raw.organizer_feedback)
  const d = obj(raw.document_readiness)
  return {
    verification: { status: str(v.status), isVerified: bool(v.is_verified), label: str(v.label), explanation: str(v.explanation) },
    experience: {
      confirmedEvents: num(e.confirmed_events),
      distinctCities: num(e.distinct_cities),
      distinctOrganizers: num(e.distinct_organizers),
      label: str(e.label),
    },
    feedback: {
      count: num(f.count),
      displayAverage: bool(f.display_average),
      average: bool(f.display_average) ? num(f.average) : null,
      label: str(f.label),
      method: str(f.method),
    },
    documents: { label: str(d.label), currentTypes: arr(d.current_types).map((t) => str(t.label)) },
    achievements: arr(raw.achievements).map((a) => ({ slug: str(a.slug), label: str(a.label), rule: str(a.rule) })),
    isNewVendor: bool(raw.is_new_vendor),
    newVendorNote: str(raw.new_vendor_note),
  }
}

function mapPrivacy(raw: Raw): PassportPrivacy {
  return {
    publicProfile: bool(raw.public_profile),
    showHistory: bool(raw.show_history),
    showReviews: bool(raw.show_reviews),
    showCities: bool(raw.show_cities),
    showPortfolio: bool(raw.show_portfolio),
    showSocials: bool(raw.show_socials),
    hiddenEventIds: (Array.isArray(raw.hidden_event_ids) ? raw.hidden_event_ids : []).map(num).filter(Boolean),
  }
}

const TABS: PassportTab[] = ["overview", "portfolio", "verification", "history", "reviews", "documents", "settings"]

export function asPassportTab(raw: unknown): PassportTab {
  return TABS.includes(raw as PassportTab) ? (raw as PassportTab) : "overview"
}

function mapPortfolio(items: unknown): PortfolioItem[] {
  return arr(items).map((p) => ({
    attachmentId: num(p.attachment_id),
    url: str(p.url),
    thumb: str(p.thumb) || str(p.url),
    caption: str(p.caption),
    alt: str(p.alt),
    tag: str(p.tag),
    isCover: bool(p.is_cover),
    eventTitle: str(p.event_title) || undefined,
  }))
}

export function mapMyPassport(raw: Raw): MyPassport {
  const strength = obj(raw.strength)
  return {
    profileId: num(raw.profile_id),
    businessName: str(raw.business_name),
    description: str(raw.description),
    logo: str(raw.logo),
    cover: str(raw.cover),
    city: str(raw.city),
    state: str(raw.state),
    categories: arr(raw.categories).map((c) => ({ id: num(c.id), name: str(c.name), isPrimary: bool(c.is_primary) })),
    publicUrl: str(raw.public_url),
    isPublic: bool(raw.is_public),
    strength: { score: num(strength.score), tier: str(strength.tier), note: str(strength.note) },
    indicators: mapIndicators(obj(raw.reputation)),
    privacy: mapPrivacy(obj(raw.privacy)),
    requiredActions: arr(raw.required_actions).map((a) => ({
      key: str(a.key),
      label: str(a.label),
      detail: str(a.detail),
      target: asPassportTab(a.target),
    })),
    portfolio: mapPortfolio(raw.portfolio),
    capabilities: arr(raw.capabilities).map((c) => ({ key: str(c.key), label: str(c.label), group: str(c.group) })),
    invitations: arr(raw.invitations).map((i) => ({
      id: num(i.id),
      eventTitle: str(i.event_title),
      eventDate: str(i.event_date),
      organizerName: str(i.organizer_name),
      message: str(i.message),
    })),
  }
}

const MOCK_PASSPORT: MyPassport = {
  profileId: 1,
  businessName: "Demo Tacos",
  description: "Street tacos for festivals.",
  logo: "",
  cover: "",
  city: "Houston",
  state: "TX",
  categories: [{ id: 1, name: "Food Truck", isPrimary: true }],
  publicUrl: "https://example.com/vendors/demo-tacos/",
  isPublic: true,
  strength: { score: 62, tier: "Good", note: "Profile completeness is not business verification." },
  indicators: {
    verification: { status: "not_submitted", isVerified: false, label: "Not submitted", explanation: "" },
    experience: { confirmedEvents: 0, distinctCities: 0, distinctOrganizers: 0, label: "No confirmed events yet" },
    feedback: { count: 0, displayAverage: false, average: null, label: "No reviews yet", method: "" },
    documents: { label: "No reviewed documents on file", currentTypes: [] },
    achievements: [],
    isNewVendor: true,
    newVendorNote: "New to LNKup — no reviews or completed events yet. That is not a negative signal.",
  },
  privacy: {
    publicProfile: true,
    showHistory: true,
    showReviews: true,
    showCities: true,
    showPortfolio: true,
    showSocials: true,
    hiddenEventIds: [],
  },
  requiredActions: [{ key: "profile_photos", label: "Add photos", detail: "Show your booth and products.", target: "portfolio" }],
  portfolio: [],
  capabilities: [],
  invitations: [],
}

export async function fetchMyPassport(auth?: WpAuth): Promise<MyPassport> {
  if (!isLiveApiEnabled()) return { ...MOCK_PASSPORT }
  return mapMyPassport(await wpFetchJson<Raw>("/passport/me", { auth }))
}

export async function updatePrivacy(input: Partial<Record<keyof Omit<PassportPrivacy, "hiddenEventIds">, boolean>> & { hiddenEventIds?: number[] }, auth?: WpAuth): Promise<{ privacy: PassportPrivacy; isPublic: boolean }> {
  const body: Raw = {}
  const keys: Record<string, string> = {
    publicProfile: "public_profile",
    showHistory: "show_history",
    showReviews: "show_reviews",
    showCities: "show_cities",
    showPortfolio: "show_portfolio",
    showSocials: "show_socials",
  }
  Object.entries(keys).forEach(([k, wire]) => {
    const value = (input as Record<string, unknown>)[k]
    if (typeof value === "boolean") body[wire] = value
  })
  if (input.hiddenEventIds) body.hidden_event_ids = input.hiddenEventIds
  if (!isLiveApiEnabled()) return { privacy: { ...MOCK_PASSPORT.privacy, ...input } as PassportPrivacy, isPublic: true }
  const res = await wpFetchJson<Raw>("/passport/me/privacy", { method: "PATCH", body, auth })
  return { privacy: mapPrivacy(obj(res.privacy)), isPublic: bool(res.is_public) }
}

export async function fetchPortfolio(auth?: WpAuth): Promise<{ items: PortfolioItem[]; tags: Record<string, string>; max: number }> {
  if (!isLiveApiEnabled()) return { items: [], tags: { products: "Products", booth: "Booth setup" }, max: 30 }
  const res = await wpFetchJson<Raw>("/passport/me/portfolio", { auth })
  const tags = obj(res.tags)
  return {
    items: mapPortfolio(res.items),
    tags: Object.fromEntries(Object.entries(tags).map(([k, v]) => [k, str(v)])),
    max: num(res.max) || 30,
  }
}

export async function savePortfolio(items: PortfolioItem[], auth?: WpAuth): Promise<PortfolioItem[]> {
  if (!isLiveApiEnabled()) return items
  const res = await wpFetchJson<Raw>("/passport/me/portfolio", {
    method: "PUT",
    body: {
      items: items.map((i) => ({
        attachment_id: i.attachmentId,
        caption: i.caption,
        alt: i.alt,
        tag: i.tag,
        is_cover: i.isCover,
      })),
    },
    auth,
  })
  return mapPortfolio(res.items)
}

export const MAX_PORTFOLIO_IMAGE_BYTES = 8 * 1024 * 1024

export async function uploadPortfolioImage(
  file: { uri: string; fileName: string; mimeType?: string },
  meta: { caption?: string; alt?: string },
  auth?: WpAuth,
): Promise<PortfolioItem[]> {
  if (!isLiveApiEnabled()) return []
  const form = new FormData()
  form.append("file", { uri: file.uri, name: file.fileName, type: file.mimeType || "image/jpeg" } as unknown as Blob)
  if (meta.caption) form.append("caption", meta.caption)
  if (meta.alt) form.append("alt", meta.alt)
  const res = await wpFetchFormData<Raw>("/passport/me/portfolio/upload", form, { auth })
  return mapPortfolio(res.items)
}

export async function deletePortfolioItem(attachmentId: number, auth?: WpAuth): Promise<PortfolioItem[]> {
  if (!isLiveApiEnabled()) return []
  const res = await wpFetchJson<Raw>(`/passport/me/portfolio/${attachmentId}`, { method: "DELETE", auth })
  return mapPortfolio(res.items)
}

export async function fetchMyHistory(page: number, auth?: WpAuth): Promise<HistoryPage> {
  if (!isLiveApiEnabled()) return { items: [], total: 0, page: 1, perPage: 20 }
  const res = await wpFetchJson<Raw>("/passport/me/history", { query: { page, per_page: 20 }, auth })
  return {
    items: arr(res.items).map((i) => ({
      eventId: num(i.event_id),
      eventTitle: str(i.event_title),
      eventDate: str(i.event_date),
      city: str(i.city),
      state: str(i.state),
      organizerName: str(i.organizer_name),
      tourTitle: str(i.tour_title),
      status: str(i.status),
      statusLabel: str(i.status_label),
      confirmed: bool(i.confirmed),
      evidence: str(i.evidence),
    })),
    total: num(res.total),
    page: num(res.page) || page,
    perPage: num(res.per_page) || 20,
  }
}

export async function fetchMyReviews(page: number, auth?: WpAuth): Promise<ReviewsPage> {
  if (!isLiveApiEnabled()) return { items: [], total: 0, page: 1, perPage: 10, summaryLabel: "No reviews yet", reportReasons: {} }
  const res = await wpFetchJson<Raw>("/passport/me/reviews", { query: { page }, auth })
  const reasons = obj(res.report_reasons)
  return {
    items: arr(res.items).map((r) => ({
      id: num(r.id),
      overallRating: num(r.overall_rating),
      publicText: str(r.public_text),
      eventTitle: str(r.event_title),
      organizerName: str(r.organizer_name),
      createdAt: str(r.created_at),
      verifiedParticipation: bool(r.verified_participation),
      underReview: bool(r.under_review),
      tags: arr(r.positive_tags).map((t) => str(t.label)),
      response: r.vendor_response ? str(obj(r.vendor_response).text) : null,
      canRespond: bool(r.can_respond),
      canReport: bool(r.can_report),
      disputes: arr(r.disputes).map((d) => ({ id: num(d.id), status: str(d.status), canAppeal: bool(d.can_appeal) })),
    })),
    total: num(res.total),
    page: num(res.page) || page,
    perPage: num(res.per_page) || 10,
    summaryLabel: str(obj(res.summary).label),
    reportReasons: Object.fromEntries(Object.entries(reasons).map(([k, v]) => [k, str(v)])),
  }
}

export async function respondToReview(reviewId: number, text: string, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled()) return
  await wpFetchJson(`/reputation/reviews/${reviewId}/response`, { method: "POST", body: { text }, auth })
}

export async function reportReview(reviewId: number, reasonCode: string, reason: string, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled()) return
  await wpFetchJson(`/reputation/reviews/${reviewId}/report`, { method: "POST", body: { reason_code: reasonCode, reason }, auth })
}

export async function appealDispute(disputeId: number, reason: string, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled()) return
  await wpFetchJson(`/reputation/disputes/${disputeId}/appeal`, { method: "POST", body: { reason }, auth })
}

export async function fetchVerification(auth?: WpAuth): Promise<{ records: VerificationRecord[]; canSubmit: boolean; summary: string }> {
  if (!isLiveApiEnabled()) return { records: [], canSubmit: false, summary: "" }
  const res = await wpFetchJson<Raw>("/passport/me/verification", { auth })
  const types = obj(res.types)
  const records = obj(res.records)
  return {
    records: Object.keys(records).map((type) => {
      const r = obj(records[type])
      return {
        type,
        label: str(types[type]) || type,
        status: str(r.status),
        statusLabel: str(r.label),
        expiresAt: r.expires_at ? str(r.expires_at) : null,
        reason: ["info_required", "rejected", "revoked"].includes(str(r.status)) ? str(r.reason) : "",
      }
    }),
    canSubmit: bool(res.can_submit),
    summary: str(obj(res.summary).explanation),
  }
}

export async function submitVerification(type: string, note: string, auth?: WpAuth): Promise<void> {
  if (!isLiveApiEnabled()) return
  await wpFetchJson("/passport/me/verification", { method: "POST", body: { type, note }, auth })
}

export type OrganizerPassport = {
  profileId: number
  businessName: string
  description: string
  logo: string
  city: string
  state: string
  categories: string[]
  portfolio: PortfolioItem[]
  capabilities: string[]
  indicators: PassportIndicators
  history: HistoryItem[]
  canViewContact: boolean
  contact: { email: string; phone: string } | null
  acceptsInvites: boolean
  publicUrl: string
}

export async function fetchOrganizerPassport(profileId: number, auth?: WpAuth): Promise<OrganizerPassport> {
  const res = await wpFetchJson<Raw>(`/passport/vendors/${profileId}/organizer`, { auth })
  const contact = res.contact ? obj(res.contact) : null
  return {
    profileId: num(res.profile_id),
    businessName: str(res.business_name),
    description: str(res.description),
    logo: str(res.logo),
    city: str(res.city),
    state: str(res.state),
    categories: arr(res.categories).map((c) => str(c.name)),
    portfolio: mapPortfolio(res.portfolio),
    capabilities: arr(res.capabilities).map((c) => str(c.label)),
    indicators: mapIndicators(obj(res.reputation)),
    history: arr(obj(res.history).items).map((i) => ({
      eventId: num(i.event_id),
      eventTitle: str(i.event_title),
      eventDate: str(i.event_date),
      city: str(i.city),
      state: str(i.state),
      organizerName: str(i.organizer_name),
      tourTitle: str(i.tour_title),
      status: str(i.status),
      statusLabel: str(i.status_label),
      confirmed: bool(i.confirmed),
      evidence: str(i.evidence),
    })),
    canViewContact: bool(res.can_view_contact),
    contact: contact ? { email: str(contact.email), phone: str(contact.phone) } : null,
    acceptsInvites: bool(obj(res.invite).accepts_invites),
    publicUrl: str(res.public_url),
  }
}

export type OrganizerTourOption = { id: number; title: string; stopCount: number }

export async function fetchMyTours(auth?: WpAuth): Promise<OrganizerTourOption[]> {
  const res = await wpFetchJson<Raw>("/tours", { auth })
  return arr(res.items)
    .map((t) => ({ id: num(t.id), title: str(t.title), stopCount: num(t.stop_count) }))
    .filter((t) => t.id > 0 && t.stopCount > 0)
}

export async function inviteToTour(profileId: number, tourId: number, eventIds: number[], message: string, auth?: WpAuth): Promise<{ created: number; skipped: string[] }> {
  const res = await wpFetchJson<Raw>(`/passport/vendors/${profileId}/invite-tour`, {
    method: "POST",
    body: { tour_id: tourId, event_ids: eventIds, message },
    auth,
  })
  return { created: arr(res.created).length, skipped: arr(res.skipped).map((s) => str(s.reason)) }
}
