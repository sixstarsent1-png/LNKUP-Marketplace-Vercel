// Shared mock data for the LNKup marketplace.
// Frontend-only — no backend wired yet.

export const LNKUP_FEE = 19.99

export type VendorCategory =
  | 'Food Vendors'
  | 'Food Trucks'
  | 'Desserts'
  | 'Drinks'
  | 'Retail'
  | 'Clothing'
  | 'Beauty'
  | 'Jewelry'
  | 'Art'
  | 'Photo Booths'
  | 'Services'

export const vendorCategories: { name: VendorCategory; icon: string }[] = [
  { name: 'Food Vendors', icon: 'UtensilsCrossed' },
  { name: 'Food Trucks', icon: 'Truck' },
  { name: 'Desserts', icon: 'IceCreamCone' },
  { name: 'Drinks', icon: 'CupSoda' },
  { name: 'Retail', icon: 'ShoppingBag' },
  { name: 'Clothing', icon: 'Shirt' },
  { name: 'Beauty', icon: 'Sparkles' },
  { name: 'Jewelry', icon: 'Gem' },
  { name: 'Art', icon: 'Palette' },
  { name: 'Photo Booths', icon: 'Camera' },
  { name: 'Services', icon: 'Wrench' },
]

export type EventOpportunity = {
  id: string
  name: string
  city: string
  state: string
  date: string
  attendance: number
  vendorFee: number
  spotsRemaining: number
  totalSpots: number
  categoriesNeeded: VendorCategory[]
  image: string
  organizer: string
  deadline: string
}

export const events: EventOpportunity[] = [
  {
    id: 'dirty-south-rodeo',
    name: 'Dirty South Rodeo',
    city: 'Charlotte',
    state: 'NC',
    date: 'Aug 16, 2026',
    attendance: 12000,
    vendorFee: 250,
    spotsRemaining: 6,
    totalSpots: 40,
    categoriesNeeded: ['Food Trucks', 'Drinks', 'Clothing', 'Retail'],
    image: '/events/rodeo.png',
    organizer: 'Southern Live Co.',
    deadline: 'Jul 20, 2026',
  },
  {
    id: 'soul-district-block-party',
    name: 'Soul District Block Party',
    city: 'Atlanta',
    state: 'GA',
    date: 'Jul 4, 2026',
    attendance: 8500,
    vendorFee: 180,
    spotsRemaining: 12,
    totalSpots: 50,
    categoriesNeeded: ['Food Vendors', 'Desserts', 'Beauty', 'Art'],
    image: '/events/block-party.png',
    organizer: 'District Culture',
    deadline: 'Jun 15, 2026',
  },
  {
    id: 'sunset-soul-miami',
    name: 'Sunset & Soul Miami',
    city: 'Miami',
    state: 'FL',
    date: 'Sep 5, 2026',
    attendance: 15000,
    vendorFee: 400,
    spotsRemaining: 3,
    totalSpots: 35,
    categoriesNeeded: ['Drinks', 'Food Trucks', 'Jewelry', 'Clothing'],
    image: '/events/sunset-miami.png',
    organizer: 'Sunset Collective',
    deadline: 'Aug 1, 2026',
  },
  {
    id: 'nc-greek-picnic',
    name: 'NC Greek Picnic',
    city: 'Raleigh',
    state: 'NC',
    date: 'Jun 21, 2026',
    attendance: 20000,
    vendorFee: 300,
    spotsRemaining: 9,
    totalSpots: 60,
    categoriesNeeded: ['Food Vendors', 'Clothing', 'Photo Booths', 'Services'],
    image: '/events/greek-picnic.png',
    organizer: 'Greek Picnic LLC',
    deadline: 'May 30, 2026',
  },
  {
    id: 'ghoe-vendor-market',
    name: 'GHOE Vendor Market',
    city: 'Greensboro',
    state: 'NC',
    date: 'Nov 1, 2026',
    attendance: 30000,
    vendorFee: 350,
    spotsRemaining: 18,
    totalSpots: 80,
    categoriesNeeded: ['Retail', 'Clothing', 'Art', 'Beauty', 'Desserts'],
    image: '/events/ghoe-market.png',
    organizer: 'Aggie Events',
    deadline: 'Oct 1, 2026',
  },
  {
    id: 'taste-of-houston',
    name: 'Taste of Houston Food Fest',
    city: 'Houston',
    state: 'TX',
    date: 'Oct 12, 2026',
    attendance: 18000,
    vendorFee: 275,
    spotsRemaining: 14,
    totalSpots: 70,
    categoriesNeeded: ['Food Vendors', 'Food Trucks', 'Desserts', 'Drinks'],
    image: '/events/food-fest.png',
    organizer: 'Bayou Bites',
    deadline: 'Sep 10, 2026',
  },
]

export type ApplicationStatus =
  | 'Submitted'
  | 'Approved'
  | 'Payment Required'
  | 'Paid'
  | 'Waitlisted'
  | 'Rejected'
  | 'Completed'

export type Application = {
  id: string
  eventId: string
  eventName: string
  city: string
  date: string
  vendorFee: number
  status: ApplicationStatus
  appliedOn: string
}

export const applications: Application[] = [
  {
    id: 'app-1',
    eventId: 'dirty-south-rodeo',
    eventName: 'Dirty South Rodeo',
    city: 'Charlotte, NC',
    date: 'Aug 16, 2026',
    vendorFee: 250,
    status: 'Payment Required',
    appliedOn: 'Jun 2, 2026',
  },
  {
    id: 'app-2',
    eventId: 'soul-district-block-party',
    eventName: 'Soul District Block Party',
    city: 'Atlanta, GA',
    date: 'Jul 4, 2026',
    vendorFee: 180,
    status: 'Approved',
    appliedOn: 'May 28, 2026',
  },
  {
    id: 'app-3',
    eventId: 'nc-greek-picnic',
    eventName: 'NC Greek Picnic',
    city: 'Raleigh, NC',
    date: 'Jun 21, 2026',
    vendorFee: 300,
    status: 'Paid',
    appliedOn: 'May 10, 2026',
  },
  {
    id: 'app-4',
    eventId: 'sunset-soul-miami',
    eventName: 'Sunset & Soul Miami',
    city: 'Miami, FL',
    date: 'Sep 5, 2026',
    vendorFee: 400,
    status: 'Submitted',
    appliedOn: 'Jun 12, 2026',
  },
  {
    id: 'app-5',
    eventId: 'ghoe-vendor-market',
    eventName: 'GHOE Vendor Market',
    city: 'Greensboro, NC',
    date: 'Nov 1, 2026',
    vendorFee: 350,
    status: 'Waitlisted',
    appliedOn: 'Jun 1, 2026',
  },
  {
    id: 'app-6',
    eventId: 'taste-of-houston',
    eventName: 'Taste of Houston Food Fest',
    city: 'Houston, TX',
    date: 'Oct 12, 2025',
    vendorFee: 275,
    status: 'Completed',
    appliedOn: 'Aug 1, 2025',
  },
]

export const applicationStatusMeta: Record<
  ApplicationStatus,
  { label: string; tone: 'neutral' | 'accent' | 'warn' | 'success' | 'danger' }
> = {
  Submitted: { label: 'Submitted', tone: 'neutral' },
  Approved: { label: 'Approved', tone: 'accent' },
  'Payment Required': { label: 'Payment Required', tone: 'warn' },
  Paid: { label: 'Paid', tone: 'success' },
  Waitlisted: { label: 'Waitlisted', tone: 'neutral' },
  Rejected: { label: 'Rejected', tone: 'danger' },
  Completed: { label: 'Completed', tone: 'success' },
}

export type VendorDocument = {
  id: string
  name: string
  status: 'Verified' | 'Pending' | 'Missing' | 'Expiring Soon'
  expires?: string
}

export const documents: VendorDocument[] = [
  { id: 'coi', name: 'Certificate of Insurance', status: 'Verified', expires: 'Mar 2027' },
  { id: 'health', name: 'Health Permit', status: 'Expiring Soon', expires: 'Jul 2026' },
  { id: 'license', name: 'Business License', status: 'Verified', expires: 'Jan 2027' },
  { id: 'w9', name: 'W-9', status: 'Pending' },
  { id: 'sales-tax', name: 'Sales Tax Certificate', status: 'Missing' },
]

export type Payment = {
  id: string
  eventName: string
  date: string
  vendorFee: number
  status: 'Paid' | 'Due' | 'Refunded'
}

export const payments: Payment[] = [
  { id: 'pay-1', eventName: 'NC Greek Picnic', date: 'May 11, 2026', vendorFee: 300, status: 'Paid' },
  { id: 'pay-2', eventName: 'Dirty South Rodeo', date: 'Jun 3, 2026', vendorFee: 250, status: 'Due' },
  { id: 'pay-3', eventName: 'Taste of Houston Food Fest', date: 'Aug 2, 2025', vendorFee: 275, status: 'Paid' },
  { id: 'pay-4', eventName: 'Spring Arts Market', date: 'Apr 1, 2025', vendorFee: 150, status: 'Refunded' },
]

export type Review = {
  id: string
  organizer: string
  event: string
  rating: number
  date: string
  comment: string
}

export const reviews: Review[] = [
  {
    id: 'rev-1',
    organizer: 'Greek Picnic LLC',
    event: 'NC Greek Picnic',
    rating: 5,
    date: 'Jun 2025',
    comment: 'Showed up early, professional setup, sold out by 4pm. Would book again instantly.',
  },
  {
    id: 'rev-2',
    organizer: 'Bayou Bites',
    event: 'Taste of Houston Food Fest',
    rating: 5,
    date: 'Oct 2025',
    comment: 'Amazing energy and the line never stopped. A crowd favorite.',
  },
  {
    id: 'rev-3',
    organizer: 'District Culture',
    event: 'Soul District Block Party',
    rating: 4,
    date: 'Jul 2025',
    comment: 'Great food, would love quicker breakdown next time. Solid vendor overall.',
  },
]

// ---- Organizer side ----

export type OrganizerEvent = {
  id: string
  name: string
  city: string
  date: string
  status: 'Open' | 'Filling' | 'Full' | 'Past'
  spotsFilled: number
  totalSpots: number
  newApplications: number
  revenue: number
  image: string
}

export const organizerEvents: OrganizerEvent[] = [
  {
    id: 'oe-1',
    name: 'Dirty South Rodeo',
    city: 'Charlotte, NC',
    date: 'Aug 16, 2026',
    status: 'Filling',
    spotsFilled: 34,
    totalSpots: 40,
    newApplications: 8,
    revenue: 8500,
    image: '/events/rodeo.png',
  },
  {
    id: 'oe-2',
    name: 'Soul District Block Party',
    city: 'Atlanta, GA',
    date: 'Jul 4, 2026',
    status: 'Open',
    spotsFilled: 38,
    totalSpots: 50,
    newApplications: 15,
    revenue: 6840,
    image: '/events/block-party.png',
  },
  {
    id: 'oe-3',
    name: 'Sunset & Soul Miami',
    city: 'Miami, FL',
    date: 'Sep 5, 2026',
    status: 'Filling',
    spotsFilled: 32,
    totalSpots: 35,
    newApplications: 4,
    revenue: 12800,
    image: '/events/sunset-miami.png',
  },
]

export type VendorApplicant = {
  id: string
  businessName: string
  category: VendorCategory
  completedEvents: number
  rating: number
  documentsComplete: boolean
  verified: boolean
  avatar: string
  appliedFor: string
}

export const vendorApplicants: VendorApplicant[] = [
  {
    id: 'va-1',
    businessName: 'Smoke & Brisket Co.',
    category: 'Food Trucks',
    completedEvents: 42,
    rating: 4.9,
    documentsComplete: true,
    verified: true,
    avatar: '/vendors/smoke-brisket.png',
    appliedFor: 'Dirty South Rodeo',
  },
  {
    id: 'va-2',
    businessName: 'Glow Beauty Bar',
    category: 'Beauty',
    completedEvents: 18,
    rating: 4.7,
    documentsComplete: true,
    verified: true,
    avatar: '/vendors/glow-beauty.png',
    appliedFor: 'Soul District Block Party',
  },
  {
    id: 'va-3',
    businessName: 'Sweet Tooth Treats',
    category: 'Desserts',
    completedEvents: 7,
    rating: 4.5,
    documentsComplete: false,
    verified: false,
    avatar: '/vendors/sweet-tooth.png',
    appliedFor: 'GHOE Vendor Market',
  },
  {
    id: 'va-4',
    businessName: 'Crown & Culture Apparel',
    category: 'Clothing',
    completedEvents: 25,
    rating: 4.8,
    documentsComplete: true,
    verified: true,
    avatar: '/vendors/crown-culture.png',
    appliedFor: 'Dirty South Rodeo',
  },
]

// ---- Admin side ----

export const adminStats = {
  totalVendors: 4820,
  totalOrganizers: 312,
  totalEvents: 1184,
  applications: 28940,
  bookings: 9120,
  gmv: 2860000,
  revenue: 182300,
}

export const topCities = [
  { city: 'Atlanta, GA', events: 142, gmv: 412000 },
  { city: 'Houston, TX', events: 118, gmv: 388000 },
  { city: 'Charlotte, NC', events: 96, gmv: 301000 },
  { city: 'Miami, FL', events: 88, gmv: 296000 },
  { city: 'Dallas, TX', events: 74, gmv: 254000 },
]

export const topCategories = [
  { category: 'Food Trucks', share: 32 },
  { category: 'Food Vendors', share: 24 },
  { category: 'Clothing', share: 16 },
  { category: 'Desserts', share: 12 },
  { category: 'Beauty', share: 9 },
  { category: 'Art', share: 7 },
]

export const revenueTrend = [
  { month: 'Jan', gmv: 142, revenue: 9.1 },
  { month: 'Feb', gmv: 168, revenue: 10.7 },
  { month: 'Mar', gmv: 201, revenue: 12.8 },
  { month: 'Apr', gmv: 224, revenue: 14.3 },
  { month: 'May', gmv: 268, revenue: 17.1 },
  { month: 'Jun', gmv: 312, revenue: 19.9 },
]

export const pendingVendors = [
  { id: 'pv-1', name: 'Urban Eats Truck', category: 'Food Trucks', city: 'Atlanta, GA', submitted: '2 hours ago' },
  { id: 'pv-2', name: 'Luxe Lash Lounge', category: 'Beauty', city: 'Miami, FL', submitted: '5 hours ago' },
  { id: 'pv-3', name: 'Heritage Threads', category: 'Clothing', city: 'Charlotte, NC', submitted: '1 day ago' },
  { id: 'pv-4', name: 'Cocoa Dreams', category: 'Desserts', city: 'Houston, TX', submitted: '1 day ago' },
]

export const pendingOrganizers = [
  { id: 'po-1', name: 'Sunset Collective', events: 6, city: 'Miami, FL', flag: false },
  { id: 'po-2', name: 'District Culture', events: 12, city: 'Atlanta, GA', flag: false },
  { id: 'po-3', name: 'Fest Kings Promotions', events: 2, city: 'Las Vegas, NV', flag: true },
]

export function currency(amount: number) {
  return amount.toLocaleString('en-US', {
    style: 'currency',
    currency: 'USD',
    minimumFractionDigits: amount % 1 === 0 ? 0 : 2,
    maximumFractionDigits: 2,
  })
}
