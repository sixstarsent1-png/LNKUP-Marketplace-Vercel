// Mock data for the LNKup vendor marketplace. UI-only, no backend.

export type RequestStatus =
  | "under_review"
  | "approved"
  | "payment_due"
  | "paid"
  | "waitlisted"
  | "rejected"

export const VENDOR_CATEGORIES = [
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
  name: string
  image: string
  date: string
  shortDate: string
  city: string
  state: string
  venue: string
  organizer: string
  verified: boolean
  attendance: number
  fee: number
  spotsRemaining: number
  totalSpots: number
  categories: string[]
  deadline: string
  trending?: boolean
}

export const events: EventItem[] = [
  {
    id: "charlotte-night-market",
    name: "Charlotte Night Market",
    image: "/events/charlotte-night-market.png",
    date: "Sat, Aug 16, 2026 · 5:00 PM",
    shortDate: "Aug 16",
    city: "Charlotte",
    state: "NC",
    venue: "Camp North End",
    organizer: "CultureWave Events",
    verified: true,
    attendance: 4200,
    fee: 185,
    spotsRemaining: 6,
    totalSpots: 40,
    categories: ["Food Vendor", "Dessert Vendor", "Retail Vendor", "Art Vendor"],
    deadline: "Closes in 5 days",
    trending: true,
  },
  {
    id: "atlanta-food-fest",
    name: "Atlanta Food Fest",
    image: "/events/atlanta-food-fest.png",
    date: "Sun, Sep 7, 2026 · 12:00 PM",
    shortDate: "Sep 7",
    city: "Atlanta",
    state: "GA",
    venue: "Piedmont Park",
    organizer: "Southern Social Co.",
    verified: true,
    attendance: 8500,
    fee: 250,
    spotsRemaining: 12,
    totalSpots: 60,
    categories: ["Food Truck", "Food Vendor", "Beverage Vendor"],
    deadline: "Closes in 12 days",
    trending: true,
  },
  {
    id: "miami-culture-popup",
    name: "Miami Culture Pop-Up",
    image: "/events/miami-culture-popup.png",
    date: "Fri, Sep 19, 2026 · 6:00 PM",
    shortDate: "Sep 19",
    city: "Miami",
    state: "FL",
    venue: "Wynwood Yard",
    organizer: "MarketHouse Collective",
    verified: false,
    attendance: 3000,
    fee: 160,
    spotsRemaining: 3,
    totalSpots: 30,
    categories: ["Clothing Brand", "Beauty Vendor", "Jewelry Vendor", "Art Vendor"],
    deadline: "Closes in 3 days",
  },
  {
    id: "raleigh-homecoming-market",
    name: "Raleigh Homecoming Market",
    image: "/events/raleigh-homecoming-market.png",
    date: "Sat, Oct 4, 2026 · 11:00 AM",
    shortDate: "Oct 4",
    city: "Raleigh",
    state: "NC",
    venue: "Dorothea Dix Park",
    organizer: "Block Party Group",
    verified: true,
    attendance: 5600,
    fee: 200,
    spotsRemaining: 18,
    totalSpots: 50,
    categories: ["Food Vendor", "Retail Vendor", "Wellness Vendor", "Service Vendor"],
    deadline: "Closes in 20 days",
  },
  {
    id: "greensboro-block-party",
    name: "Greensboro Summer Block Party",
    image: "/events/greensboro-block-party.png",
    date: "Sat, Jul 25, 2026 · 2:00 PM",
    shortDate: "Jul 25",
    city: "Greensboro",
    state: "NC",
    venue: "Downtown Greenway",
    organizer: "CultureWave Events",
    verified: true,
    attendance: 2400,
    fee: 120,
    spotsRemaining: 9,
    totalSpots: 25,
    categories: ["Dessert Vendor", "Beverage Vendor", "Mobile Business"],
    deadline: "Closes in 8 days",
  },
  {
    id: "houston-rodeo-market",
    name: "Houston Rodeo Weekend Market",
    image: "/events/houston-rodeo-market.png",
    date: "Sun, Nov 2, 2026 · 4:00 PM",
    shortDate: "Nov 2",
    city: "Houston",
    state: "TX",
    venue: "NRG Park Grounds",
    organizer: "Southern Social Co.",
    verified: true,
    attendance: 12000,
    fee: 300,
    spotsRemaining: 22,
    totalSpots: 80,
    categories: ["Food Truck", "Retail Vendor", "Clothing Brand", "Photo Booth Vendor"],
    deadline: "Closes in 30 days",
    trending: true,
  },
]

export type VendorRequest = {
  id: string
  eventId: string
  eventName: string
  shortDate: string
  city: string
  state: string
  organizer: string
  status: RequestStatus
  fee: number
  appliedOn: string
  category: string
}

export const vendorRequests: VendorRequest[] = [
  {
    id: "r1",
    eventId: "charlotte-night-market",
    eventName: "Charlotte Night Market",
    shortDate: "Aug 16",
    city: "Charlotte",
    state: "NC",
    organizer: "CultureWave Events",
    status: "payment_due",
    fee: 185,
    appliedOn: "Jul 2",
    category: "Dessert Vendor",
  },
  {
    id: "r2",
    eventId: "atlanta-food-fest",
    eventName: "Atlanta Food Fest",
    shortDate: "Sep 7",
    city: "Atlanta",
    state: "GA",
    organizer: "Southern Social Co.",
    status: "paid",
    fee: 250,
    appliedOn: "Jun 20",
    category: "Food Vendor",
  },
  {
    id: "r3",
    eventId: "miami-culture-popup",
    eventName: "Miami Culture Pop-Up",
    shortDate: "Sep 19",
    city: "Miami",
    state: "FL",
    organizer: "MarketHouse Collective",
    status: "under_review",
    fee: 160,
    appliedOn: "Jul 1",
    category: "Beauty Vendor",
  },
  {
    id: "r4",
    eventId: "raleigh-homecoming-market",
    eventName: "Raleigh Homecoming Market",
    shortDate: "Oct 4",
    city: "Raleigh",
    state: "NC",
    organizer: "Block Party Group",
    status: "approved",
    fee: 200,
    appliedOn: "Jun 28",
    category: "Wellness Vendor",
  },
  {
    id: "r5",
    eventId: "greensboro-block-party",
    eventName: "Greensboro Summer Block Party",
    shortDate: "Jul 25",
    city: "Greensboro",
    state: "NC",
    organizer: "CultureWave Events",
    status: "waitlisted",
    fee: 120,
    appliedOn: "Jun 15",
    category: "Beverage Vendor",
  },
  {
    id: "r6",
    eventId: "houston-rodeo-market",
    eventName: "Houston Rodeo Weekend Market",
    shortDate: "Nov 2",
    city: "Houston",
    state: "TX",
    organizer: "Southern Social Co.",
    status: "rejected",
    fee: 300,
    appliedOn: "Jun 10",
    category: "Photo Booth Vendor",
  },
]

export type Vendor = {
  id: string
  name: string
  category: string
  city: string
  state: string
  rating: number
  completedEvents: number
  verified: boolean
  status?: "requested" | "approved" | "invited"
}

export const vendors: Vendor[] = [
  {
    id: "v1",
    name: "Sweet Heat BBQ",
    category: "Food Vendor",
    city: "Charlotte",
    state: "NC",
    rating: 4.9,
    completedEvents: 34,
    verified: true,
    status: "requested",
  },
  {
    id: "v2",
    name: "Glow Beauty Bar",
    category: "Beauty Vendor",
    city: "Atlanta",
    state: "GA",
    rating: 4.8,
    completedEvents: 21,
    verified: true,
    status: "requested",
  },
  {
    id: "v3",
    name: "City Drip Apparel",
    category: "Clothing Brand",
    city: "Miami",
    state: "FL",
    rating: 4.7,
    completedEvents: 18,
    verified: false,
    status: "approved",
  },
  {
    id: "v4",
    name: "Fresh Press Lemonade",
    category: "Beverage Vendor",
    city: "Raleigh",
    state: "NC",
    rating: 4.9,
    completedEvents: 41,
    verified: true,
    status: "approved",
  },
  {
    id: "v5",
    name: "The Rolling Dessert Co.",
    category: "Dessert Vendor",
    city: "Greensboro",
    state: "NC",
    rating: 4.6,
    completedEvents: 12,
    verified: true,
    status: "invited",
  },
  {
    id: "v6",
    name: "LensLoop Photo Booth",
    category: "Photo Booth Vendor",
    city: "Houston",
    state: "TX",
    rating: 5.0,
    completedEvents: 27,
    verified: true,
    status: "requested",
  },
]

export const organizers = [
  "CultureWave Events",
  "MarketHouse Collective",
  "Southern Social Co.",
  "Block Party Group",
]

export function statusLabel(status: RequestStatus): string {
  switch (status) {
    case "under_review":
      return "Under Review"
    case "approved":
      return "Approved"
    case "payment_due":
      return "Payment Due"
    case "paid":
      return "Paid"
    case "waitlisted":
      return "Waitlisted"
    case "rejected":
      return "Rejected"
  }
}

export function formatCurrency(n: number): string {
  return n.toLocaleString("en-US", {
    style: "currency",
    currency: "USD",
    maximumFractionDigits: 0,
  })
}
