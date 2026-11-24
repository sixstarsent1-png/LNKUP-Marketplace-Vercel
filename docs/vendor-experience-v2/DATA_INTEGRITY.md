# Data integrity

## Titles

Application posts are stored as `Application: {name} → {event}` in `LNKup_Applications`. That string is an admin title. Vendor UI uses the linked `lnkup_event` title, then `lnkup_plain_title()` and `lnkup_display_event_title()`. If the event post is missing, the label is “Event details unavailable”. Stored payment and application titles are not rewritten.

## Places

`lnkup_display_state()` maps full US state names and the alias `ge` → `GA`. `lnkup_canonical_state_code()` is what new profile and landing writes use, so `Georgia` is stored as `GA` instead of `GE`. A one-time option `lnkup_state_alias_v1` updates post meta `_lnkup_state` whose value is exactly `ge` (any case) to `GA`. Financial rows are not migrated.

## Lifecycle

Stored application statuses do not change. A past event date moves the booking out of upcoming and pay-now counts into Past events. An open payment on that booking stays payable and is labeled as an invoice, not event preparation. Withdraw is not offered after the event date.

## Money

| Figure | Source | Meaning |
|---|---|---|
| Total spent | `LNKup_Financial::vendor_dashboard()` `revenue_paid` | Sum of paid payment CPT `_lnkup_total_amount`. Not sales revenue. |
| Amount due | `vendor_money_contract()` outstanding cents | Pending and failed payment CPT totals. Not the 100-row ledger window. |
| Growth “event investment” | Same paid spot-fee total previously labeled lifetime revenue | Booth spend. |
| Chart window | `revenue_over_time()` last 90 days | Empty window does not mean lifetime spend is zero. |
| Receipt total | Receipt row `amount` captured at issue | Not recomputed with current fee rules. Line items come from the linked invoice when one exists. |

Credits are `LNKup_Credits::available_total()`. Referral earnings stay on the referral ledger and are not added to booth spend.

## Growth claims removed

“Fast-growing market near {home city}” and the hardcoded Summer/Fall best season are not calculated. Market cards state that a city is outside the home market unless the city matches. Fastest-growing rankings are omitted unless a later metric defines a sample threshold. Open-opportunity facts remain.

## Readiness

Pending checklist labels describe the remaining action. Organizer-owned tasks (booth, parking, load-in) say the organizer has not published them. Completed items stay out of Next steps because `pending_top` only includes undone items, and those items now use pending labels.

## Weather

Missing date copy does not tell the vendor to edit the event.

## Visual overhaul pass: data findings

| Symptom | Cause | Fix |
|---|---|---|
| Explore showed `Atlanta, NC`, `Houston, NC`, `Nashville, NC` | `scripts/e2e/seed.php` renamed tour stops but kept the default `_lnkup_state = NC` | The seed now writes GA, TX, and TN. A one-time fix (`lnkup_e2e_tour_state_v1`) updates only events whose `_lnkup_e2e_key` is `event:tour-houston`, `event:tour-atlanta`, or `event:tour-nashville`, then reindexes their opportunities. Real events are never touched. |
| Wallet overview listed five pending $99.07 rows with Pay now for one event | Repeated seed runs deleted payment posts but left their ledger rows marked `pending` | `LNKup_Transactions::format()` now returns `payment_status` from the payment post, or `removed` when the post no longer exists. The wallet shows those rows as "no longer due" without Pay now. The ledger is not edited. |
| Growth funnel showed a "Booked" stage | `renderFunnel()` set booked to 85% of the approval rate | The funnel uses real lifetime counts (submitted, approved, paid or completed) with percentages of submitted. |
| Growth KPIs lost their `$` and `%` | The count-up animation replaced formatted text with raw numbers | The animation is off for KPIs. Each KPI has a unit and a time period. |
| Markets listed Atlanta and Nashville twice | Market trends returned duplicate city rows | `market_expansion()` and the JS both dedupe by city. |
| Documents showed "No documents uploaded yet" while a certificate was in review | The empty state checked `attention_count === total`, which counts pending items | The empty state shows only when nothing is uploaded. The summary shows verified, in review, expiring, and not uploaded counts. |
| Past-event balances appeared as upcoming payment tasks | Past payable bookings were not separated in `today_priorities()` | They are grouped as "Past event balances" under a Past events heading in the Action Center. The amount is still shown and still payable. |
| Recommendation copy said categories were "trending" | No trend metric backed the claim | It now reads "Open opportunities use this category…". Market chips read "Markets with recent listings". |
| Referral copy hardcoded 20% for 12 months | Fixed string in `LNKup_Organizer_Recruit_Render` | It reads `commission_percent()` and `window_months()`. |
| Receipt PDF link returned 503 locally | Dompdf is declared in `composer.json`, but `vendor/` is not installed here | The Download PDF link renders only when `\Dompdf\Dompdf` is available. Print still works. |

## Test fixtures in reporting

Rows with `_lnkup_e2e_key` are E2E fixtures (`LNKup_Vendor_Presentation::is_e2e_fixture()`). When `wp_get_environment_type()` is `production`, they are excluded from Growth "active opportunities". Local and staging keep them so the specs have data. Nothing is deleted.
