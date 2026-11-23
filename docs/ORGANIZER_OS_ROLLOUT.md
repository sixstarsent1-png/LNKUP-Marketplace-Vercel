# Organizer Management OS — Production Enable & Rollback Checklist

Covers Wave 1 (review workspace, scoring, waitlist offers, org teams, pass tokens) and Wave 2 (event tasks, readiness, organizer document review, Overview). Everything ships dark: with every flag off, organizers see the classic dashboard.

## 1. Switches

All live in the `lnkup_settings` option (wp-admin → LNKup → Settings) and are read through `LNKup_Feature_Flags`.

| Setting | Turns on | Depends on |
|---|---|---|
| `application_review_v2_enabled` | Review workspace (assignments, rubric scoring, rubric editor, reviewer emails) on `?view=applications` | — |
| `waitlist_offers_enabled` | Timed waitlist offers + waitlist reorder | Review workspace for the reorder UI |
| `organizer_ops_v2_enabled` | Tasks, readiness checklist, Overview home, My tasks view, task digest email | Schema `2` |
| `organizer_document_review_enabled` | Per-type document requests / review / waive | Passport `document_vault_sharing` on (otherwise stays off) |
| `trust_org_teams` | Team members get scoped access to events (pre-existing flag) | — |
| `organizer_os_pilot_user_ids` | Comma/space separated user IDs (organizer owners). Turns the four Organizer OS flags on **only** for these users and their teams | — |

Pilot rule (`LNKup_Feature_Flags::organizer_os()`): a feature is on for a user if the global flag is on, **or** the user is in the pilot list, **or** any org the user belongs to (`LNKup_Org_Teams::orgs_for_user`) is owned by a pilot user. The task digest cron runs when the flag is on globally or the pilot list is non-empty.

`?mode=classic` on `?view=applications` always shows the classic requests page, so support can fall back per user without touching flags.

## 2. Before enabling

- [ ] Deploy plugin; confirm option `lnkup_organizer_os_schema` = `2` (installs on `init`, idempotent via dbDelta).
- [ ] Confirm tables exist: `{prefix}lnkup_pass_tokens`, `lnkup_app_review_assignments`, `lnkup_review_rubrics`, `lnkup_app_reviews`, `lnkup_waitlist_offers`, `lnkup_event_tasks`, `lnkup_event_task_comments`, `lnkup_task_templates`, `lnkup_app_document_reviews`.
  `wp db query "SHOW TABLES LIKE '%lnkup_%'"`
- [ ] Confirm crons are scheduled (`wp cron event list`):
  - `lnkup_waitlist_offers_expire` (hourly) — expires unaccepted offers, advances the waitlist
  - `lnkup_event_tasks_due` (daily, 13:00 site time) — overdue/due-today digest
  - `lnkup_documents_expire` (daily) — marks expired vendor documents
- [ ] WP-Cron actually runs in production (real system cron hitting `wp-cron.php`, or `DISABLE_WP_CRON` with an external runner).
- [ ] Outbound email works (`LNKup_Notifications::send`) — reviewer assignment, task assignment, document requests and digest all email.
- [ ] For document review: Passport `document_vault_sharing` enabled and vendors have shared documents.
- [ ] Run regression suites on staging:
  - `wp eval-file scripts/e2e/php/organizer-os-test.php`
  - `wp eval-file scripts/e2e/php/organizer-os-wave2-test.php`
  - `scripts/e2e/seed-organizer-os.php on`, then `run-specs.ps1 tests/e2e/organizer-*.spec.ts`, then `seed-organizer-os.php off`

## 3. Enable sequence

1. **Pilot** — add 2–5 organizer owner IDs to `organizer_os_pilot_user_ids`. Leave global flags off.
2. **Verify with a pilot organizer** (see §4). Watch for 1 week: PHP error log, REST 4xx/5xx on `lnkup/v1/events/*/tasks|readiness|documents`, `applications/*/review*`, `opportunities/*/waitlist*`.
3. **Teams** — if pilots use staff, enable `trust_org_teams` (globally) and have them invite a reviewer.
4. **General availability** — turn on global flags one at a time, in this order, at least a day apart: `application_review_v2_enabled` → `waitlist_offers_enabled` → `organizer_ops_v2_enabled` → `organizer_document_review_enabled`.
5. Clear the pilot list once all four are on globally (optional; it has no effect then).

## 4. Verification after each step

- [ ] Applications page shows the review workspace; assign a reviewer → reviewer receives the email, link opens the app.
- [ ] Reviewer can score; non-reviewer staff cannot (403, friendly card).
- [ ] Rubric editor saves; new scores use the new weights.
- [ ] Waitlisted app: reorder up/down persists; offer created on a freed slot, expires after the window.
- [ ] Event workspace: Readiness and Tasks tabs load (no infinite skeleton), apply a template, complete a task.
- [ ] Overview home shows Needs attention / Upcoming / Tasks; My tasks lists assignments.
- [ ] Documents tab: request a type → vendor gets email; approve / reject / waive update roster status.
- [ ] Vendor dashboard unaffected (apply, pay, packet).

## 5. Rollback

Rollback is a settings change — no code deploy or data migration needed.

1. Remove pilot IDs and/or uncheck the affected global flag(s). Effect is immediate on the next request.
2. With flags off:
   - Applications falls back to the classic requests page; assignments, scores and rubrics stay in their tables, untouched.
   - New waitlist offers cannot be made (409). Offers already sent stay valid until their deadline; the expiry cron keeps running regardless of the flag, so they still expire and the held slot is released.
   - Tasks/readiness/Overview disappear from nav; data is kept. The digest cron no-ops.
   - Document requests UI disappears; roster reverts to classic document statuses. Passport shares are unaffected.
3. Re-enabling restores everything as it was.
4. **Full removal (only if required):** unschedule crons (`wp cron event delete lnkup_waitlist_offers_expire lnkup_event_tasks_due`), drop the nine tables above and delete `lnkup_organizer_os_schema`. Not reversible — export first.

## 6. Known non-blocking items

- Pre-existing Playwright failures unrelated to these flags (fail with flags off too, from concurrent nav/workspace redesign): CRM invites ×2, event-create ×3, info-request "vendor replies", launch-checklist, workspace "shows event sections".
- Check-in scan and Financial specs can time out on slow local environments; they pass on rerun.
