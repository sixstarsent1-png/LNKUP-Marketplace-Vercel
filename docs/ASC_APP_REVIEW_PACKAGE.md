# App Store Connect — review package (operator paste)

Use with ASC app **6795865859** · bundle `com.lnkup.marketplace` · version `1.0.0`.

## Preconditions before Submit for Review

- [ ] `https://thelnkup.com/privacy` returns 200 (theme legal pages + admin visit after theme deploy)
- [ ] `https://thelnkup.com/terms` returns 200
- [ ] `https://thelnkup.com/contact` is real support copy (no lorem)
- [ ] Plugin zip deployed with `POST /lnkup/v1/account/delete`, `POST /lnkup/v1/auth/register`, and organizer UX routes
- [ ] App Information → **Privacy Policy URL** = `https://thelnkup.com/privacy` (required for Apple Pay / payments)
- [ ] TestFlight smoke green (Create account in-app + vendor + organizer + **More → Delete account**)

## Privacy Policy / Support / Terms URLs

| Field | URL |
|-------|-----|
| Privacy Policy | https://thelnkup.com/privacy |
| Terms of Use | https://thelnkup.com/terms |
| Support / Marketing | https://thelnkup.com/contact |

## App Privacy (nutrition labels) — suggested answers

| Data | Linked to user | Used for tracking | Purpose |
|------|----------------|-------------------|---------|
| Name / Username | Yes | No | App functionality, Account management |
| Email | Yes | No | App functionality, Account management |
| Phone (if provided on profile) | Yes | No | App functionality |
| Precise location (optional Near Me) | Yes | No | App functionality |
| Device ID / Push token | Yes | No | App functionality |
| Photos / Documents (vendor uploads) | Yes | No | App functionality |
| Payment info | Processed by Stripe; LNKup stores payment status/amounts | No | App functionality, Purchases |

**Not used for third-party advertising tracking.**

## Review notes (paste into ASC)

> LNKup is a vendor opportunity marketplace for live events (not attendee ticketing).
>
> **Account creation:** Create free account is in-app (Sign in → Create free account). It calls POST /lnkup/v1/auth/register and signs the user in. It does not open Safari or thelnkup.com/sign-up/.
>
> **Demo login:** Provide a reviewer vendor and/or organizer WordPress username + site password against https://thelnkup.com.
>
> **Privacy Policy URL:** https://thelnkup.com/privacy (also linked on Sign in and Create account).
>
> **Account deletion (Guideline 5.1.1(v)):** Sign in → More tab → “Delete my account…” → type DELETE → Confirm. Deletion is scheduled within 30 days; the user is signed out immediately and cannot sign back in. Personal profile data is scrubbed on request.
>
> **Location:** Optional “Near me” on Find Events. When allowed, the app sends lat/lng/radius to marketplace search.
>
> **Push:** Expo push tokens register with WordPress; toggles in Profile / More.
>
> **Payments:** Booth fee checkout opens in the system browser to WordPress / Stripe Checkout. Native Stripe React Native is present for Connect/return flows; card entry for booth fees is browser-based.
>
> **Camera:** Permission string may appear if Stripe RN is linked; primary booth checkout does not require scanning a card in-app.

## Listing copy (short)

**Subtitle:** Vendor booth marketplace for events

**Description (short):**  
LNKup connects food trucks, merchants, and service vendors with event organizers. Discover opportunities, apply, pay booth fees when accepted, and unlock day-of event passes. Organizers launch events, review vendors, and manage payouts.

**Keywords:** vendor,marketplace,events,food truck,booth,festival,organizer

**Category:** Business (secondary: Lifestyle)

**Age rating:** 4+ (no UGC chat room; marketplace applications only)

## Screenshots checklist

- iPhone 6.7" and 6.1": Vendor Home, Find, Pass; Organizer Home, Launch / Events  
- No Stripe Dashboard chrome; black/white/lime brand

## After approval

Release manually or phased. Keep OTA for JS-only fixes; new native modules need a new production binary.
