# Vendor design system

The vendor design layer is `wp-content/plugins/lnkup-marketplace/public/css/vendor-os.css`. It loads last on vendor surfaces only, from `LNKup_Vendor_UI::enqueue()` at `wp_enqueue_scripts` priority 99. Every rule is scoped to `body.lnkup-vos`. That body class is added on the vendor dashboard, Explore (`/opportunities/`), Saved (`/favorites/`), vendor profile, event and opportunity pages, and the notification center for vendor-only accounts. Organizer screens never get it.

Older tokens remain in `wp-content/themes/lnkup-theme/assets/dist/components.css`. The layer overrides them on vendor surfaces; it does not edit them.

## Tokens (`body.lnkup-vos`)

| Token | Value | Use |
|---|---|---|
| `--vos-canvas` | `#f6f5f1` | Page background |
| `--vos-surface` / `--vos-surface-2` | `#fff` / `#faf9f6` | Cards / quiet rows |
| `--vos-line` / `--vos-line-strong` | `#e7e5df` / `#d6d3cb` | Borders |
| `--vos-ink` / `--vos-ink-2` / `--vos-muted` | `#141414` / `#3f3f46` / `#62626b` | Text |
| `--vos-shell` | `#121212` | Sidebar, spotlight, event headers, primary metric |
| `--vos-lime` | `oklch(0.88 0.19 128)` | Primary actions only, always with dark text |
| Status pairs | success, warning, danger, info | Background and text pairs that pass 4.5:1 |
| Space | 4, 8, 12, 16, 24, 32, 48 px | `--vos-space-1` to `--vos-space-7` |
| Type | page 30, section 20, card 17, body 15, meta 13 px | `--vos-text-*` |
| Radius | 14 / 10 px | Cards / rows |

`:focus-visible` uses a two-ring focus style. `prefers-reduced-motion` turns off transitions and animations.

## Layout

- Content width is up to 88rem (was 72rem).
- Home uses `.vos-layout`: one column below 1280px, and a main column plus a sticky 22rem rail at 1280px and above.
- Page-level horizontal overflow is 0 on 9 screens at 8 widths (`screenshots/after/overflow.json`). Only the wallet tab row scrolls horizontally on phones.

## Shared partials (`LNKup_Vendor_UI`)

`page_header`, `metric`, `metrics`, `section_head`, `status` (icon plus text, never color alone), `tone_for_status`, `money`, `date`, `days_until`, `event_image` (uses the Event Media fallback chain), `initials`, `organizer_name`, `empty_state`, and `progress`. They return escaped HTML.

## Shell

- Sidebar groups (Marketplace, My Business) are collapsible buttons. Their state is stored in `localStorage` under `lnkupVosNavGroups`.
- The desktop sidebar collapses to icons. That state is stored under `lnkupVosNavCollapsed`.
- The top bar drops the duplicate business name on desktop. It adds a notifications bell whose count comes from `GET /comms/inbox/count`.

## Components by screen

| Screen | Main classes |
|---|---|
| Home | `vos-hero`, `vos-actions` / `vos-action`, `vos-spotlight`, `vos-discover`, `vos-metrics`, `vos-invites`, `vos-setup`, `vos-level`, `vos-feed` |
| Explore and cards | `lnkup-event-card` restyled, a single primary action, `lnkup-event-card__deadline` |
| Opportunity detail | `vos-action-column` (sticky, state-aware CTA) |
| Bookings | `vos-booking` (image, one status, one primary action) |
| Booking detail | `vos-detail`, `vos-tabs`, `vos-dl`, `vos-lines`, `vos-timeline` |
| Wallet | server-rendered `lnkup-fin-balance-hero__grid--vendor`, `vos-due`, `vos-wallet-tabs` plus a `vos-tab-more` menu, `vos-drawer` invoice drawer |
| Operations | `vos-ops-header`, `vos-ops-switcher`, `vos-readiness__group--vendor` / `--organizer` |
| Growth | KPI grid, `vos-funnel`, `vos-market`, recruit card |
| Documents | metric summary, `lnkup-doc-card` with `vos-status` |

## Mobile (Expo)

`lnk-up-marketplace-mobile/lib/theme.ts` adds the same tokens: `canvas`, `surfaceMuted`, `line`, `lineStrong`, `ink`, `inkMuted`, and `shell`. Screens use `colors.canvas` as the background. Booking cards use `colors.line` borders and the server's `primary_status`.
