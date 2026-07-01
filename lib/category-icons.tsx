import {
  UtensilsCrossed,
  Truck,
  IceCreamCone,
  CupSoda,
  ShoppingBag,
  Shirt,
  Sparkles,
  Gem,
  Palette,
  Camera,
  Wrench,
  type LucideIcon,
} from 'lucide-react'
import type { VendorCategory } from '@/lib/mock-data'

export const categoryIcons: Record<VendorCategory, LucideIcon> = {
  'Food Vendors': UtensilsCrossed,
  'Food Trucks': Truck,
  Desserts: IceCreamCone,
  Drinks: CupSoda,
  Retail: ShoppingBag,
  Clothing: Shirt,
  Beauty: Sparkles,
  Jewelry: Gem,
  Art: Palette,
  'Photo Booths': Camera,
  Services: Wrench,
}
