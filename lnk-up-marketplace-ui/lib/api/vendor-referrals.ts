import type { WpReferralCode } from "@/lib/api/types"

export type VendorReferralState = {
  available: boolean
  code?: string
  shareUrl?: string
  referralsCount?: number
  rewardsPending?: boolean
  message?: string
}

type WpReferralsResponse = {
  code?: WpReferralCode & { code?: string }
  referrals?: unknown[]
  rewards?: unknown[]
}

export function mapVendorReferrals(data: WpReferralsResponse | undefined): VendorReferralState {
  const code = data?.code?.code
  const referralsCount = data?.referrals?.length ?? 0
  const hasRewards = (data?.rewards?.length ?? 0) > 0

  if (!code) {
    return {
      available: false,
      message: "Referral invites are coming soon. Help more small businesses find event opportunities.",
    }
  }

  const base = process.env.NEXT_PUBLIC_WORDPRESS_SITE_URL?.replace(/\/$/, "") || ""
  const shareUrl = base ? `${base}/?ref=${encodeURIComponent(code)}` : undefined

  return {
    available: true,
    code,
    shareUrl,
    referralsCount,
    rewardsPending: hasRewards,
    message: hasRewards
      ? "Referral rewards unlock after paid bookings."
      : "Invite another vendor to LNKup.",
  }
}

export const mockVendorReferrals: VendorReferralState = {
  available: true,
  code: "SWEETHEAT",
  shareUrl: "https://thelnkup.com/?ref=SWEETHEAT",
  referralsCount: 2,
  rewardsPending: false,
  message: "Referral rewards unlock after paid bookings.",
}

export const mockVendorReferralsUnavailable: VendorReferralState = {
  available: false,
  message: "Referral invites are coming soon.",
}
