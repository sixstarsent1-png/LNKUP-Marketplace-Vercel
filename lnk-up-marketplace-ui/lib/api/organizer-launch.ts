import { z } from "zod"

export type LaunchFeeRow = {
  id: string
  category: string
  vendorFee: number
  spotsTotal: number
}

export type OrganizerLaunchDraft = {
  event: {
    title: string
    date?: string
    startTime?: string
    endTime?: string
    city?: string
    state?: string
    venueName?: string
    venueAddress?: string
    eventType?: string
    expectedAttendance?: number
    imageUrl?: string
    externalUrl?: string
    description?: string
  }
  opportunity: {
    categories?: string[]
    vendorFee?: number
    currency?: string
    spotsTotal?: number
    applicationDeadline?: string
    indoor?: boolean
    outdoor?: boolean
    requiredDocumentsMode?: "none" | "platform_defaults" | "custom"
    customRequiredDocuments?: string[]
    applicationInstructions?: string
    /** Per vendor-type booth prices (preferred over single fee fields). */
    feeRows?: LaunchFeeRow[]
  }
  status: "draft" | "ready_to_publish" | "published" | "handoff_required" | "error"
  wordpressUrl?: string
  raw?: unknown
}

export type OrganizerLaunchPublishResult = {
  status:
    | "published"
    | "handoff_required"
    | "validation_error"
    | "auth_required"
    | "forbidden"
    | "error"
  eventId?: string
  opportunityId?: string
  eventUrl?: string
  opportunityUrl?: string
  applicationUrl?: string
  wordpressUrl?: string
  message?: string
  validationErrors?: Array<{ field: string; message: string }>
  raw?: unknown
}

export type WpLaunchOpportunityRow = {
  categories: string[]
  vendor_fee: number
  spots_total: number
  opportunity_title: string
  vendor_category: string
}

const feeRowSchema = z.object({
  id: z.string().optional(),
  category: z.string().min(1, "Category is required"),
  vendorFee: z.number().min(0, "Vendor fee is required unless free"),
  spotsTotal: z.number().min(1, "Number of spots is required"),
})

export const launchDraftSchema = z
  .object({
    event: z.object({
      title: z.string().min(1, "Event title is required"),
      date: z.string().min(1, "Event date is required"),
      city: z.string().min(1, "City is required"),
      state: z.string().min(1, "State is required"),
      startTime: z.string().optional(),
      endTime: z.string().optional(),
      venueName: z.string().optional(),
      venueAddress: z.string().optional(),
      eventType: z.string().optional(),
      expectedAttendance: z.number().optional(),
      imageUrl: z.string().optional(),
      externalUrl: z.string().optional(),
      description: z.string().optional(),
    }),
    opportunity: z.object({
      categories: z.array(z.string()).optional(),
      vendorFee: z.number().min(0).optional(),
      currency: z.string().optional(),
      spotsTotal: z.number().min(1).optional(),
      applicationDeadline: z.string().optional(),
      indoor: z.boolean().optional(),
      outdoor: z.boolean().optional(),
      requiredDocumentsMode: z.enum(["none", "platform_defaults", "custom"]).optional(),
      customRequiredDocuments: z.array(z.string()).optional(),
      applicationInstructions: z.string().optional(),
      feeRows: z.array(feeRowSchema).optional(),
    }),
  })
  .superRefine((draft, ctx) => {
    const rows = normalizeFeeRows(draft as OrganizerLaunchDraft)
    if (rows.length === 0) {
      ctx.addIssue({
        code: z.ZodIssueCode.custom,
        path: ["opportunity", "feeRows"],
        message: "Add at least one vendor type with a booth price",
      })
    }
  })

export function createFeeRow(partial?: Partial<LaunchFeeRow>): LaunchFeeRow {
  return {
    id: partial?.id ?? `fee-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    category: partial?.category ?? "",
    vendorFee: partial?.vendorFee ?? 0,
    spotsTotal: partial?.spotsTotal ?? 1,
  }
}

/** Prefer explicit feeRows; otherwise derive one row from legacy single-fee fields. */
export function normalizeFeeRows(draft: OrganizerLaunchDraft): LaunchFeeRow[] {
  const rows = draft.opportunity.feeRows?.filter((row) => row.category.trim()) ?? []
  if (rows.length > 0) return rows

  const categories = draft.opportunity.categories?.filter((c) => c.trim()) ?? []
  if (categories.length === 0) return []

  return categories.map((category, index) =>
    createFeeRow({
      id: `legacy-${index}`,
      category,
      vendorFee: draft.opportunity.vendorFee ?? 0,
      spotsTotal: draft.opportunity.spotsTotal ?? 1,
    }),
  )
}

export function feeRowsToWpOpportunities(
  draft: OrganizerLaunchDraft,
): WpLaunchOpportunityRow[] {
  return normalizeFeeRows(draft).map((row) => {
    const category = row.category.trim()
    return {
      categories: [category],
      vendor_fee: row.vendorFee,
      spots_total: row.spotsTotal,
      opportunity_title: category,
      vendor_category: category,
    }
  })
}

export function draftToWpPayload(draft: OrganizerLaunchDraft): Record<string, unknown> {
  const opportunities = feeRowsToWpOpportunities(draft)
  const primary = opportunities[0]

  return {
    event_name: draft.event.title,
    event_date: draft.event.date,
    start_time: draft.event.startTime,
    end_time: draft.event.endTime,
    city: draft.event.city,
    state: draft.event.state,
    venue_name: draft.event.venueName,
    venue_address: draft.event.venueAddress,
    event_type: draft.event.eventType,
    expected_attendance: draft.event.expectedAttendance,
    description: draft.event.description,
    ticketing_url: draft.event.externalUrl,
    vendor_fee: primary?.vendor_fee ?? draft.opportunity.vendorFee,
    spots_total: primary?.spots_total ?? draft.opportunity.spotsTotal,
    application_deadline: draft.opportunity.applicationDeadline,
    indoor: draft.opportunity.indoor,
    outdoor: draft.opportunity.outdoor,
    categories: opportunities.flatMap((row) => row.categories),
    organizer_notes: draft.opportunity.applicationInstructions,
    opportunity_title: primary?.opportunity_title,
    document_mode: draft.opportunity.requiredDocumentsMode ?? "platform_defaults",
    opportunities,
  }
}

export function validateLaunchDraft(draft: OrganizerLaunchDraft): Array<{ field: string; message: string }> {
  const result = launchDraftSchema.safeParse(draft)
  if (result.success) return []
  return result.error.issues.map((issue) => ({
    field: issue.path.join("."),
    message: issue.message,
  }))
}

export function hasMeaningfulLaunchDraft(draft: OrganizerLaunchDraft | null | undefined): boolean {
  if (!draft) return false
  const { event, opportunity } = draft
  const feeRows = normalizeFeeRows(draft)
  return Boolean(
    event.title?.trim() ||
      event.city?.trim() ||
      event.state?.trim() ||
      event.date?.trim() ||
      feeRows.length > 0 ||
      (opportunity.categories?.length ?? 0) > 0 ||
      (opportunity.vendorFee ?? 0) > 0 ||
      (opportunity.spotsTotal ?? 0) > 1,
  )
}

export function launchDraftFromOnboarding(
  onboarding: {
    draft?: {
      organizationName?: string
      eventType?: string
      city?: string
      state?: string
      categories?: string[]
      vendorFee?: number
      spotsTotal?: number
      applicationDeadline?: string
      documentsMode?: string
    }
  } | null,
): OrganizerLaunchDraft | null {
  if (!onboarding?.draft) return null
  const d = onboarding.draft
  const hasFields =
    d.organizationName ||
    d.eventType ||
    d.city ||
    d.state ||
    (d.categories?.length ?? 0) > 0 ||
    d.vendorFee ||
    d.spotsTotal
  if (!hasFields) return null

  const docMode = d.documentsMode as OrganizerLaunchDraft["opportunity"]["requiredDocumentsMode"]
  const categories = d.categories ?? []
  const feeRows =
    categories.length > 0
      ? categories.map((category, index) =>
          createFeeRow({
            id: `onboarding-${index}`,
            category,
            vendorFee: d.vendorFee ?? 0,
            spotsTotal: d.spotsTotal ?? 1,
          }),
        )
      : [createFeeRow({ vendorFee: d.vendorFee ?? 0, spotsTotal: d.spotsTotal ?? 1 })]

  return {
    event: {
      title: d.organizationName ? `${d.organizationName} Event` : "",
      city: d.city,
      state: d.state,
      eventType: d.eventType,
    },
    opportunity: {
      categories,
      vendorFee: d.vendorFee ?? 0,
      spotsTotal: d.spotsTotal ?? 1,
      applicationDeadline: d.applicationDeadline,
      requiredDocumentsMode:
        docMode === "none" || docMode === "custom" ? docMode : "platform_defaults",
      feeRows,
    },
    status: "draft",
  }
}

export function emptyLaunchDraft(): OrganizerLaunchDraft {
  return {
    event: { title: "" },
    opportunity: {
      categories: [],
      vendorFee: 0,
      spotsTotal: 1,
      requiredDocumentsMode: "platform_defaults",
      feeRows: [createFeeRow()],
    },
    status: "draft",
  }
}

export function buildMockLaunchDraft(): OrganizerLaunchDraft {
  return {
    event: {
      title: "Charlotte Night Market",
      date: "2026-09-15",
      city: "Charlotte",
      state: "NC",
      venueName: "Uptown Green",
      eventType: "Market",
      expectedAttendance: 5000,
      description: "Monthly night market featuring local vendors.",
    },
    opportunity: {
      categories: ["Food Vendor", "Retail"],
      vendorFee: 200,
      currency: "USD",
      spotsTotal: 20,
      applicationDeadline: "2026-09-01",
      indoor: false,
      outdoor: true,
      requiredDocumentsMode: "platform_defaults",
      applicationInstructions: "Bring your menu and photos of your setup.",
      feeRows: [
        createFeeRow({ id: "mock-1", category: "Food Vendor", vendorFee: 250, spotsTotal: 12 }),
        createFeeRow({ id: "mock-2", category: "Retail", vendorFee: 150, spotsTotal: 8 }),
      ],
    },
    status: "ready_to_publish",
  }
}

export function mockPublishResult(draft: OrganizerLaunchDraft): OrganizerLaunchPublishResult {
  return {
    status: "published",
    eventId: "charlotte-night-market",
    opportunityId: "101",
    eventUrl: "/organizer/events/charlotte-night-market",
    applicationUrl: "/vendor/find?city=Charlotte&category=Food%20Vendor",
    wordpressUrl: "https://thelnkup.com/organizer-dashboard/?view=operations&event=charlotte-night-market",
    message: `Published ${draft.event.title}`,
  }
}
