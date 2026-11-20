import { isLiveApiEnabled } from "@/lib/api/config"
import type { WpAuth } from "@/lib/api/wp-client"
import { wpFetchJson } from "@/lib/api/wp-client"

export type OrganizerRecruitReferral = {
  id: number
  referred_user_id: number
  referred_name: string
  target_role?: string
  status: "active" | "expired" | string
  lifecycle?: "awaiting_payment" | "earning" | "ended" | string
  expires_at: string
  earned_total: number
  attributed_revenue?: number
  created_at?: string
}

export type OrganizerRecruitDashboard = {
  enabled: boolean
  program: string
  code: string
  share_url: string
  share_url_organizer?: string
  share_url_vendor?: string
  percent: number
  months: number
  headline: string
  message: string
  referrals: OrganizerRecruitReferral[]
  rewards: Array<Record<string, unknown>>
  earnings_total: number
  active_count: number
}

const MOCK_DASHBOARD: OrganizerRecruitDashboard = {
  enabled: true,
  program: "organizer_recruit",
  code: "LNKUPDEMO",
  share_url: "https://thelnkup.com/sign-up/?org_ref=LNKUPDEMO&interest=organizer",
  share_url_organizer: "https://thelnkup.com/sign-up/?org_ref=LNKUPDEMO&interest=organizer",
  share_url_vendor: "https://thelnkup.com/sign-up/?org_ref=LNKUPDEMO&interest=vendor",
  percent: 20,
  months: 12,
  headline: "Invite organizers or vendors",
  message: "Credits apply to booth fees at checkout — not a bank withdrawal.",
  referrals: [
    {
      id: 1,
      referred_user_id: 0,
      referred_name: "Demo Organizer",
      target_role: "organizer",
      status: "active",
      lifecycle: "awaiting_payment",
      expires_at: "2027-08-01",
      earned_total: 0,
    },
  ],
  rewards: [],
  earnings_total: 0,
  active_count: 1,
}

function normalizeDashboard(snap: OrganizerRecruitDashboard): OrganizerRecruitDashboard {
  return {
    ...snap,
    enabled: snap.enabled !== false,
    code: snap.code || "",
    percent: Number(snap.percent) || 0,
    months: Number(snap.months) || 0,
    headline: snap.headline || "",
    message: snap.message || "",
    referrals: Array.isArray(snap.referrals) ? snap.referrals : [],
    earnings_total: Number(snap.earnings_total) || 0,
    active_count: Number(snap.active_count) || 0,
  }
}

export async function fetchOrganizerRecruitDashboard(
  auth: WpAuth | undefined,
): Promise<OrganizerRecruitDashboard> {
  if (!isLiveApiEnabled()) {
    return normalizeDashboard({
      ...MOCK_DASHBOARD,
      referrals: [...MOCK_DASHBOARD.referrals],
    })
  }
  const snap = await wpFetchJson<OrganizerRecruitDashboard>("/referrals/organizer-recruit", {
    method: "GET",
    auth,
  })
  return normalizeDashboard(snap)
}

export async function ensureOrganizerRecruitCode(auth: WpAuth | undefined): Promise<{
  code: string
  share_url: string
  share_url_organizer?: string
  share_url_vendor?: string
  program: string
}> {
  if (!isLiveApiEnabled()) {
    return {
      code: MOCK_DASHBOARD.code,
      share_url: MOCK_DASHBOARD.share_url,
      share_url_organizer: MOCK_DASHBOARD.share_url_organizer,
      share_url_vendor: MOCK_DASHBOARD.share_url_vendor,
      program: MOCK_DASHBOARD.program,
    }
  }
  return wpFetchJson("/referrals/organizer-recruit/code", {
    method: "POST",
    auth,
    body: {},
  })
}
