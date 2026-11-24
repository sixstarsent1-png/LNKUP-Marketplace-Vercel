# Security and permissions review

## Permission model

| Actor | Can | Cannot |
|---|---|---|
| Signed-out visitor | Public pages, public embeds, public resolver / event payload, demand taps, cohort interest form | Any `organizer/*`, `connector/*`, `comms/marketing/*`, `app/share-link`, diagnostics |
| Vendor | Market alerts, favorites, acceptance link for own paid booking, share links (plain URL unless booked) | Organizer marketing data, other vendors' bookings, Command Center |
| Organizer / team (`broadcast`) | Marketing workspace and links for own events; performance for own links | Other organizers' links (403), non-owned events |
| City Connector / partner | Share & Track links forwarding their own code | Other connectors' links |
| `lnkup_marketing` role | Command Center, campaigns, segments (counts only), partner/cohort pages, guides (checklist enforced) | Financial records, revenue attribution, vendor documents, user management, settings, flag changes, `edit_posts` |
| Finance / `lnkup_manage_all` | Revenue attribution report + CSV | — |
| Administrator (`manage_options`) | Flag toggles | — |

## Checks performed

| Risk | Control | Evidence |
|---|---|---|
| Broken object-level authorization | Ownership checks on every link/status route (`user_owns`, `can_target`, `can_promote_event`, `vendor_booked_event`); campaigns require `lnkup_manage_marketing` and a different approver | PHP: attribution, organizer-marketing, distribution, marketing-campaigns, app-links suites; Playwright Journey H |
| Open redirect | `/l/` stores a target type + ID/key and resolves an internal URL at click time; `wp_validate_redirect` + `wp_safe_redirect` | PHP "arbitrary URL targets impossible"; Playwright "tracked link codes cannot redirect off-site" |
| Attribution manipulation | Touch values sanitized per key; URL-shaped labels dropped; click IDs reduced to flags; referral credit stays with the existing arbiter | PHP attribution suite |
| XSS | All output escaped; cohort/partner copy sanitized on save; query parameters never echoed raw | Reflection spot-check on 8 public routes (no payload reflected); cohort title `<script>` stripped in a browser test |
| CSRF | Admin actions via `admin-post.php` with `check_admin_referer`; REST uses cookie nonce or app tokens; cohort form uses honeypot + consent + rate limit (public, no account) | Command Center PHP + browser form tests |
| SQL injection | `$wpdb->prepare()` everywhere; filter values bound | PHP "filter values are bound, not interpolated" |
| CSV injection | Exported cells starting with `= + - @` (non-numeric) are prefixed with `'` | PHP CSV safety checks |
| Private data in public endpoints | Public payloads built from an explicit allowlist; no fees, inventory, emails, street address when hidden, application data | PHP payload checks (no `vendor_fee`, `spots_remaining`, hidden address, email); embed tests |
| Share previews | OG/preview output only uses public event fields; acceptance card shows no personal data | Distribution suite |
| Consent | Promotional campaigns respect unsubscribes, preferences, outreach suppression bridge; ad platforms require consent and skip staff | Campaign and attribution suites |
| Credentials | GA4 API secret, TikTok token, Search Console service account only from environment / `wp-config.php`; status screens show presence only | Ads status test "never exposes secrets"; Search Console reads constants only |
| Test traffic to ad platforms | Off unless production host or explicit test mode (GA4 validation endpoint, TikTok test code) | Attribution suite |
| Rate limits | Resolver/public event 120/h/IP, embeds 300/h/IP, demand 20/h/IP, cohort 5/day/IP | Code review |

## Findings to note (not changed here)

1. **CPT capability type (pre-existing).** All LNKup CPTs use `capability_type => 'post'`, so any account with `edit_posts` can list private CPTs (applications, documents, payments) in wp-admin. For this reason the `lnkup_marketing` role was **not** given `edit_posts`; guide authoring needs an editor account, with the editorial checklist enforced for all non-administrators. Recommended follow-up: custom capability types for private CPTs.
2. The cohort interest form is a public, unauthenticated form (honeypot, consent, 5/day/IP); add a CAPTCHA if abuse appears.
3. The local test site's administrator account (ID 1) disappeared during this session for an unknown reason and was recreated locally with the wp-env defaults; no production impact, but worth knowing if local tests start failing on missing admin.
