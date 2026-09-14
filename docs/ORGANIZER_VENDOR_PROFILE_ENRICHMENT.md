# Organizer Vendor Profile Enrichment (1A / 2A)

**Status:** Built  
**Scope:** WordPress CRM + Next.js organizer app + Expo mobile — **read-only** enrichment only.

## Goal

Surface existing CRM vendor DTO fields on all three organizer surfaces so gallery, bio, setup/ops, documents (type/status), shared history, profile strength, and reputation are visible without CRM write actions from the apps.

## Source of truth

`GET /crm/vendors/{id}` via `LNKup_Vendor_CRM_Profile::get()` — no new REST routes.

Optional application context: `GET /crm/applications/{id}/intel` for message + readiness checklist blockers.

## Shared sections

| Section | When shown |
|---------|------------|
| Hero | Always (name, category, location, verified, strength) |
| About | Non-empty `description` |
| Gallery | Non-empty `gallery` / cover |
| Setup & ops | Any setup/power/water/tent/trailer/space field |
| Documents | Type + status chips only |
| With you | `past_events` / `upcoming_events` |
| Reputation | When reputation / rating summary exists |
| Contact | Email, phone, website, Instagram (links) |
| Readiness | When `applicationId` known and incomplete checklist items exist |
| Open in LNKup CRM | `{organizerDashboard}?vendor={profileId}` |

## Surfaces

### WordPress

`render_vendor_crm_profile()` in `public/class-shortcodes.php` + gallery/doc/history styles in `public/css/organizer-crm.css`.

### Next.js

- Mapper: `lnk-up-marketplace-ui/lib/api/organizer-vendor-profile.ts`
- Drawer: `components/organizer/organizer-vendor-profile-drawer.tsx`
- Discover → drawer: `app/organizer/vendors/page.tsx` + `VendorCard.onViewProfile`
- BFF: `app/api/organizer/vendors/[id]/route.ts` passes readiness into mapper
- Footer: **Open in LNKup CRM**

### Expo mobile

- Peek mapper: `lnk-up-marketplace-mobile/lib/api/organizer-vendor-directory.ts`
- Screen: `app/(organizer)/vendors/[profileId].tsx`
- Requests pass `?applicationId=` for readiness
- **Open in LNKup CRM** via `wordpressUrl` (`?vendor=`)

## Non-goals

- Favorite / note / invite / list writes from apps
- New document download UX
- Next standalone `/organizer/vendors/[id]` route (drawer only)
- Financial OS / Stripe changes

## Verify

```bash
node scripts/organizer-vendor-profile-enrichment-verify.mjs
```
