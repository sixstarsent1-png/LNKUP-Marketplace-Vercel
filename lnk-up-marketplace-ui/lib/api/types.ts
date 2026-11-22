export type WpOpportunityItem = {
  opportunity_id?: number
  id?: number
  item_type?: string
  name?: string
  event_name?: string
  city?: string
  state?: string
  venue_name?: string
  date?: string
  event_date?: string
  attendance?: number
  vendor_fee?: number
  spots_remaining?: number
  total_spots?: number
  categories?: string[]
  categories_needed?: string[]
  deadline?: string
  deadline_label?: string
  featured_image?: string
  gallery?: Array<{
    id?: number
    url?: string
    mime?: string
    type?: string
    thumb?: string
  }>
  organizer_name?: string
  organizer_verified?: boolean
  trending_score?: number
  badges?: string[]
  application_count?: number
  approved_vendors?: number
  paid_vendors?: number
  is_open?: boolean
}

export type WpSearchResponse = {
  items?: WpOpportunityItem[]
  total?: number
  page?: number
  pages?: number
  per_page?: number
  empty_state?: boolean
  active_filters?: Array<{ key: string; value: string; label: string }>
  has_external?: boolean
}

export type WpPreviousApplication = {
  id: number
  status: string
  reapply: "allowed" | "blocked"
  reason: string
}

export type WpApplyEligibility = {
  can_apply?: boolean
  block_reason?: string
  profile_id?: number
  existing_application_id?: number
  existing_application_status?: string
  previous_application?: WpPreviousApplication
}

export type WpApplicationQuestion = {
  id: string
  label: string
  type: "text" | "textarea" | "select" | "checkbox" | "file"
  required: boolean
  options: string[]
  help: string
}

export type WpApplyDraftSummary = {
  id: number
  revision: number
  step: string
  updated_at: string
  fields?: { selected_category?: string; application_message?: string; setup_notes?: string }
  answers?: Record<string, unknown>
  document_ids?: number[]
  price?: { quoted: number; current: number; changed: boolean }
}

export type WpApplyFeatures = {
  drafts: boolean
  tour_multi_apply: boolean
}

export type WpButtonState = {
  label?: string
  disabled?: boolean
  reason?: string
}

export type WpProfileSnapshot = {
  business_name?: string
  city?: string
  state?: string
  category?: string[]
  logo_url?: string
  verified?: boolean
}

export type WpApplyContext = {
  opportunity?: WpOpportunityItem & {
    opportunity_id?: number
    categories_needed?: string[]
  }
  eligibility?: WpApplyEligibility
  profile?: WpProfileSnapshot | null
  categories?: string[]
  default_category?: string
  button_state?: WpButtonState
  warnings?: string[]
  questions?: WpApplicationQuestion[]
  vendor_documents?: Array<{ id: number; label: string }>
  documents_required_to_apply?: boolean
  draft?: WpApplyDraftSummary | null
  previous_application?: WpPreviousApplication | null
  tour_id?: number
  features?: WpApplyFeatures
}

export type WpApplyResult = {
  success?: boolean
  application_id?: number
  status?: string
  message?: string
  redirect_url?: string
  warnings?: string[]
}

export type WpUserMe = {
  id: number
  name: string
  slug: string
}

export type WpApiError = {
  code: string
  message: string
  status?: number
  data?: Record<string, unknown>
}

export type WpVendorApplicationItem = {
  application_id?: number
  id?: number
  opportunity_id?: number
  event_id?: number
  event_name?: string
  event_date?: string
  city?: string
  state?: string
  organizer_name?: string
  category?: string
  status?: string
  submitted_at?: string
  approved_at?: string
  payment_due_at?: string
  amount_due?: number
  currency?: string
  vendor_fee?: number
  featured_image?: string
  payment_id?: number
  payment_status?: string
  rejection_reason?: string
}

export type WpVendorApplicationsResponse = {
  items?: WpVendorApplicationItem[]
  total?: number
}

export type WpVerificationVendor = {
  level?: string
  label?: string
  percent_complete?: number
  badges?: string[]
  badge_details?: Array<{ label?: string; key?: string }>
  missing_items?: string[] | Array<{ label?: string; title?: string; href?: string }>
  recommended_items?: string[] | Array<{ label?: string; title?: string }>
  next_action?: string | { label?: string; href?: string; title?: string }
  required_documents?: Array<{ type?: string; label?: string; status?: string }>
  profile_id?: number
}

export type WpProfileStrengthSection = {
  key?: string
  title?: string
  complete?: boolean
  points_earned?: number
  max_points?: number
  missing?: string[]
  actions?: Array<{
    id?: string
    label?: string
    description?: string
    href?: string
    priority?: number
    complete?: boolean
  }>
}

export type WpProfileStrength = {
  score?: number
  tier?: string
  completed_sections?: number
  total_sections?: number
  missing_items?: string[]
  recommended_actions?: Array<{
    id?: string
    label?: string
    description?: string
    href?: string
  }>
  sections?: WpProfileStrengthSection[]
  profile_id?: number
}

export type WpVendorDocumentItem = {
  id?: number
  type?: string
  label?: string
  status?: string
  verification?: string
  required?: boolean
  file_name?: string
  uploaded_at?: string
  expires_at?: string
  rejection_reason?: string
  download_url?: string
}

export type WpVendorDocumentsResponse = {
  items?: WpVendorDocumentItem[]
  compliance?: {
    total?: number
    verified?: number
    attention_count?: number
  }
  total?: number
}

export type WpFavoritesResponse = {
  favorites?: {
    opportunities?: number[]
    events?: number[]
    organizers?: number[]
  }
  items?: WpOpportunityItem[]
}

export type WpSavedSearchItem = {
  id?: string
  name?: string
  filters?: Record<string, unknown>
  frequency?: string
  alert_enabled?: boolean
  created_at?: string
  last_checked_at?: string
  new_match_count?: number
  is_paused?: boolean
  status?: "active" | "paused" | "deleted" | string
  next_digest_at?: string
  filter_summary?: Array<{ key?: string; value?: string; label?: string }>
}

export type WpSavedSearchesResponse = {
  searches?: WpSavedSearchItem[]
  limit?: number
}

export type WpReferralCode = {
  id?: number
  user_id?: number
  code?: string
  role_type?: string
  is_active?: boolean
  created_at?: string
}
