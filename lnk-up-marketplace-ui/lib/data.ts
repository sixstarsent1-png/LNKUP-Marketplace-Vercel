export type Role = "vendor" | "organizer" | "admin"

export type RequestStatus =
  | "under-review"
  | "approved"
  | "payment-due"
  | "paid"
  | "waitlisted"
  | "rejected"

export const vendorCategories = [
  "Food Vendor",
  "Food Truck",
  "Dessert Vendor",
  "Beverage Vendor",
  "Retail Vendor",
  "Clothing Brand",
  "Beauty Vendor",
  "Jewelry Vendor",
  "Art Vendor",
  "Photo Booth Vendor",
  "Wellness Vendor",
  "Service Vendor",
  "Mobile Business",
] as const

export type EventItem = {
  id: string
  /** WordPress opportunity post ID when loaded from live API */
  opportunityId?: number
  name: string
  image: string
  /** "contain" for flyers/logos (show the whole artwork on imageBackground). */
  imageFit?: "cover" | "contain"
  imageBackground?: string
  imageAlt?: string
  date: string
  city: string
  state: string
  venue: string
  organizer: string
  organizerVerified: boolean
  expectedAttendance: number
  vendorFee: number | null
  spotsRemaining: number
  totalSpots: number
  categoriesNeeded: string[]
  deadline: string
  trending?: boolean
  readiness: number
  vendorRequests: number
  approvedVendors: number
  paidVendors: number
  revenue: number
  status: "draft" | "live" | "closed"
  eventType?: string
  documentsRequired?: boolean
  indoor?: boolean
  outdoor?: boolean
  applicationStatus?: string
  gallery?: Array<{
    id: number
    url: string
    type: "image" | "video"
    thumb?: string
  }>
}

export const events: EventItem[] = [
  {
    id: "charlotte-night-market",
    name: "Charlotte Night Market",
    image: "/night-market-string-lights-food-stalls.png",
    date: "Sat, Jul 18, 2026",
    city: "Charlotte",
    state: "NC",
    venue: "Camp North End",
    organizer: "CultureWave Events",
    organizerVerified: true,
    expectedAttendance: 4200,
    vendorFee: 185,
    spotsRemaining: 6,
    totalSpots: 40,
    categoriesNeeded: ["Food Vendor", "Dessert Vendor", "Retail Vendor", "Art Vendor"],
    deadline: "Jul 5",
    trending: true,
    readiness: 82,
    vendorRequests: 34,
    approvedVendors: 28,
    paidVendors: 22,
    revenue: 4070,
    status: "live",
  },
  {
    id: "atlanta-food-fest",
    name: "Atlanta Food Fest",
    image: "/outdoor-food-festival-crowd-daytime.png",
    date: "Sun, Aug 2, 2026",
    city: "Atlanta",
    state: "GA",
    venue: "Piedmont Park",
    organizer: "Southern Social Co.",
    organizerVerified: true,
    expectedAttendance: 9800,
    vendorFee: 320,
    spotsRemaining: 12,
    totalSpots: 60,
    categoriesNeeded: ["Food Truck", "Food Vendor", "Beverage Vendor"],
    deadline: "Jul 20",
    trending: true,
    readiness: 64,
    vendorRequests: 51,
    approvedVendors: 38,
    paidVendors: 26,
    revenue: 8320,
    status: "live",
  },
  {
    id: "miami-culture-popup",
    name: "Miami Culture Pop-Up",
    image: "/vibrant-street-culture-popup-market-miami.png",
    date: "Fri, Aug 15, 2026",
    city: "Miami",
    state: "FL",
    venue: "Wynwood Yard",
    organizer: "MarketHouse Collective",
    organizerVerified: false,
    expectedAttendance: 3100,
    vendorFee: 210,
    spotsRemaining: 3,
    totalSpots: 30,
    categoriesNeeded: ["Clothing Brand", "Beauty Vendor", "Jewelry Vendor", "Art Vendor"],
    deadline: "Aug 1",
    readiness: 48,
    vendorRequests: 27,
    approvedVendors: 19,
    paidVendors: 14,
    revenue: 2940,
    status: "live",
  },
  {
    id: "raleigh-homecoming-market",
    name: "Raleigh Homecoming Market",
    image: "/homecoming-market-vendors-tents-sunny.png",
    date: "Sat, Sep 5, 2026",
    city: "Raleigh",
    state: "NC",
    venue: "Dorothea Dix Park",
    organizer: "Block Party Group",
    organizerVerified: true,
    expectedAttendance: 5600,
    vendorFee: 240,
    spotsRemaining: 18,
    totalSpots: 45,
    categoriesNeeded: ["Food Vendor", "Retail Vendor", "Wellness Vendor", "Service Vendor"],
    deadline: "Aug 22",
    readiness: 35,
    vendorRequests: 16,
    approvedVendors: 9,
    paidVendors: 5,
    revenue: 1200,
    status: "live",
  },
  {
    id: "greensboro-summer-block-party",
    name: "Greensboro Summer Block Party",
    image: "/summer-block-party-street-festival-evening.png",
    date: "Sat, Jul 25, 2026",
    city: "Greensboro",
    state: "NC",
    venue: "LeBauer Park",
    organizer: "CultureWave Events",
    organizerVerified: true,
    expectedAttendance: 2800,
    vendorFee: 150,
    spotsRemaining: 0,
    totalSpots: 25,
    categoriesNeeded: ["Food Truck", "Dessert Vendor", "Beverage Vendor"],
    deadline: "Closed",
    readiness: 95,
    vendorRequests: 41,
    approvedVendors: 25,
    paidVendors: 25,
    revenue: 3750,
    status: "closed",
  },
  {
    id: "houston-rodeo-weekend-market",
    name: "Houston Rodeo Weekend Market",
    image: "/rodeo-weekend-outdoor-market-texas.png",
    date: "Sun, Sep 20, 2026",
    city: "Houston",
    state: "TX",
    venue: "NRG Park Grounds",
    organizer: "Southern Social Co.",
    organizerVerified: true,
    expectedAttendance: 12500,
    vendorFee: 395,
    spotsRemaining: 22,
    totalSpots: 80,
    categoriesNeeded: ["Food Vendor", "Food Truck", "Retail Vendor", "Clothing Brand", "Mobile Business"],
    deadline: "Sep 6",
    trending: true,
    readiness: 41,
    vendorRequests: 63,
    approvedVendors: 44,
    paidVendors: 31,
    revenue: 12245,
    status: "live",
  },
  {
    id: "dc-greek-picnic-vendor-row",
    name: "DC Greek Picnic Vendor Row",
    image: "/homecoming-market-vendors-tents-sunny.png",
    date: "Sat, Aug 8, 2026",
    city: "Washington",
    state: "DC",
    venue: "RFK Festival Grounds",
    organizer: "Campus Weekend Live",
    organizerVerified: true,
    expectedAttendance: 7200,
    vendorFee: 275,
    spotsRemaining: 9,
    totalSpots: 35,
    categoriesNeeded: ["Food Vendor", "Clothing Brand", "Beauty Vendor", "Wellness Vendor"],
    deadline: "Jul 28",
    trending: true,
    readiness: 58,
    vendorRequests: 38,
    approvedVendors: 22,
    paidVendors: 16,
    revenue: 4400,
    status: "live",
  },
]

export type Vendor = {
  id: string
  name: string
  category: string
  city: string
  state: string
  rating: number
  reviews: number
  completedEvents: number
  verified: boolean
  documentsVerified: boolean
  insured: boolean
  avatarColor: string
  initials: string
}

export const vendors: Vendor[] = [
  {
    id: "sweet-heat-bbq",
    name: "Sweet Heat BBQ",
    category: "Food Vendor",
    city: "Charlotte",
    state: "NC",
    rating: 4.9,
    reviews: 128,
    completedEvents: 34,
    verified: true,
    documentsVerified: true,
    insured: true,
    avatarColor: "bg-primary",
    initials: "SH",
  },
  {
    id: "glow-beauty-bar",
    name: "Glow Beauty Bar",
    category: "Beauty Vendor",
    city: "Atlanta",
    state: "GA",
    rating: 4.8,
    reviews: 96,
    completedEvents: 21,
    verified: true,
    documentsVerified: true,
    insured: false,
    avatarColor: "bg-primary",
    initials: "GB",
  },
  {
    id: "city-drip-apparel",
    name: "City Drip Apparel",
    category: "Clothing Brand",
    city: "Miami",
    state: "FL",
    rating: 4.7,
    reviews: 74,
    completedEvents: 18,
    verified: true,
    documentsVerified: false,
    insured: true,
    avatarColor: "bg-primary",
    initials: "CD",
  },
  {
    id: "fresh-press-lemonade",
    name: "Fresh Press Lemonade",
    category: "Beverage Vendor",
    city: "Raleigh",
    state: "NC",
    rating: 4.9,
    reviews: 152,
    completedEvents: 47,
    verified: true,
    documentsVerified: true,
    insured: true,
    avatarColor: "bg-primary",
    initials: "FP",
  },
  {
    id: "rolling-dessert-co",
    name: "The Rolling Dessert Co.",
    category: "Dessert Vendor",
    city: "Greensboro",
    state: "NC",
    rating: 4.6,
    reviews: 63,
    completedEvents: 12,
    verified: false,
    documentsVerified: false,
    insured: false,
    avatarColor: "bg-primary",
    initials: "RD",
  },
  {
    id: "lensloop-photo-booth",
    name: "LensLoop Photo Booth",
    category: "Photo Booth Vendor",
    city: "Houston",
    state: "TX",
    rating: 4.8,
    reviews: 88,
    completedEvents: 29,
    verified: true,
    documentsVerified: true,
    insured: true,
    avatarColor: "bg-primary",
    initials: "LL",
  },
  {
    id: "aura-wellness-studio",
    name: "Aura Wellness Studio",
    category: "Wellness Vendor",
    city: "Washington",
    state: "DC",
    rating: 4.8,
    reviews: 41,
    completedEvents: 9,
    verified: true,
    documentsVerified: true,
    insured: true,
    avatarColor: "bg-primary",
    initials: "AW",
  },
]

export type VendorRequest = {
  id: string
  eventId: string
  eventName: string
  eventImage: string
  date: string
  city: string
  organizer: string
  status: RequestStatus
  category: string
  vendorFee: number
  submittedAt: string
  paymentId?: string
  paymentDueAt?: string
  reason?: string
}

export const vendorRequests: VendorRequest[] = [
  {
    id: "req-1",
    eventId: "charlotte-night-market",
    eventName: "Charlotte Night Market",
    eventImage: "/night-market-string-lights-food-stalls.png",
    date: "Sat, Jul 18, 2026",
    city: "Charlotte, NC",
    organizer: "CultureWave Events",
    status: "paid",
    category: "Food Vendor",
    vendorFee: 185,
    submittedAt: "Jun 20",
    paymentId: "pay-mock-1",
  },
  {
    id: "req-2",
    eventId: "atlanta-food-fest",
    eventName: "Atlanta Food Fest",
    eventImage: "/outdoor-food-festival-crowd-daytime.png",
    date: "Sun, Aug 2, 2026",
    city: "Atlanta, GA",
    organizer: "Southern Social Co.",
    status: "payment-due",
    category: "Food Truck",
    vendorFee: 320,
    submittedAt: "Jun 24",
    paymentId: "pay-mock-2",
    paymentDueAt: "Aug 1, 2026",
  },
  {
    id: "req-3",
    eventId: "miami-culture-popup",
    eventName: "Miami Culture Pop-Up",
    eventImage: "/vibrant-street-culture-popup-market-miami.png",
    date: "Fri, Aug 15, 2026",
    city: "Miami, FL",
    organizer: "MarketHouse Collective",
    status: "approved",
    category: "Retail Vendor",
    vendorFee: 210,
    submittedAt: "Jun 26",
  },
  {
    id: "req-4",
    eventId: "raleigh-homecoming-market",
    eventName: "Raleigh Homecoming Market",
    eventImage: "/homecoming-market-vendors-tents-sunny.png",
    date: "Sat, Sep 5, 2026",
    city: "Raleigh, NC",
    organizer: "Block Party Group",
    status: "under-review",
    category: "Food Vendor",
    vendorFee: 240,
    submittedAt: "Jun 28",
  },
  {
    id: "req-5",
    eventId: "houston-rodeo-weekend-market",
    eventName: "Houston Rodeo Weekend Market",
    eventImage: "/rodeo-weekend-outdoor-market-texas.png",
    date: "Sun, Sep 20, 2026",
    city: "Houston, TX",
    organizer: "Southern Social Co.",
    status: "waitlisted",
    category: "Food Vendor",
    vendorFee: 395,
    submittedAt: "Jun 22",
    reason: "Category full — you're #3 on the waitlist.",
  },
  {
    id: "req-6",
    eventId: "greensboro-summer-block-party",
    eventName: "Greensboro Summer Block Party",
    eventImage: "/summer-block-party-street-festival-evening.png",
    date: "Sat, Jul 25, 2026",
    city: "Greensboro, NC",
    organizer: "CultureWave Events",
    status: "rejected",
    category: "Dessert Vendor",
    vendorFee: 150,
    submittedAt: "Jun 15",
    reason: "Organizer selected a different dessert vendor for this event.",
  },
]

// Requests from the organizer's perspective (vendors applying to their event)
export type IncomingRequest = {
  id: string
  vendorId: string
  vendorName: string
  initials: string
  category: string
  city: string
  rating: number
  completedEvents: number
  verified: boolean
  message: string
  status: "under-review" | "approved" | "payment-due" | "paid" | "waitlisted" | "rejected"
  eventId?: string
  eventName?: string
  opportunityId?: string
  vendorProfileId?: string
  paymentStatus?: string
}

export const incomingRequests: IncomingRequest[] = [
  {
    id: "in-1",
    vendorId: "sweet-heat-bbq",
    vendorName: "Sweet Heat BBQ",
    initials: "SH",
    category: "Food Vendor",
    city: "Charlotte, NC",
    rating: 4.9,
    completedEvents: 34,
    verified: true,
    message: "We'd love to bring our smoked brisket and ribs. We've done 3 CultureWave events before.",
    status: "under-review",
  },
  {
    id: "in-2",
    vendorId: "fresh-press-lemonade",
    vendorName: "Fresh Press Lemonade",
    initials: "FP",
    category: "Beverage Vendor",
    city: "Raleigh, NC",
    rating: 4.9,
    completedEvents: 47,
    verified: true,
    message: "Fresh squeezed lemonade and iced teas. Fully insured, health permit ready.",
    status: "under-review",
  },
  {
    id: "in-3",
    vendorId: "rolling-dessert-co",
    vendorName: "The Rolling Dessert Co.",
    initials: "RD",
    category: "Dessert Vendor",
    city: "Greensboro, NC",
    rating: 4.6,
    completedEvents: 12,
    verified: false,
    message: "Rolled ice cream and mini donuts. First time applying — excited to join!",
    status: "under-review",
  },
  {
    id: "in-4",
    vendorId: "city-drip-apparel",
    vendorName: "City Drip Apparel",
    initials: "CD",
    category: "Clothing Brand",
    city: "Miami, FL",
    rating: 4.7,
    completedEvents: 18,
    verified: true,
    message: "Streetwear and custom tees. We bring high foot traffic to markets.",
    status: "approved",
    eventId: "charlotte-night-market",
    eventName: "Charlotte Night Market",
    vendorProfileId: "city-drip-apparel",
  },
  {
    id: "in-5",
    vendorId: "taco-temple",
    vendorName: "Taco Temple",
    initials: "TT",
    category: "Food Truck",
    city: "Atlanta, GA",
    rating: 4.8,
    completedEvents: 22,
    verified: true,
    message: "Gourmet tacos with local ingredients.",
    status: "payment-due",
    eventId: "atlanta-food-fest",
    eventName: "Atlanta Food Fest",
    vendorProfileId: "taco-temple",
    paymentStatus: "pending",
  },
  {
    id: "in-6",
    vendorId: "velvet-scoops",
    vendorName: "Velvet Scoops",
    initials: "VS",
    category: "Dessert Vendor",
    city: "Charlotte, NC",
    rating: 4.9,
    completedEvents: 41,
    verified: true,
    message: "Artisan gelato — booked and ready.",
    status: "paid",
    eventId: "charlotte-night-market",
    eventName: "Charlotte Night Market",
    vendorProfileId: "velvet-scoops",
    paymentStatus: "paid",
  },
  {
    id: "in-7",
    vendorId: "sparkle-jewels",
    vendorName: "Sparkle Jewels",
    initials: "SJ",
    category: "Jewelry",
    city: "Raleigh, NC",
    rating: 4.5,
    completedEvents: 8,
    verified: false,
    message: "Handmade earrings and bracelets.",
    status: "waitlisted",
    eventId: "raleigh-homecoming-market",
    eventName: "Raleigh Homecoming Market",
    vendorProfileId: "sparkle-jewels",
  },
  {
    id: "in-8",
    vendorId: "overloaded-truck",
    vendorName: "Overloaded Truck Co.",
    initials: "OT",
    category: "Food Truck",
    city: "Houston, TX",
    rating: 3.9,
    completedEvents: 5,
    verified: false,
    message: "Late application — category was full.",
    status: "rejected",
    eventId: "houston-rodeo-weekend-market",
    eventName: "Houston Rodeo Weekend Market",
    vendorProfileId: "overloaded-truck",
  },
]

export const organizers = [
  { id: "culturewave", name: "CultureWave Events", events: 12, verified: true },
  { id: "markethouse", name: "MarketHouse Collective", events: 7, verified: false },
  { id: "southern-social", name: "Southern Social Co.", events: 15, verified: true },
  { id: "block-party", name: "Block Party Group", events: 9, verified: true },
  { id: "campus-weekend", name: "Campus Weekend Live", events: 6, verified: true },
]

export const currentVendor = vendors[0]

export const vendorKpis = {
  applicationsSubmitted: vendorRequests.length,
  approvedBookings: vendorRequests.filter((r) => ["approved", "payment-due", "paid"].includes(r.status)).length,
  paymentsDue: vendorRequests.filter((r) => r.status === "payment-due").length,
  completedEvents: currentVendor.completedEvents,
  averageRating: currentVendor.rating,
  profileStrength: 78,
}

export type VendorDocument = {
  id: string
  name: string
  status: "verified" | "pending" | "missing"
  required: boolean
  expiresAt?: string
}

export const vendorDocuments: VendorDocument[] = [
  { id: "biz-license", name: "Business license", status: "verified", required: true, expiresAt: "Dec 2026" },
  { id: "insurance", name: "Certificate of insurance", status: "pending", required: true },
  { id: "health-permit", name: "Health department permit", status: "verified", required: true, expiresAt: "Aug 2026" },
  { id: "food-handler", name: "Food handler certification", status: "missing", required: false },
  { id: "w9", name: "W-9 tax form", status: "verified", required: true },
]

export type VendorReview = {
  id: string
  eventName: string
  organizer: string
  rating: number
  text: string
  date: string
}

export const vendorReviews: VendorReview[] = [
  {
    id: "rev-1",
    eventName: "Charlotte Night Market",
    organizer: "CultureWave Events",
    rating: 5,
    text: "Great crowd and smooth load-in. Would book again.",
    date: "Jun 2026",
  },
  {
    id: "rev-2",
    eventName: "Atlanta Food Fest",
    organizer: "Southern Social Co.",
    rating: 4,
    text: "Well organized. Parking was tight but staff helped.",
    date: "May 2026",
  },
  {
    id: "rev-3",
    eventName: "Raleigh Homecoming Market",
    organizer: "Block Party Group",
    rating: 5,
    text: "Professional team and clear vendor packet.",
    date: "Apr 2026",
  },
]

export const vendorFavoriteIds = [
  "charlotte-night-market",
  "atlanta-food-fest",
  "dc-greek-picnic-vendor-row",
]

export const vendorGrowth = {
  score: 87,
  rankLabel: "Top 12% in Charlotte",
  responseRate: 94,
  acceptanceRate: 68,
  repeatBookings: 12,
  profileViews: 248,
  milestones: [
    { label: "10 events completed", done: true },
    { label: "4.8+ average rating", done: true },
    { label: "50 profile views this month", done: false },
  ],
}

export function getVendorFavoriteEvents() {
  return events.filter((e) => vendorFavoriteIds.includes(e.id))
}

export function getVendorOperations() {
  return vendorRequests.filter((r) => ["approved", "payment-due", "paid"].includes(r.status))
}

export const organizerKpis = {
  activeEvents: events.filter((e) => e.organizer === "CultureWave Events" && e.status === "live").length,
  vendorRequests: events.filter((e) => e.organizer === "CultureWave Events").reduce((s, e) => s + e.vendorRequests, 0),
  approved: events.filter((e) => e.organizer === "CultureWave Events").reduce((s, e) => s + e.approvedVendors, 0),
  paid: events.filter((e) => e.organizer === "CultureWave Events").reduce((s, e) => s + e.paidVendors, 0),
  vendorRevenue: events.filter((e) => e.organizer === "CultureWave Events").reduce((s, e) => s + e.revenue, 0),
  readinessScore: Math.round(
    events.filter((e) => e.organizer === "CultureWave Events").reduce((s, e) => s + e.readiness, 0) /
      events.filter((e) => e.organizer === "CultureWave Events").length,
  ),
}

export const adminMetrics = {
  vendorCount: vendors.length,
  organizerCount: organizers.length,
  liveEvents: events.filter((e) => e.status === "live").length,
  totalApplications: vendorRequests.length + incomingRequests.length,
  gmv: events.reduce((s, e) => s + e.revenue, 0),
  verificationQueue: organizers.filter((o) => !o.verified).length + vendors.filter((v) => !v.documentsVerified).length,
  verifiedVendors: vendors.filter((v) => v.verified && v.documentsVerified).length,
  verifiedOrganizers: organizers.filter((o) => o.verified).length,
  acceptedApplications: vendorRequests.filter((r) => ["approved", "payment-due", "paid"].includes(r.status)).length +
    incomingRequests.filter((r) => ["approved", "payment-due", "paid"].includes(r.status)).length,
  paidBookings: vendorRequests.filter((r) => r.status === "paid").length +
    incomingRequests.filter((r) => r.status === "paid").length,
  openDisputes: 2,
  refundsThisMonth: 3,
  refundsAmount: 540,
  topCities: ["Charlotte", "Atlanta", "Houston", "Miami", "Washington"],
  topCategories: ["Food Vendor", "Food Truck", "Clothing Brand", "Beauty Vendor", "Beverage Vendor"],
}

export const vendorProfileDetails = {
  business: {
    description:
      "Award-winning Carolina-style BBQ with a modern twist. We bring smoked brisket, pulled pork, and house-made sauces to festivals and night markets across the Southeast.",
    email: "hello@sweetheatbbq.com",
    phone: "(704) 555-0198",
    website: "sweetheatbbq.com",
    instagram: "@sweetheatbbq",
    cities: ["Charlotte", "Raleigh", "Atlanta"],
    setupNeeds: ["10x10 tent", "2 tables", "Electrical hookup", "Generator (quiet-rated)"],
  },
  photos: [
    { id: "p1", label: "Cover", url: "/outdoor-food-festival-crowd-daytime.png" },
    { id: "p2", label: "Booth setup", url: "/night-market-string-lights-food-stalls.png" },
    { id: "p3", label: "Menu board", url: "/vibrant-street-culture-popup-market-miami.png" },
  ],
  documents: [
    { id: "d1", name: "Business license", status: "verified" as const, uploadedAt: "Mar 12, 2026" },
    { id: "d2", name: "Certificate of insurance", status: "verified" as const, uploadedAt: "Mar 12, 2026" },
    { id: "d3", name: "Food handler permit", status: "pending" as const, uploadedAt: "Jun 20, 2026" },
  ],
  setupNeeds: [
    { id: "s1", label: "10x10 tent", required: true, provided: true },
    { id: "s2", label: "2 folding tables", required: true, provided: true },
    { id: "s3", label: "Electrical hookup (20A)", required: false, provided: false },
    { id: "s4", label: "Quiet-rated generator", required: false, provided: true },
  ],
  reviews: [
    { id: "r1", event: "Charlotte Night Market", rating: 5, text: "Best BBQ at the market — line was out the gate all night.", date: "Jul 2025" },
    { id: "r2", event: "Atlanta Food Fest", rating: 5, text: "Professional setup, on time, great with crowds.", date: "May 2025" },
    { id: "r3", event: "Greensboro Summer Block Party", rating: 4, text: "Solid food and friendly team. Would book again.", date: "Aug 2024" },
  ],
  verification: {
    identity: "verified" as const,
    documents: "verified" as const,
    insurance: "verified" as const,
    payout: "connected" as const,
  },
}

export const vendorLists = [
  { id: "list-1", name: "Night market favorites", count: 8, updatedAt: "Jun 18, 2026" },
  { id: "list-2", name: "Food & beverage shortlist", count: 12, updatedAt: "Jun 10, 2026" },
  { id: "list-3", name: "Charlotte locals", count: 5, updatedAt: "May 28, 2026" },
]

export const vendorInvites = [
  { id: "inv-1", vendor: "Fresh Press Lemonade", event: "Charlotte Night Market", status: "pending" as const, sentAt: "Jun 22, 2026" },
  { id: "inv-2", vendor: "Glow Beauty Bar", event: "Atlanta Food Fest", status: "accepted" as const, sentAt: "Jun 15, 2026" },
  { id: "inv-3", vendor: "LensLoop Photo Booth", event: "Houston Rodeo Weekend Market", status: "pending" as const, sentAt: "Jun 8, 2026" },
]

export function computeVendorFeeBreakdown(vendorFee: number) {
  const lnkupFee = Math.round(vendorFee * 0.1)
  return {
    vendorSpotFee: vendorFee,
    lnkupFee,
    total: vendorFee + lnkupFee,
  }
}

export function statusLabel(status: RequestStatus | IncomingRequest["status"]): string {
  const map: Record<string, string> = {
    "under-review": "Under Review",
    approved: "Approved",
    "payment-due": "Payment Due",
    paid: "Paid",
    waitlisted: "Waitlisted",
    rejected: "Rejected",
  }
  return map[status] ?? status
}

export function statusVariant(
  status: RequestStatus | IncomingRequest["status"],
): "review" | "approved" | "due" | "paid" | "waitlist" | "rejected" {
  const map: Record<string, "review" | "approved" | "due" | "paid" | "waitlist" | "rejected"> = {
    "under-review": "review",
    approved: "approved",
    "payment-due": "due",
    paid: "paid",
    waitlisted: "waitlist",
    rejected: "rejected",
  }
  return map[status] ?? "review"
}
