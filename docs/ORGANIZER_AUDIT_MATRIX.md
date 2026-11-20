# Organizer Experience 2.0 — Audit Matrix

Scope: organizer role on `lnkup-marketplace` + `lnkup-theme`, and `lnk-up-marketplace-mobile/app/(organizer)`.

**Runtime environment:** local `@wordpress/env` (WordPress latest, PHP 8.1, Apache) on `http://localhost:8888`, seeded by `scripts/e2e/seed.php` (two organizers, two vendors, staff per role, events with multi-slot / $0 / capacity-2 opportunities). Commands:

```bash
npm run e2e:env      # start
npm run e2e:seed     # idempotent seed, writes scripts/e2e/.env.e2e
npm run e2e:lint     # real php -l on every plugin/theme file
npx wp-env run cli wp eval-file /var/www/html/wp-content/lnkup-e2e/probe-organizer.php
npm run e2e:run -- tests/e2e/<spec> --project=chromium
```

Verification legend: **Verified (runtime)** — reproduced on wp-env by `probe-organizer.php` or a Playwright spec. **Verified (static)** — code path read end to end. **Fixed (runtime)** — the same probe/spec passes after the change.

## Baseline probe run (before Sprint B)

```
FAIL  org B reads org A support tickets                    expected=denied  actual=200
PASS  org A reads own support tickets                      expected=allowed actual=200
FAIL  security staff reads event financials                expected=denied  actual=200
FAIL  operations staff reads event financials              expected=denied  actual=200
PASS  owner reads event financials                         expected=allowed actual=200
FAIL  security staff adds staff                            expected=denied  actual=200
PASS  security staff reads roster                          expected=allowed actual=200
PASS* org B calculates refund on org A payment             expected=denied  actual=500   (*crash, not a permission check)
FAIL  org B invites vendor into org A opportunity          expected=denied  actual=200
FAIL  duplicate active invite is refused                   expected=denied  actual=200
FAIL  spots_remaining reflects approved/paid holds         total=2 holds=1 remaining=2
4/11 probes passed
```

## After Sprint B

`probe-organizer.php`: **27/27 passed** (tenant, staff matrix, capacity guard, refunds/credit/cap, invites, respond binding, Create Event replay, discover). `organizer-security.spec.ts`: **11 passed**. Vendor specs unchanged: **32 passed, 2 skipped**.

Found while fixing O6 (not in the original audit): `LNKup_Security::sanitize_payment_status` allowed only `pending|paid|failed|refunded|disputed`, so the registered meta sanitizer silently rewrote `processing` (ACH in flight) and `partially_refunded` to **`pending`** — making those bookings payable again. Allowlist extended; checkout now refuses any non-`pending` payment (`payment_not_payable`, 409).

Rows below marked *(B)* are now **Fixed (runtime)** unless the Status column says otherwise.

## Register

| # | Area | Problem | Root cause / files | Sev | Fix (sprint) | Test | Status |
|---|---|---|---|---|---|---|---|
| O1 | Operations REST | Any organizer reads any event's vendor support tickets; incident/support `PUT` checks the row ID as if it were an event ID | `class-operations-rest.php` `organizer_permission` treats every `id` as an event; `list_support` has no event check | Critical | ID-type aware permission; event-owned list (B) | probe | Fixed (runtime) |
| O2 | Booths, parking, load-in, map elements, incidents, support | Rows updated by primary key only — another event's rows can be overwritten | `class-booths.php`, `class-parking.php`, `class-loadin-schedule.php`, `class-map-elements.php`, `class-incidents.php`, `class-support-requests.php` | Critical | `WHERE event_id = %d` + application-belongs-to-event checks (B) | organizer-security.spec | Fixed (static) |
| O3 | Staff permissions | Every staff role is treated as the owner: financials, staff management, map, packets, broadcasts, lifecycle | `LNKup_Capabilities::organizer_owns_event()` returns true for any staff row; `staff_role` never read | Critical | `staff_can()` matrix; owner-only financials/staff (B) | probe | Fixed (runtime) |
| O4 | Capacity | Approvals never reserve inventory; unlimited oversell | `_lnkup_spots_remaining` only written at create/edit/duplicate | Critical | `LNKup_Opportunity_Inventory` recompute under lock, approve guard (B) | probe + spec | Fixed (runtime) |
| O5 | Payments | Concurrent approvals can create two payment posts | `create_from_application` check-then-insert | High | per-application lock (B) | — | Fixed (static) |
| O6 | Refunds | Approve/deny race; multiple pending refunds; cap ignores prior refunds; partial refund marks payment `refunded`; `credit` type refunds money **and** grants credit; bypasses Financial Service | `class-refunds.php`, `class-refund-rules.php` | High | conditional updates, cap, single pending, partial status, credit-only (B) | — | Fixed (runtime) |
| O7 | Refund calculate | Unowned `POST /financial/refunds/calculate` → HTTP 500 (and no ownership check) | `class-financial-rest.php` `refunds_calculate` | High | ownership + error handling (B) | probe | Fixed (runtime) |
| O8 | Cashout | Balance check → insert → debit without a lock; `cancel` unconditional; `type` not allow-listed | `class-organizer-payouts.php` | High | per-organizer lock, conditional cancel (B) | — | Fixed (runtime: type allowlist; lock + conditional cancel static) |
| O9 | Invites | Can target another organizer's event/opportunity; duplicates accepted; `respond` public and re-answerable | `class-vendor-invites.php` | High | ownership, dedupe, logged-in vendor + state guard (B) | probe | Fixed (runtime) |
| O10 | Event create | Not atomic; no event-level idempotency; partial success shown as green toast | `class-organizer-onboarding.php`, `class-ajax.php`, `marketplace.js` | High | event `client_token`, partial response, inline warning + retry (B/C) | organizer-event-create.spec | Fixed (runtime) |
| O11 | Test hook in prod | `X-LNKup-Test-Block-Opp` header honored in production | `class-organizer-onboarding.php` | Medium | only when `WP_DEBUG` (B) | — | Fixed (static) |
| O12 | Maps | `duplicate_map` / `save_template` read unverified source events; `apply_template` permission reads missing `id` | `class-map-rest.php`, `class-map-templates.php` | Medium | ownership checks (B) | — | Fixed (static) |
| O13 | Sponsor invoices | Unverified `event_id` | `class-sponsor-payments.php` | Medium | ownership (B) | — | Fixed (static) |
| O14 | Intelligence AJAX | HTML `wp_die` on GET, 200 on permission failure, no try/catch | `class-ajax.php` `handle_event_intelligence` | Medium | (B) | — | Fixed (runtime) |
| O15 | Discover vendors | Includes `private` profiles; `q` AND-narrows; "My vendors" totals wrong | `helpers.php` discover query, `class-vendor-directory.php` | Medium | (B/D) | — | Fixed (runtime) |
| O16 | Vendors / Lists / Invites views | Skeleton never resolves from sidebar (fetch only on `#hash`); `syncHashNav` overwrote the active `?view=` section | `organizer-crm.js` (`currentCrmSection`, safe fetch, toasts) | High (UX) | (D) | organizer-crm.spec | Fixed (runtime) |
| O17 | Request More Information | Does not exist | `info_requested` status, `/applications/{id}/request-info` + `/respond-info`, web + native reply | Feature | (D) | organizer-info-request.spec, probe, lifecycle 4–5 | Built (runtime; native static) |
| O18 | Check-in | `set_vendor_status` bypasses check-in gates; no audited override; overridden rescan returned `payment_pending` | `class-operations-rest.php`, `class-checkins.php` | Medium | gated statuses, owner/ops override with reason + ops log, already-checked-in first (E) | probe, organizer-checkin.spec, lifecycle 11 | Fixed (runtime) |
| O19 | Notifications | Approval sends `payment_required` twice (dedupe rejects; DB error logged each time) | `send_application_approved` + `create_from_application` both call `send_payment_required`; `claim_delivery` plain `INSERT` | Low | `INSERT IGNORE` (done in Sprint 0) | debug.log | Fixed (runtime: no new DB errors on reseed) |
| O20 | Stale specs | Organizer specs assume hash routes, old wizard, `/wp-login` regex vs `/log-in/` | `tests/e2e/organizer-*.spec.ts`, `vendor-dashboard-ui.spec.ts` and others | — | rewrite (G) | — | Fixed (runtime: organizer group 102/102) |
| O21 | Native organizer | No request cache; approve hard-codes `approved`; no ref guards/idempotency on publish & cash out; demo events served on live failure; push routing gaps; client-side "collected" recompute | `lnk-up-marketplace-mobile/app/(organizer)`, `lib/api/organizer-*.ts` | Medium | (F) | typecheck + verify scripts | Fixed (static; no device run) |
| O22 | Home | CRM widgets and weather readiness card dropped from organizer Home during the redesign | `class-organizer-dashboard-views.php` | Medium | restored (C) | organizer-home.spec | Fixed (runtime) |
| O23 | Application cards | Inline forms (Decline, Ask for info) wrapped into a second column off-screen | `marketplace-v2.css` | Medium (UX) | `flex-wrap: nowrap` on v2 request rows (D) | organizer-info-request.spec | Fixed (runtime) |
| O24 | CRM slide-over | Close button sat under the WP admin bar | `organizer-crm.css` | Low | z-index (D) | organizer-crm.spec | Fixed (runtime) |
| O25 | Maps floor-plan upload | Uses `wp/v2/media`; organizers lack `upload_files`, so upload fails | `maps.js` | Medium | failure now shown as a toast; dedicated endpoint **not built** | — | Open |

## Baseline Playwright run (before organizer changes)

Specs: organizer-crm, organizer-event-create, organizer-launch-checklist, organizer-financial, dashboard-enhancements, dashboard-consistency, apply-with-profile, vendor-dashboard-ui → **68 passed, 35 failed**. Failures are stale assertions (see O20) plus one real Vendor 2.0 regression found and fixed in Sprint 0 (vendor Home lost the shared page header; `dashboard-consistency › vendor /vendor-dashboard/` now passes).

## Final results (Sprints C–G, 2026-11-16, wp-env)

| Check | Result |
|---|---|
| `probe-organizer.php` | **46/46** |
| `lifecycle.php` (create → publish → apply ×2 → request info → respond → approve/hold → full-slot refusals → pay (simulation) → packet → booth → check-in + rescan → refund → cancel releases spot → waiting vendor approved) | **16/16** |
| Organizer group: all `organizer-*.spec.ts` + `dashboard-consistency` (incl. 10 phone-viewport tests) + `apply-with-profile` + `vendor-dashboard-ui` | **102 passed, 0 failed** (`scripts/e2e/reports/final-organizer-run.txt`) |
| Vendor journey / security / operations / profile-strength | **43 passed, 6 skipped (data-dependent)** after updating the stale profile-strength assertions |
| Mobile `npx tsc --noEmit` + phase20/23/24/26 + ui-polish verify scripts | clean / pass (static only) |
| Payments | simulation mode only; no live charges or payouts |

### Baseline comparison

A worktree of `HEAD` (before any organizer change) was mounted into wp-env and the same specs were run.

- Full non-app run: baseline **91 passed / 61 failed**; first post-change run 92 passed / 69 failed.
- Every extra failure was traced and resolved: Vendor 2.0's intentional `/log-in/` URL (specs updated), Home widgets lost in the redesign (restored, O22), tab-to-link changes (specs updated), stale profile-strength selectors (spec updated), and one login reauth flake (spec moved to the shared retrying login helper).
- **Still failing, and failing identically at baseline** (not caused by this work): `connector-program` (Charlotte text matches 3 elements), `event-intelligence` ×2 (expects removed `#intelligence` panel), `vendor-financial` ×2, `vendor-growth` (Growth sidebar link), `vendor-reputation` ×2. These touch the landing, Find Events and vendor profile work that was already uncommitted on Nov 10.

## Remaining issues

- O25 map floor-plan upload needs a dedicated organizer upload endpoint.
- Vendor first login stacks the product tour and welcome modals (pre-existing; tests close them in a loop).
- Instant approval is not implemented server-side, so the wizard does not offer it.
- Native organizer changes are typechecked and script-verified only; not run on a device.
- Stripe Connect onboarding and real test-card charges were not exercised (no Stripe test keys in wp-env).

## Deferred (documented, not built)

- Two-way vendor ↔ organizer messaging (`lnkup-communications` plugin, per-application threads).
- Native QR scanning (`expo-camera`; requires new store build).
- Multi-organization teams beyond per-event staff.
