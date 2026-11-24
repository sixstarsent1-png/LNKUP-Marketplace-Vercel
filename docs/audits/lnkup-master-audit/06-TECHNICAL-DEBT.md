# 06 — Technical debt (ranked by business impact)

| Rank | Debt | Evidence | Impact | Recommendation |
|---|---|---|---|---|
| 1 | **Diverged release lines + manual deploys** | H 4 ahead / 39 behind `origin/master`; both say 12.33.x; deploy workflow never succeeded (`SG_SSH_KEY` missing); 23+ local branches and 5 worktrees; 19 tracked + 10 untracked plugin/theme zips in the repo | Fixes written but not live; regressions on each manual upload; nobody can name the production revision | One trunk, tag-based deploys, staging site, zips out of git (`.gitignore` + `git rm --cached`) |
| 2 | **Flags without visibility** | 40+ default-off flags in 7 places (`LNKup_Feature_Flags`, `LNKup_Rollout`, Match, Passport, Trust, Org Growth, Comms) | Large built features may be dark in production with no record | Single flag registry + read-only admin screen + CLI dump |
| 3 | **`$request['id']` read where a JSON body can override the URL** | Fixed only in `class-operations-rest.php` (M). **202 uses in 22 other REST classes** on H | Same "Event not found" class of bug elsewhere; risk that a permission callback and a handler read different IDs | Shared `route_id()` helper (from M) applied across REST classes, with a test sending a conflicting body `id` |
| 4 | **Oversized files** | `public/class-shortcodes.php` 7,316 lines; `includes/helpers.php` 3,845; `class-organizer-dashboard-views.php` 2,684; `class-notifications.php` 2,560; JS files of 2,200–2,500 lines (`marketplace.js`, `financial.js`, `connector.js`, `organizer-crm.js`) | Merge conflicts between the two lines concentrate here; slow review | Split by view/feature when touched; no big-bang refactor |
| 5 | **Unbounded queries** | 106 `'posts_per_page' => -1` in `includes/` and `public/` | Fine at today's volume (27 public events, 153 opportunities); grows linearly with vendors and applications | Replace in hot paths (dashboards, money, CRM) with paged or indexed queries; the pass-token table shows the pattern |
| 6 | **Two flag systems, two push senders, two notification paths** | `LNKup_Push_Service` + comms push channel; legacy `LNKup_Notifications` + comms engine (delegation hook) | Correct today, but every new message must be wired twice | Once comms flows are on and stable, retire the legacy senders per key |
| 7 | **Next.js app partially mocked and type-broken on H** | `app/admin/{money,events,users}`, `app/vendor/more` import `@/lib/data`; 18 `tsc` errors on H; 0 unit tests; Production deploy from July | Third client that drifts | Decide its role (see 07); if kept, CI must run `tsc` and Playwright against a mock-mode build |
| 8 | **Docs drift** | 85 docs; `SYSTEM_OVERVIEW` "~200 routes" vs 464; `BUILDOUT_CHECKLIST` says private uploads blocked in production (now 403-protected); `.cursor/rules` mention plugin 12.1.0 | AI and human sessions start from wrong facts | Generate counts from code in CI; keep one CURRENT_STATE as the only status doc |
| 9 | **Third-party leftovers in production** | WooCommerce, ThemeIsle/Otter, demo "fitness trainer" pages and products | Page weight, SEO pollution, attack surface | Remove unused plugins and content |
| 10 | **Test environments sprawl** | 4 wp-env stacks running (ports 8892–8897) mounting 4 different worktrees | Test results don't say which code they ran against | Record the commit in every test report (the repo's reports mostly do) |
| 11 | **Dead or placeholder code** | `LNKup_Premium_Listings::purchase` never called; financing and insurance "placeholder" classes; escrow without routes | Confusing surface; looks built when it isn't | Remove or move behind an explicit "not built" flag |

Not found:
- circular dependency problems
- PHP syntax errors (582 files lint clean)
- committed secrets
- sample data reaching live Expo screens
