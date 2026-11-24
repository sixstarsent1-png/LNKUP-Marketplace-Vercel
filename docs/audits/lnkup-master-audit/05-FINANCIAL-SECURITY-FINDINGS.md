# 05 — Financial and security findings

Each finding is labelled:

- **VERIFIED**: observed in production, or reproduced by a test run in this audit
- **CONFIRMED IN CODE**: the defect is visible in source; not exercised at runtime
- **POTENTIAL**: a risk that depends on live settings or conditions I couldn't see

## Financial

### Authoritative sources (who drives which number)

| Figure | Source of truth | Code |
|---|---|---|
| What a vendor is charged | `lnkup_payment` meta `_lnkup_total_amount`, recalculated at checkout by `LNKup_Payments::refresh_pending_fees` | `includes/class-payments.php` L330, L339 |
| Paid or not | `_lnkup_payment_status`, written only by `LNKup_Financial_Service` (finalize, refund) and webhook handlers | `includes/financial/class-financial-service.php`, `class-stripe-webhooks.php` |
| Organizer money | `organizer_money_contract` (payment records, not summaries) | `class-financial-service.php` |
| Vendor wallet | `vendor_money_contract` | same (M counts more statuses, see C2) |
| Platform revenue | fee breakdown stored per payment (`_lnkup_lnkup_fee`, `_lnkup_vendor_checkout_fee`, `_lnkup_organizer_commission`) | `LNKup_Payments::apply_fee_breakdown_to_payment` |
| Partner commissions | partner ledger rows, one per payment (attribution arbiter) | `includes/partnerships/*` |
| Invoices | separate `invoices` table, **snapshot at creation** | `includes/class-invoices.php` |

### Current fee model (code defaults; production settings not visible)

| Component | Default | Code |
|---|---|---|
| Vendor checkout fee (flat, added to booth price) | **$5.99**; $0 for free booths | `class-fee-engine.php::resolve_vendor_checkout_fee_from_settings` L109-117, free rule L33-35 |
| Organizer commission (taken from booth fee) | **6%** of booth price | `resolve_organizer_commission` L217-224 (`organizer_commission_percent`, rules can override) |
| Card processing (added to vendor total) | 2.9% + $0.30 of (booth + checkout fee) | L12-13, L77-89 |
| ACH (when `ach_enabled`) | 0.8%, capped at $5 | L14-15 |
| Fee payer | `vendor` by default; `organizer` mode moves the checkout fee into commission | L39-43 |
| Reserve | 0% default; FINANCIAL.md recommends 5% | L384 vs FINANCIAL.md L172 |

Example, $300 booth: the vendor pays $300 + $5.99 + $9.17 processing (2.9% × $305.99 + $0.30) = **$315.16**. LNKup earns $5.99 + $18.00 = **$23.99**. The organizer nets **$282.00**.

### Findings

| # | Finding | Label | Detail |
|---|---|---|---|
| F1 | Fixes for the earlier money issues are only partly in production | VERIFIED (code diff + production version) | Master fixed:<br>- vendor totals ignoring `partially_paid`, `partially_refunded`, `disputed`<br>- invoice/receipt sort and paging<br>- booth/parking editing<br><br>Production runs H, which lacks the receipt paging, the money-contract change and the booth fix. |
| F2 | Invoice can disagree with the amount charged | CONFIRMED IN CODE | The invoice `total_amount` is set once (`class-invoices.php` L184-198). `refresh_pending_fees` re-prices the payment at checkout (four callers: `class-checkout.php:105`, `class-financial-service.php:31,66`, `class-stripe-provider.php:647`) but never touches the invoice. `mark_paid` then compares the paid amount with the stale total, which can mark a fully paid invoice `partially_paid`. Triggers: a fee setting change, discount, ACH vs card, or a commission rule edited between approval and payment. |
| F3 | Late "failed" webhook can't undo a paid booking | VERIFIED (code + `financial-sync-test` 37/37) | Guard at `class-stripe-webhooks.php` L369-379 covers paid, refunded, partially_refunded, partially_paid, disputed and chargeback_lost, plus superseded intents. Fixed since the October review. |
| F4 | Webhook signature verification | VERIFIED (code + `financial-security-test` 12/12) | Unsigned or missing-secret requests are rejected unless test mode is on and no secret is set. 300-second tolerance. Multiple `v1` signatures accepted. The deploy excludes `vendor/`, so this manual verifier is what runs in production. |
| F5 | "Processing" can overwrite refunded or disputed | CONFIRMED IN CODE (low impact) | `mark_payment_processing` guards only `paid`. |
| F6 | Booking integrity and inventory | VERIFIED (`booking-application-os-test` 73/73, `reapply-policy-test` 37/37) | Per-opportunity locks (`GET_LOCK`), allowed status transitions, capacity derived from applications (`LNKup_Opportunity_Inventory`), 409 `opportunity_full`, duplicate-apply lock. |
| F7 | Refunds and disputes | VERIFIED (`financial-refund-dispute-test` 22/22) | One open request per payment, cap at remaining balance, partial refunds, chargeback events. |
| F8 | Cash-out | VERIFIED (`financial-cashout-test` 22/22) | Per-organizer lock, standard/instant allow-list, replay token. |
| F9 | Partner commission default loses money | CONFIRMED IN CODE / POTENTIAL in production | City Connector v1 pays **10% of booth GMV, uncapped** (`class-commission-rules.php` L36-50), more than LNKup's own take on most bookings (see the example above). Production settings unknown. |
| F10 | No real Stripe journey has ever been run | VERIFIED (absence) | The local wp-env has no Stripe keys, and the repo's BUILDOUT says the test-mode card run needs staging keys. Every financial suite runs with Stripe off. |
| F11 | Platform fees hidden from organizers (12.33.3) | H only | `LNKup_Organizer_Fee_Privacy` (`organizer-fee-privacy-test` 25/25 here) is **not on master**. Merging must keep it. |

## Security

### Public (no-login) REST routes — all 24 reviewed

| Route | Method | Assessment |
|---|---|---|
| `/auth/login`, `/auth/register` | POST | Rate limited on failures only; successful sign-ups unlimited (D6); password trimmed (D7) |
| `/stripe-webhook` | POST | Signed (F4) |
| `/financial/checkout/native-return` | GET | Redirects to `lnkup://` with sanitized params only |
| `/applications/apply-context/{id}`, `/opportunities/{id}/application-questions` | GET | Public opportunity data |
| `/marketplace/track/view`, `/marketplace/track/event` | POST | Rate limited (`LNKup_Match_Funnel::within_rate`); anonymous users share one bucket |
| `/connector/interest`, `/partners/apply` | POST | **No rate limit, honeypot or CAPTCHA found** (static); spam leads possible |
| `/referrals/resolve` | GET | Public code lookup |
| `/maps/discovery/pins`, `/maps/share/{token}` | GET | Public pins; share by token |
| `/organizers/{slug}/public`, `/reputation/vendors/{slug}/public`, `/passport/vendors/{slug}` (+ history, reviews) | GET | Public profiles (Passport gated) |
| `/public/opportunities/{id}/card` | GET | Embed card, only fields already public |
| `/tours/{id}`, `/portfolio/public/tours/{slug}` | GET | Public tours (production returns `lnkup_not_found` for unknown slugs) |
| `/growth/unsubscribe` | GET/POST | Token-based |
| external events (1), marketplace (1) | GET | Public listing feeds |

### Findings

| # | Finding | Label | Detail and fix |
|---|---|---|---|
| S1 | Private CPTs not exposed | VERIFIED (production) | `wp/v2/lnkup_application`, `lnkup_payment`, `lnkup_document`, `lnkup_vendor_profile`, `lnkup_tour` → 404. |
| S2 | Vendor documents | VERIFIED (production) | No PDFs listed in `wp/v2/media` (0 of 417). Any path under `/uploads/lnkup-private/`, including non-existent files, returns **403**, while normal uploads return 404, so the deny rule is active. The repo doc claiming production is "blocked by NGINX Direct Delivery" is **out of date**. Remaining: documents uploaded before 12.20 still live under dated public folders; the migration and its safety probe exist only on M (D4). 289 JPEG and 123 PNG are publicly listed. Spot-check that none are licence photos. |
| S3 | User enumeration | VERIFIED (production) | `wp/v2/users` → 200, 14 users, plus `author-sitemap.xml`. Restrict both (D2). |
| S4 | App sessions survive password reset | CONFIRMED IN CODE | `class-mobile-auth.php`: 90-day tokens, max 10 per user, no reset/change hook (D1). |
| S5 | Draft events on public REST | CONFIRMED IN CODE (H) | Hide filter exists only on M (D3). |
| S6 | Tenant isolation for operations, CRM, maps, sponsors | VERIFIED by repo tests (ORGANIZER_AUDIT_MATRIX "Fixed (runtime)") | `LNKup_Booths::save` checks booth ↔ event (L66), so B1 is a functional bug, not a cross-organizer hole. |
| S7 | XSS sinks | CONFIRMED IN CODE | `external-events.js` L350 (vendor and event names), `marketplace-compare.js` L55 (opportunity names). Server-side `sanitize_text_field` reduces but doesn't remove the risk. |
| S8 | Secrets | VERIFIED (absence) | `git grep` of HEAD found no live or test Stripe keys, webhook secrets, AWS or Google keys, or private keys. Only `.example` env files are tracked. |
| S9 | Mobile token storage | VERIFIED (code) | SecureStore on iOS/Android; AsyncStorage only on web builds. |
| S10 | XML-RPC | VERIFIED (production) | Blocked at the host (no response). |
| S11 | Unsigned QR passes | CONFIRMED IN CODE (accepted risk) | Scans still require an authenticated staff actor, lock and payment checks. |
| S12 | Third-party plugins on public pages | VERIFIED (production) | WooCommerce (11 assets per page), Facebook Pixel, ThemeIsle/Otter (insecure http CSS). Each adds attack surface and page weight. Remove what LNKup doesn't use. |
| S13 | PHP compatibility | VERIFIED (`php -l` 582 files, PHP 8.1, 0 errors) | No PHP 8-only syntax beyond WordPress-polyfilled `str_contains`. |
