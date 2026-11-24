# Uncommitted change inventory

## This work

Everything from Phases 1–10 is committed (see README.md for the commit list). Phase 10 commits the journeys spec, these docs, `CURRENT_STATE.md` / `SYSTEM_OVERVIEW.md` sections, the version bumps (`lnkup-marketplace` 12.37.0, `lnkup-communications` 1.4.0), a Command Center copy fix and the flag-aware expectations in `partnerships-programs-test.php`.

Shared files were committed with hunk-level staging, so only lines from this work went into these commits. In `lnkup-marketplace.php` that means the loader and init lines and the version lines; in `signup.tsx` and the organizer event screen, only the attribution and share edits. The other session's edits in those files stay uncommitted.

The temporary local helpers in `scripts/e2e/tmp/` (fixtures, screenshots, staging scripts) were deleted and never committed.

## Belongs to the concurrent session (left untouched)

At the time of the Phase 10 commit, `git status` showed uncommitted work from another session in these areas. None of it is part of this work, and it was neither staged nor reverted.

| Area | Examples |
|---|---|
| Mobile vendor experience v2 | `lnk-up-marketplace-mobile/app/(vendor)/*`, `app/wallet/`, `components/marketplace/`, `components/vendor/*`, `lib/vendor/*`, `lib/api/vendor-*.ts`, `lib/api/event-groups.ts`, `lib/api/locations.ts` |
| Edits on top of files from this work | `app/event/[eventId].tsx` (signed-in vendors get a full vendor event view), `app/signup.tsx` (location field, returnTo), `app/(organizer)/events/[eventId].tsx` (location field), `lib/analytics/mobile-events.ts`, `lib/navigation/pending-destination.ts`, `public/js/organizer-marketing.js` |
| Organizer / vendor OS UI | `includes/class-os-ui.php`, `class-vendor-ui.php`, `class-organizer-presentation.php`, `class-vendor-presentation.php`, `public/class-organizer-workspace-view.php`, `public/css/*-os.css`, `public/js/*-os.js` |
| Location normalization | `includes/location/`, `admin/class-location-health-admin.php`, `public/js/location-field.js`, `components/location-field.tsx`, Next.js `app/api/locations/` |
| Event groups / booking contract | `includes/class-event-groups.php`, `includes/class-vendor-booking-contract.php`, `includes/access/` |
| Public discovery gating | `includes/public/class-public-discovery.php` (hides spot counts for gated visitors; causes the one `public-website-test` failure) |
| Next.js app | `lnk-up-marketplace-ui/*` (launch, onboarding, vendor pages, discovery) |
| Many marketplace classes and dashboard scripts | e.g. `class-applications*.php`, `class-financial-*.php`, `class-growth-*.php`, `matching/*`, `portfolio/*`, theme `inc/*`, `assets/dist/*` |
| Tests and probes | `tests/e2e/location.spec.ts`, `public-access.spec.ts`, `vendor-os.spec.ts`, `tests/mobile/*`, `scripts/e2e/php/mobile-vendor-contract-test.php`, probes |
| Build artifacts | `*.zip` under `wp-content/plugins`, `wp-content/themes`, `dist/` |
| Docs | `docs/mobile-vendor-v2/`, `docs/organizer-experience-v2/`, `docs/vendor-experience-v2/` |

That session's in-progress edits also make the mobile `tsc --noEmit` fail (2 errors in `components/request-card.tsx`) and cause one check in `public-website-test` to fail.

## Local environment state (not in git)

- Marketing flags were reset to off after testing (their default).
- The local administrator (ID 1) was missing during this session and was recreated as `admin` / `password` (wp-env default) with ID 1.
- Tracked links created by the journeys spec for `e2e_org_a` are archived at the end of each run.
