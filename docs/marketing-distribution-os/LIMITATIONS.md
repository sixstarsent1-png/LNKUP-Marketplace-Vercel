# Remaining limitations

## Needs credentials, approvals or production configuration (implemented, not verified live)

| Item | What is needed |
|---|---|
| Google Search Console data | Service account JSON + `LNKUP_GSC_SERVICE_ACCOUNT` / `LNKUP_GSC_PROPERTY` in `wp-config.php`; read access on the property |
| GA4 / Google Ads / TikTok | Measurement ID, API secret, Ads conversion ID + labels, TikTok pixel + access token; flags on; production host |
| iOS universal links for new paths | App build with the new `+native-intent` in the App Store, then `deep_links_v2` on |
| Android App Links | Play signing SHA-256 fingerprint saved in Command Center; Android build |
| App Store campaign attribution | Provider token (`pt`) from App Store Connect |
| Email / push delivery of new messages | Production comms providers; staging test recipients |
| Ad spend ROAS | Spend entered in Organizer Growth by channel/campaign |

## Functional limits

- Unclaimed external events open Find in the app instead of an event screen (web shows them with a disclosure).
- Market alerts are per-event messages governed by the comms frequency caps; there is no daily digest variant for market alerts yet (the existing saved-search digests still apply).
- Experiments compare two variants; no multi-armed or sequential testing.
- City + category pages require staff to enable each pair; there is no automatic suggestion queue yet (Command Center → Markets shows demand to guide the choice).
- The Command Center links out to Communications for editing campaigns rather than embedding the editor.
- No SMS provider is connected (shown as "Not configured").
- Search Console shows page-level clicks/impressions only (no query explorer, no indexing API).
- Lab performance numbers are dominated by the local server; no field CWV yet.

## Engineering notes

- Pre-existing: LNKup CPTs share the `post` capability type (see SECURITY_REVIEW.md). The marketing role intentionally lacks `edit_posts`.
- Theme templates `single-post.php`, `category.php`, `home.php` were added for the hub instead of `single.php`/`archive.php` so CPT singles and archives keep their current rendering.
- The concurrent session's in-progress changes cause one `public-website-test` failure and two TypeScript errors in `components/request-card.tsx`; they are not part of this work.
