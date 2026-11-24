# Organizer route map

Base: `/organizer-dashboard/`. View slugs are allowlisted by `lnkup_organizer_dashboard_view()` (`includes/helpers.php` ~238). Unknown values fall back to `home`. Detail routes are resolved in `LNKup_Shortcodes::organizer_asset_context()` (~156) in this priority: `vendor` → `roster` → `ops` → `scan` → `edit` → workspace.

## `view=` sections

| `view=` | Aliases | Renderer | Nav group (2.0) |
| --- | --- | --- | --- |
| `home` | `''`, `overview` | `render_home` / `render_home_v2` | Workspace |
| `events` | `create-opportunity` | `render_events` | Workspace |
| `tours` | — | `render_portfolio` (portfolio flag) | Workspace |
| `vendors` | — | `render_vendors` (+ `section=` Discover / For this event / My vendors / Lists / Imported / Invitations) | Vendor Management |
| `applications` | — | `render_applications` (`filter`, `group`, `event_filter`, `q`, `sort`, `mode=classic`) | Vendor Management |
| `invites` | — | `render_invites` | Vendor Management |
| `lists` | — | `render_lists` | Vendor Management |
| `financial` | `payments` | `render_financial` (`fin_tab=`) | Business |
| `operations` | — | `render_operations` | Business |
| `marketing` | — | `LNKup_Organizer_Marketing::render` (flag) | Business |
| `intelligence` | — | `render_intelligence` | Business |
| `team` | — | `render_team` | Organization |
| `profile` | — | `render_profile` | Organization |
| `settings` | — | `render_settings` (new hub, 2.0) | Organization |
| `help` | — | `render_help` | Footer |
| `start` | — | `render_start` (Home module in 2.0; route kept) | — |
| `create` | `create-event`, `import` | `render_create` | Top bar "New" |
| `tasks` | — | `render_my_tasks` | Operations |
| `referrals` | — | `render_referrals` | Vendor Management (More) |

## Event detail routes

| Query | Renderer | Notes |
| --- | --- | --- |
| `?event=ID` / `&tab=` | `render_event_workspace` | Ownership: `LNKup_Capabilities::organizer_owns_event` |
| `?event=ID&roster=1` | `render_event_roster` | Readiness matrix + CSV export |
| `?event=ID&ops=1` | operations page (`operations.js`) | Hash sections: `#booths`, `#map`, `#packet`, `#parking`, `#loadin`, `#broadcasts`, `#compliance` |
| `?event=ID&scan=1` | check-in (`LNKup_Checkins`) | QR scan, search, manual |
| `?event=ID&edit=1` | `render_event_edit_view` | Event edit form |
| `?vendor=ID` | vendor detail (CRM) | Profile, history, notes |

### Workspace `tab=` keys (2.0 with alias map)

| 2.0 tab | Accepted keys | Content |
| --- | --- | --- |
| Overview | `overview` | Funnel, capacity, money, readiness, deadlines |
| Inventory | `inventory`, `slots` | Booth types table + edit drawer |
| Applications | `applications`, `requests` | Shared application table (event scope) |
| Vendors | `vendors` | Confirmed vendors |
| Money | `money`, `financials` | Event financials (requires `can_manage_event_financials`) |
| Operations | `operations` → `?ops=1` | Link out |
| Marketing | `marketing` | Share link, apply link, QR, embed |
| Settings | `settings` → `?edit=1` | Link out |
| Readiness / Tasks / Documents | `readiness`, `tasks`, `documents` | Ops v2 panels (flags + staff caps) |

## Money (`view=financial`) tabs

2.0 primary tabs: Overview, Transactions, Invoices, Payouts, Planning, Reports, Account. Legacy `fin_tab=` values map:

| Legacy `fin_tab` | 2.0 location |
| --- | --- |
| `overview`, `wallet` | Overview |
| `vendor-revenue` | Transactions |
| `cashout`, `payouts` | Payouts (cash-out drawer) |
| `reserves`, `budget`, `forecast` | Planning |
| `reports`, `sponsor`, `tax` | Reports |
| `refunds`, `disputes` | More menu |
| `settings` | Account |

## Modals and drawers

| Surface | Trigger | Implementation |
| --- | --- | --- |
| Application review drawer | Applications table row | `organizer-review.js` → `LNKupOS.drawer` |
| Invite drawer | Vendors multi-select tray | `organizer-crm.js` |
| Add to list dialog | Vendor card | `render_overlays()` |
| Inventory edit drawer | Workspace Inventory row | `organizer-os.js` |
| Duplicate event dialog | Events "New" menu / row action | `organizer-os.js` → `POST /organizer/events/{id}/duplicate` |
| Booth assign drawer | Booth row / vendor row | `operations.js` → `/booths/{id}/assign` |
| Bulk booth create dialog | Booth workspace | `operations.js` → `/operations/events/{id}/booths` |
| Cash-out drawer | Money Payouts | `financial.js` → `POST /financial/organizer-wallet/cashout` |
| Transaction detail drawer | Money Transactions row | `financial.js` |
| Tour attach drawer | Tour detail | `portfolio.js` → `POST /portfolio/tours/{id}/attach` |
| Map start dialog | Booths → Map | `maps.js` |
| Rebook vendors | Past event header | `organizer-crm.js` |

## Mobile (Expo) equivalents

| Web | Expo route |
| --- | --- |
| Home | `app/(organizer)/index.tsx` |
| Events | `app/(organizer)/events/index.tsx` |
| Event workspace | `app/(organizer)/events/[eventId].tsx` |
| Check-in | `app/(organizer)/events/checkin.tsx` |
| Money | `app/(organizer)/money.tsx` |
| Vendors / Find | `app/(organizer)/vendors/index.tsx`, `vendors/find.tsx`, `vendors/[profileId].tsx` |
| Packet | `app/(organizer)/packet.tsx` |
| Event comms | `app/(organizer)/event-comms/[eventId].tsx` |
| Launch | `app/(organizer)/launch.tsx` |
| Profile | `app/(organizer)/profile.tsx` |
| Notifications | `app/(organizer)/notifications.tsx`, `notification-settings.tsx` |
| Referrals | `app/(organizer)/referrals.tsx` |
| More | `app/(organizer)/more.tsx` |
| Map editor, bulk tour creation | Not on mobile (desktop-first; see `IMPLEMENTATION_PLAN.md`) |
