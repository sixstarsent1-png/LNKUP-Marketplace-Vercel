# Analytics event taxonomy

`LNKup_Analytics_Taxonomy::map()` maps the specification's event names onto what LNKup already records. There is no second event stream: conversions stay in the conversion registry (`lnkup_conversion_events`, mirrored to `lnkup_analytics_events` as `conv_*`), other events stay in `lnkup_analytics_events`.

| Specification event | Recorded as (`event_type`) | Source status |
|---|---|---|
| `opportunity_viewed` | `view` | mapped |
| `opportunity_shared` | `share_link_copied`, `application_link_copied` | mapped |
| `opportunity_saved` | `conv_addtowishlist` | mapped |
| `vendor_signup_completed` | `conv_vendorregistered` | mapped |
| `vendor_profile_completed` | `conv_vendorprofilecompleted` | mapped |
| `application_started` | `apply_clicked` | partial (client event) |
| `application_submitted` | `conv_submitapplication` | mapped |
| `application_approved` | `conv_applicationapproved` | mapped |
| `booking_confirmed` | `conv_firstvendorbooking`, `conv_repeatvendorbooking` | mapped |
| `vendor_payment_succeeded` | `conv_purchase` (Stripe webhook confirmed) | mapped |
| `organizer_signup_completed` | `conv_organizerregistered` | mapped |
| `organizer_event_created` | `conv_eventcreated` | mapped |
| `organizer_event_published` | `conv_eventpublished` | mapped |
| `organizer_first_booking` | `conv_organizerfirstpaidbooking` | mapped |
| `external_event_interest` | `external_event_interest_submitted` | mapped |
| `external_event_claimed` | `external_event_claim_completed` | mapped |
| `referral_conversion` | referral reward ledger (`lnkup_referral_rewards`, not reversed/void) | ledger |
| `market_alert_created` | `market_alert_created` | new (v1 contract) |
| `market_demand_signal` | `market_demand_signal` (anonymous, no user ID) | new (v1 contract) |
| `market_page_viewed` | `market_page_view`, `market_page_viewed` | mapped |

## Event contract (new events)

`LNKup_Analytics_Taxonomy::emit()` adds `_v` (contract version 1), `event_id` (UUID) and `env` (`wp_get_environment_type()`) to the event data. Anonymous signals are written with `user_id = 0` even when someone is signed in. Event data never contains names, emails, phone numbers or application content.

## Client events

Clients may only send types on the `LNKup_Match_Funnel::CLIENT_EVENTS` allowlist to `POST /marketplace/track/event`. The Expo allowlist now includes `deep_opportunity_opened` (app link opened, metadata `route`, `tracked_link`), `share_link_copied` (native share completed) and `apply_clicked` (public event screen). Server-recorded types (views, conversions, impressions) are never accepted from clients.

## Meta "Lead"

Vendor interest in an external event and a new market alert record the standard `Lead` conversion once per person and entity (it reaches Meta only through the existing consent and allowlist rules).

## Ad platform names

| Registry event | GA4 | TikTok |
|---|---|---|
| CompleteRegistration | `sign_up` | `CompleteRegistration` |
| Lead | `generate_lead` | `SubmitForm` |
| SubmitApplication | `submit_application` | `SubmitForm` |
| InitiateCheckout | `begin_checkout` | `InitiateCheckout` |
| Purchase | `purchase` (server, `transaction_id` = event_id) | `CompletePayment` (browser + Events API, de-duplicated by event_id) |
| OrganizerRegistered | `organizer_sign_up` | — |
| FirstEventPublished | `organizer_first_event_published` | — |
| ApplicationApproved | `application_approved` | — |

## Retention

Daily job `lnkup_analytics_retention` rolls raw events into `lnkup_analytics_daily` (31 days per run, watermark `lnkup_analytics_rollup_until`). Raw rows older than 13 months are purged **only** when `analytics_retention_purge` is enabled. Counts read rolled-up days from the rollup and later days from raw rows, so nothing is double-counted.

## Where to see it

Command Center → Overview → Analytics event taxonomy (counts for the selected window, CSV export).
