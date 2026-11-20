import { getPublicWpSiteUrl, isLiveApiEnabled } from "@/lib/api/config"
import { adminMetrics } from "@/lib/data"

export type AdminDataMeta = {
  live: boolean
  mocked: boolean
  partial: boolean
  stale: boolean
  source: "mock" | "wordpress" | "mixed"
  updatedAt: string
  lastUpdatedAt: string
  unavailableReasons?: string[]
}

export type AdminSummaryKpis = {
  activeVendors: number
  activeOrganizers: number
  liveEvents: number
  applications: number
  paidBookings: number
  verificationQueue: number
  openDisputes: number
}

export type AdminQueueSnapshot = {
  externalEventsPendingReview: number
  verificationPending: number
  connectorsPendingApproval: number
}

export type AdminSummaryResponse = {
  meta: AdminDataMeta
  kpis: AdminSummaryKpis
  queue: AdminQueueSnapshot
  handoff: {
    wpAdminUrl: string
    externalEventsUrl: string
    verificationUrl: string
    connectorsUrl: string
  }
}

export type AdminHealthResponse = {
  meta: AdminDataMeta
  checks: Array<{
    id: string
    label: string
    status: "pass" | "warning" | "fail" | "not_configured"
    message?: string
    checkedAt: string
  }>
}

function nowIso() {
  return new Date().toISOString()
}

export function buildWpAdminHandoffUrls() {
  const site = getPublicWpSiteUrl()
  const adminBase = site ? `${site}/wp-admin/` : ""
  return {
    wpAdminUrl: adminBase,
    externalEventsUrl: adminBase ? `${adminBase}admin.php?page=lnkup-external-events` : "",
    verificationUrl: adminBase ? `${adminBase}admin.php?page=lnkup-verification` : "",
    connectorsUrl: adminBase ? `${adminBase}admin.php?page=lnkup-connectors` : "",
  }
}

export function getMockAdminSummary(): AdminSummaryResponse {
  const timestamp = nowIso()
  return {
    meta: {
      live: false,
      mocked: true,
      partial: false,
      stale: false,
      source: "mock",
      updatedAt: timestamp,
      lastUpdatedAt: timestamp,
    },
    kpis: {
      activeVendors: adminMetrics.vendorCount,
      activeOrganizers: adminMetrics.organizerCount,
      liveEvents: adminMetrics.liveEvents,
      applications: adminMetrics.totalApplications,
      paidBookings: adminMetrics.paidBookings,
      verificationQueue: adminMetrics.verificationQueue,
      openDisputes: adminMetrics.openDisputes,
    },
    queue: {
      externalEventsPendingReview: 12,
      verificationPending: adminMetrics.verificationQueue,
      connectorsPendingApproval: 3,
    },
    handoff: buildWpAdminHandoffUrls(),
  }
}

export function getMockAdminHealth(): AdminHealthResponse {
  const timestamp = nowIso()
  return {
    meta: {
      live: false,
      mocked: true,
      partial: false,
      stale: false,
      source: "mock",
      updatedAt: timestamp,
      lastUpdatedAt: timestamp,
    },
    checks: [
      {
        id: "live_api",
        label: "Live API mode",
        status: isLiveApiEnabled() ? "pass" : "not_configured",
        message: isLiveApiEnabled() ? "Live mode is enabled." : "Using fixture data.",
        checkedAt: timestamp,
      },
      {
        id: "wp_admin_url",
        label: "WordPress admin URL",
        status: getPublicWpSiteUrl() ? "pass" : "warning",
        message: getPublicWpSiteUrl() ? "WordPress site URL configured." : "Missing NEXT_PUBLIC_WORDPRESS_SITE_URL.",
        checkedAt: timestamp,
      },
    ],
  }
}

