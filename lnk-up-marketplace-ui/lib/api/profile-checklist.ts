import type { WpProfileStrength, WpProfileStrengthSection } from "@/lib/api/types"

export type ProfileChecklistItem = {
  id: string
  label: string
  done: boolean
  description?: string
  actionHref?: string
}

const SECTION_LINKS: Record<string, string> = {
  business_basics: "/vendor/profile",
  contact_social: "/vendor/profile",
  products_services: "/vendor/profile",
  service_areas: "/vendor/profile",
  setup_requirements: "/vendor/profile",
  photos_proof: "/vendor/profile",
  documents: "/vendor/documents",
  trust_reputation: "/vendor/profile",
}

function sectionToItem(section: WpProfileStrengthSection): ProfileChecklistItem {
  const done = Boolean(section.complete)
  const missing = section.missing?.[0]
  const action = section.actions?.[0]
  return {
    id: section.key || section.title || `section-${Math.random()}`,
    label: section.title || "Profile section",
    done,
    description: done ? undefined : missing || action?.description,
    actionHref: action?.href || SECTION_LINKS[section.key || ""] || "/vendor/profile",
  }
}

export function mapProfileChecklist(strength?: WpProfileStrength): ProfileChecklistItem[] {
  if (!strength?.sections?.length) return mockProfileChecklist
  return strength.sections.map(sectionToItem)
}

export const mockProfileChecklist: ProfileChecklistItem[] = [
  { id: "business", label: "Business name & category", done: true },
  { id: "logo", label: "Logo or cover photo", done: true },
  { id: "description", label: "Business description", done: false, actionHref: "/vendor/profile" },
  { id: "products", label: "Products/services sold", done: true },
  { id: "areas", label: "Cities/states served", done: false, actionHref: "/vendor/profile" },
  { id: "contact", label: "Contact email & phone", done: true },
  { id: "social", label: "Instagram/TikTok/website", done: false, actionHref: "/vendor/profile" },
  { id: "setup", label: "Setup needs & power/water", done: false, actionHref: "/vendor/profile" },
  { id: "documents", label: "Documents", done: false, actionHref: "/vendor/documents" },
]
