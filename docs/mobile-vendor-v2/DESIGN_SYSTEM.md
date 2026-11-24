# Mobile design system (vendor)

The existing system (`lnk-up-marketplace-mobile/lib/theme.ts`) was kept: black primary actions, lime accent, canvas and white surfaces, Sora display type, Inter body type, a 4-point spacing scale, 44pt minimum touch targets and `fontScaleCaps` for Dynamic Type. This pass changed how it is used, not the tokens.

## Changes

| Area | Before | After |
|---|---|---|
| Screen headers | "LNKUP" eyebrow above every title, plus a subtitle | Title only (28pt Sora); subtitles only where they add information. Applies to vendor and organizer screens through `components/screen.tsx`. |
| 5th tab | Labelled "Passport", opens the account hub | "Profile" (route unchanged); the Vendor Passport is the first row in it |
| Dark modules | Event-day card only | Reserved for the three highest-value summaries: next-event spotlight (Home), wallet summary, business identity (Profile). Everything else stays on white. |
| Lime | Fills, chips, badges, toggles | Accent on dark modules, counts, the active filter and primary pass actions only |
| Event imagery | Green storefront placeholder whenever the media URL failed or was missing | `EventImage` uses WordPress `media` first, then `category_fallback_url`, then the branded placeholder. Portrait flyers (`fit: contain`) sit on a blurred copy of the same image. |
| Status | Several badges per card | One status line per card (`statusNote`), in words, never colour alone |
| Money | `$${fee}` strings in places | `formatMoney` everywhere; pay labels from `payCtaLabel` |

## Components added

| Component | File | Use |
|---|---|---|
| `EventCard` (`featured`, `standard`, `compact`, `row`) | `components/marketplace/event-card.tsx` | One card per event: Explore, Home rails, Saved |
| `EventRail` | `components/marketplace/event-rail.tsx` | Horizontal Home collections (hidden when empty) |
| `VendorEventDetail` | `components/marketplace/vendor-event-detail.tsx` | Event screen with a vendor-option radio group and a context-aware action bar |
| `ActionCenter` | `components/vendor/action-center.tsx` | Consolidated "Needs action" with event context; replaces three next-step cards on vendor Home |
| `UpcomingSpotlight` | `components/vendor/upcoming-spotlight.tsx` | Next confirmed event with countdown and pass |
| `TransactionRow` | `components/wallet/transaction-row.tsx` | Ledger rows with status in words |
| `WalletSummary` (rewritten) | `components/wallet-summary.tsx` | Amount due first, then booth spend and credits (no truncation) |

## Components retired

- `components/opportunity-card.tsx`: replaced by `EventCard`; no remaining imports.
- `components/match-rails.tsx`: six extra requests on Explore duplicated the Recommended sort and the Home rail.

## Shared logic (no UI)

| Module | Responsibility |
|---|---|
| `lib/vendor/booking-lifecycle.ts` | Segments, payment-due versus past-event invoice, pass eligibility, countdown, pay label. Uses server state, with a date fallback for older servers. |
| `lib/vendor/event-group-presentation.ts` | Price, availability, deadline and environment copy for grouped events |
| `lib/api/event-groups.ts` | `GroupedEventSummary` mapping, local fallback grouping, per-option favorites behind event cards |
| `lib/api/vendor-transactions.ts` | Ledger mapping, money-movement filter, date grouping, receipt detail |
| `lib/notifications/group-notifications.ts` | Collapses repeated reminders per booking |

## Not changed (native modules)

These would need a new binary, so they were deliberately not added: haptics, `@gorhom/bottom-sheet`, FlashList, screen-brightness boost on the pass, and Apple Wallet. The existing `BottomSheet`, FlatList and expo-image cover the same needs for this release.
