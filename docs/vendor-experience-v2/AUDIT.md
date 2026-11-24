# Vendor experience audit

WordPress is the system of record. Vendor screens render from `wp-content/themes/lnkup-theme` (shell and nav) and `wp-content/plugins/lnkup-marketplace` (views, REST, financials). The Next.js app and Expo app call `lnkup/v1` and do not own these records.

Routing is `?view=` on `/vendor-dashboard/`. Explore is `/opportunities/`. Saved is `/favorites/`.

| Screen | Files | Data | Status | Issue | Cause |
|---|---|---|---|---|---|
| Home | `public/class-vendor-dashboard-views.php` | `LNKup_Vendor_Dashboard_Data::context()` | Functional, visually crowded | Repeated invite banners, large Getting Started, ungrouped activity | Each invite is its own alert; checklist renders every step; inbox items are not grouped |
| Bookings | same, `application_event_meta()` | Application CPT + event meta | Partial | Internal titles, `Atlanta, Ge`, pay/withdraw on past events, many chips | Title falls back to `Application: email → event`; raw state; past date ignored by pay/withdraw; workflow steps plus a status pill |
| Explore | `helpers.php` `lnkup_render_homepage_event_card()`, `marketplace-v2.js` | Marketplace index | Partial | Dense cards, repeated categories | Feed cards list up to four slots; category badges are not deduped in that list |
| Wallet | `class-financial-render.php`, `financial.js`, `class-shortcodes.php` | Payment CPT + 100-row ledger | Partial | Totals repeated; three outstanding definitions | Hero, PHP booth strip, and overview KPIs; `revenue_paid` vs `lifetime_spend` vs `vendor_money_contract()` |
| Receipt | `class-receipts.php` | Receipts table | Broken presentation | Nearly empty page, number twice | `render_html()` prints five lines and uses the receipt number as the title when the event is missing |
| Payment methods | `financial.js` | Local payment_methods table | Partial | Add card does not confirm SetupIntent | Setup is created; Stripe Payment Element is never mounted; sync is never called |
| Operations | `render_operations()`, `class-vendor-readiness.php` | Confirmed applications | Partial | Blank selector, internal heading, completed items in next steps, weather copy | `get_the_title(0)`; readiness pending labels reuse completed tense; weather says the vendor should add a date |
| Growth | `class-growth-kpis.php`, `growth.js`, `class-growth-recommendations.php` | Paid spot fees vs 90-day ledger | Incorrect labeling | Lifetime Revenue with an empty chart; distant cities “near” home; `R&amp;B`; hardcoded season | KPI is booth spend; chart is a 90-day window; reason string ignores distance; titles double-encoded; best season is a fixed array |
| Passport | `passport.js`, passport REST | Passport projection | Functional, split IA | Documents and reviews also exist as separate views | Same data, three entry points |

Vendor payments are expenses. Organizer collected revenue is a different screen and is not relabeled.
