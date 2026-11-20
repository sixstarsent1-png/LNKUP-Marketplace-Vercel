export type OrganizerRecruitReferral = {
  id: number
  referredName: string
  targetRole: string
  status: string
  expiresAt: string
  earnedTotal: number
}

export type OrganizerRecruitState = {
  available: boolean
  code: string
  shareUrl: string
  shareUrlOrganizer: string
  shareUrlVendor: string
  percent: number
  months: number
  headline: string
  message: string
  earningsTotal: number
  activeCount: number
  referrals: OrganizerRecruitReferral[]
}

type WpDashboard = {
  enabled?: boolean
  code?: string
  share_url?: string
  share_url_organizer?: string
  share_url_vendor?: string
  percent?: number
  months?: number
  headline?: string
  message?: string
  earnings_total?: number
  active_count?: number
  referrals?: Array<{
    id?: number
    referred_name?: string
    target_role?: string
    status?: string
    expires_at?: string
    earned_total?: number
  }>
}

export function mapOrganizerRecruit(raw: WpDashboard): OrganizerRecruitState {
  const shareOrganizer = raw.share_url_organizer || raw.share_url || ""
  const shareVendor = raw.share_url_vendor || ""
  return {
    available: raw.enabled !== false,
    code: raw.code ?? "",
    shareUrl: shareOrganizer,
    shareUrlOrganizer: shareOrganizer,
    shareUrlVendor: shareVendor,
    percent: Number(raw.percent ?? 20),
    months: Number(raw.months ?? 12),
    headline:
      raw.headline ??
      "Invite organizers or vendors — earn 20% of LNKup fees for 12 months",
    message:
      raw.message ??
      "Share a role-specific link. When they pay booth fees (vendors) or collect booth fees on their events (organizers), you earn a cut of LNKup’s fees for up to a year.",
    earningsTotal: Number(raw.earnings_total ?? 0),
    activeCount: Number(raw.active_count ?? 0),
    referrals: (raw.referrals ?? []).map((r) => ({
      id: Number(r.id ?? 0),
      referredName: r.referred_name ?? "Member",
      targetRole: r.target_role ?? "organizer",
      status: r.status ?? "expired",
      expiresAt: r.expires_at ?? "",
      earnedTotal: Number(r.earned_total ?? 0),
    })),
  }
}

const mockOrg =
  "https://thelnkup.com/sign-up/?org_ref=ORGDEMO123&interest=organizer"
const mockVendor =
  "https://thelnkup.com/sign-up/?org_ref=ORGDEMO123&interest=vendor"

export const mockOrganizerRecruit: OrganizerRecruitState = {
  available: true,
  code: "ORGDEMO123",
  shareUrl: mockOrg,
  shareUrlOrganizer: mockOrg,
  shareUrlVendor: mockVendor,
  percent: 20,
  months: 12,
  headline: "Invite organizers or vendors — earn 20% of LNKup fees for 12 months",
  message:
    "Share a role-specific link. When they pay booth fees (vendors) or collect booth fees on their events (organizers), you earn a cut of LNKup’s fees for up to a year.",
  earningsTotal: 0,
  activeCount: 0,
  referrals: [],
}

export const mockOrganizerRecruitUnavailable: OrganizerRecruitState = {
  ...mockOrganizerRecruit,
  available: false,
  code: "",
  shareUrl: "",
  shareUrlOrganizer: "",
  shareUrlVendor: "",
  message: "Referral Invites are unavailable right now. Try again later.",
}
