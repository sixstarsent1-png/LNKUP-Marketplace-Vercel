export type WpOpportunityItem = {
  opportunity_id?: number
  id?: number
  event_id?: number
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
  excerpt?: string
  description?: string
  featured_image?: string
  organizer_name?: string
  organizer_verified?: boolean
  trending_score?: number
  is_open?: boolean
  application_status?: string
  indoor?: boolean
  outdoor?: boolean
  power_available?: boolean
  load_in_time?: string
  gallery?: Array<{
    id?: number
    url?: string
    mime?: string
    type?: string
    thumb?: string
  }>
}

export type WpSearchResponse = {
  items?: WpOpportunityItem[]
  total?: number
  page?: number
  pages?: number
  per_page?: number
  empty_state?: boolean
}

export type WpUserMe = {
  id: number
  name: string
  slug: string
  roles?: string[]
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
  info_request?: WpInfoRequestEntry | null
}

export type WpInfoRequestEntry = {
  type?: "request" | "response"
  message?: string
  documents?: unknown[]
  at?: string
}

export type WpVendorApplicationsResponse = {
  items?: WpVendorApplicationItem[]
  total?: number
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
  status?: string
  next_digest_at?: string
  filter_summary?: Array<{ key?: string; value?: string; label?: string }>
}

export type WpSavedSearchesResponse = {
  searches?: WpSavedSearchItem[]
  limit?: number
}
