# Changed file inventory

Files changed by the phase commits after the Phase 0 audit (`4714243..2ea217f`), generated from git. Phase 0 committed the concurrent session's work by area (`458301a`, `a787643`, `d525f9a`, `92a7718`, `6c06850`) and the audit docs (`4714243`); see AUDIT.md. Phase 10 files are listed in UNCOMMITTED_CHANGES.md until committed.

## 1fbfd58 — feat(seo): index policy, clean event/organizer/tour URLs, accurate schema and sitemaps (flag seo_index_policy)

| Status | File |
|---|---|
| added | `scripts/e2e/php/_marketing_helpers.php` |
| added | `scripts/e2e/php/marketing-flags.php` |
| added | `scripts/e2e/php/seo-policy-test.php` |
| added | `scripts/stage-hunks.mjs` |
| modified | `tests/e2e/public-website.spec.ts` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-organizer-onboarding.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-seo.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-marketing-flags.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/seo/class-index-policy.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/seo/class-seo-diagnostics.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/seo/class-seo-schema.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/seo/class-seo-urls.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/seo/class-sitemap-provider.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/seo/class-sitemaps.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |

## d16909f — feat(markets): Phase 2 market registry, city+category and weekly digest pages, event honesty notices, follow tour, OG images

| Status | File |
|---|---|
| modified | `scripts/e2e/php/_marketing_helpers.php` |
| added | `scripts/e2e/php/market-registry-test.php` |
| modified | `wp-content/plugins/lnkup-marketplace/admin/class-market-pages-admin.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-market-pages.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-marketplace-rest.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-seo.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-vendor-personalization.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-event-distribution.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-market-discovery.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-market-registry.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-og-images.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/seo/class-index-policy.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/seo/class-seo-schema.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/seo/class-seo-urls.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/class-shortcodes.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/css/market-pages.css` |
| added | `wp-content/plugins/lnkup-marketplace/public/fonts/OFL.txt` |
| added | `wp-content/plugins/lnkup-marketplace/public/fonts/Poppins-Bold.ttf` |
| added | `wp-content/plugins/lnkup-marketplace/public/fonts/Poppins-Medium.ttf` |
| added | `wp-content/plugins/lnkup-marketplace/public/js/event-follow.js` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/market-landing.js` |
| modified | `wp-content/themes/lnkup-theme/page-templates/market-landing.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/categories.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/connector-cta.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/event-types.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/faq.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/hero.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/how-it-works.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/launch-status.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/organizer-cta.php` |
| deleted | `wp-content/themes/lnkup-theme/template-parts/market/vendor-cta.php` |

## 12f2fca — feat(attribution): Phase 3 attribution params, tracked links, analytics taxonomy, ad platforms, revenue attribution

| Status | File |
|---|---|
| modified | `lnk-up-marketplace-mobile/lib/api/auth.ts` |
| added | `scripts/e2e/php/attribution-test.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-marketplace-analytics.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-ad-platforms.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-analytics-taxonomy.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-market-registry.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-marketing-tables.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-revenue-attribution.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-tracking-links.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/matching/class-match-funnel.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/meta/class-acquisition-attribution.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/lnkup-attribution.js` |

## 0e3ed5c — feat(organizer-marketing): Phase 4 organizer Marketing toolkit, local QR codes, list embeds

| Status | File |
|---|---|
| modified | `lnk-up-marketplace-ui/lib/api/vendor-packet.ts` |
| added | `scripts/e2e/php/organizer-marketing-test.php` |
| modified | `tests/e2e/lnkup-app-phase10-vendor-operations.spec.ts` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-app-promo.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-map-sharing.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/helpers.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-organizer-marketing.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-qr.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-tracking-links.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/vendor/qrcode.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/class-organizer-dashboard-views.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/class-shortcodes.php` |
| added | `wp-content/plugins/lnkup-marketplace/public/css/organizer-marketing.css` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/connector.js` |
| added | `wp-content/plugins/lnkup-marketplace/public/js/embed-list.js` |
| added | `wp-content/plugins/lnkup-marketplace/public/js/lnkup-qr.js` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/operations.js` |
| added | `wp-content/plugins/lnkup-marketplace/public/js/organizer-marketing.js` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/passport.js` |
| added | `wp-content/plugins/lnkup-marketplace/public/vendor/qrcode-generator/LICENSE` |
| added | `wp-content/plugins/lnkup-marketplace/public/vendor/qrcode-generator/qrcode.js` |
| modified | `wp-content/themes/lnkup-theme/inc/dashboard.php` |

## ebd0c78 — feat(campaigns): Phase 5 campaign approval + scheduling, saved segments, new filters, experiments, suppression bridge, marketing role

| Status | File |
|---|---|
| added | `scripts/e2e/php/marketing-campaigns-test.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-audiences.php` |
| added | `wp-content/plugins/lnkup-communications/includes/class-comms-campaign-workflow.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-campaigns.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-engine.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-schema.php` |
| added | `wp-content/plugins/lnkup-communications/includes/class-comms-segments.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-suppression.php` |
| added | `wp-content/plugins/lnkup-communications/includes/rest/class-comms-marketing-rest.php` |
| modified | `wp-content/plugins/lnkup-communications/lnkup-communications.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-marketing-role.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |

## b946420 — feat(distribution): Phase 6 external demand leads, claim notifications, connector Share & Track, partner pages, acceptance share

| Status | File |
|---|---|
| added | `scripts/e2e/php/distribution-test.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-registry.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/templates/catalog.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-connector.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-connector-marketing.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-distribution.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-partner-landings.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-tracking-links.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/seo/class-seo-urls.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/class-vendor-dashboard-views.php` |
| added | `wp-content/plugins/lnkup-marketplace/public/js/connector-marketing.js` |
| added | `wp-content/themes/lnkup-theme/page-templates/partner-landing.php` |

## a12a631 — feat(marketing): Phase 7 lifecycle alerts, consent source, resources hub, cohort landings

| Status | File |
|---|---|
| added | `scripts/e2e/php/retention-content-test.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-preferences.php` |
| modified | `wp-content/plugins/lnkup-communications/includes/class-comms-suppression.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-cohort-landings.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-lifecycle.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-market-registry.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-resources-hub.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
| added | `wp-content/plugins/lnkup-marketplace/public/css/resources.css` |
| added | `wp-content/themes/lnkup-theme/category.php` |
| added | `wp-content/themes/lnkup-theme/home.php` |
| added | `wp-content/themes/lnkup-theme/page-templates/cohort-landing.php` |
| modified | `wp-content/themes/lnkup-theme/page-templates/market-landing.php` |
| added | `wp-content/themes/lnkup-theme/single-post.php` |
| added | `wp-content/themes/lnkup-theme/template-parts/resources/list.php` |

## 6d68155 — feat(mobile): Phase 8 app deep links v2, public event screen, referral capture at signup, tracked native share

| Status | File |
|---|---|
| added | `lnk-up-marketplace-mobile/__tests__/deep-links.test.ts` |
| modified | `lnk-up-marketplace-mobile/app.json` |
| modified | `lnk-up-marketplace-mobile/app/(organizer)/events/[eventId].tsx` |
| modified | `lnk-up-marketplace-mobile/app/+native-intent.ts` |
| added | `lnk-up-marketplace-mobile/app/event/[eventId].tsx` |
| added | `lnk-up-marketplace-mobile/app/link.tsx` |
| modified | `lnk-up-marketplace-mobile/app/signup.tsx` |
| modified | `lnk-up-marketplace-mobile/lib/analytics/mobile-events.ts` |
| added | `lnk-up-marketplace-mobile/lib/api/deep-links.ts` |
| added | `lnk-up-marketplace-mobile/lib/navigation/deep-link-map.ts` |
| added | `lnk-up-marketplace-mobile/lib/navigation/pending-attribution.ts` |
| modified | `lnk-up-marketplace-mobile/lib/navigation/pending-destination.ts` |
| added | `scripts/e2e/php/app-links-test.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-app-links.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/class-app-promo.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-app-deep-links.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-connector-marketing.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-organizer-marketing.php` |
| modified | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-tracking-links.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/connector-marketing.js` |
| modified | `wp-content/plugins/lnkup-marketplace/public/js/organizer-marketing.js` |

## 2ea217f — feat(marketing): Phase 9 Marketing Command Center

| Status | File |
|---|---|
| added | `scripts/e2e/php/marketing-command-center-test.php` |
| added | `wp-content/plugins/lnkup-marketplace/admin/class-marketing-admin.php` |
| added | `wp-content/plugins/lnkup-marketplace/includes/marketing/class-search-console.php` |
| modified | `wp-content/plugins/lnkup-marketplace/lnkup-marketplace.php` |
