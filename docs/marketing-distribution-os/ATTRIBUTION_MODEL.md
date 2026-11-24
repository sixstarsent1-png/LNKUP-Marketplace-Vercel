# Attribution model

Measurement only. Referral and City Connector commissions keep their own validated codes, cookies and `LNKup_Attribution_Arbiter`; nothing here grants credit or money.

## Touches

A visit is a **campaign touch** when the URL carries any of: `utm_source`, `utm_medium`, `utm_campaign`, `utm_content`, `utm_term`, `utm_id`, `campaign_id`, `adset_id`, `ad_id`, `placement`, `fbclid`, `gclid`, `ttclid`, `ref`, `org_ref`, `cc`, `partner`, `lnk`, or comes from an external referrer (payment and sign-in providers are ignored). A visit that only has a market or device is not a campaign touch, so internal navigation never overwrites the last touch.

Each touch also stores `landing` (path), `market` (from `/markets/{slug}/` or the link), `device` (`mobile`, `tablet`, `desktop`, `app`), referrer host and timestamp. Values are sanitized per type; URL-shaped labels are dropped.

| Cookie | Rule | Lifetime |
|---|---|---|
| `lnkup_ft` | First touch, written once | 90 days |
| `lnkup_lt` | Last campaign touch; replaced only by another campaign touch | 90 days |

Written by `lnkup-attribution.js` (works on cached pages) and by the server on uncached requests. The browser script writes nothing for visitors who opted out (`lnkup_tracking_optout`) or send Global Privacy Control.

## Sources of touches

| Source | How the touch is created |
|---|---|
| Web visit | Cookies above |
| Tracked link `/l/{code}` | `utm_source` = channel (or `lnkup`), `utm_medium` = `tracked_link`, `utm_campaign` = campaign or code, `lnk` = code, plus the owner's referral code (`ref` / `org_ref`) when forwarding is on |
| App link | `+native-intent` saves campaign parameters from the opening URL; `/l/` links resolved by `GET /public/resolve` return the same touch as a web click. Stored in SecureStore for 30 days, sent with signup as `attribution` (`device: app`), then cleared |
| App signup screen | `ref`, `org_ref`, `cc` route parameters override the stored touch |

## Conversions

At registration the cookies (or app payload) are copied to user meta (`_lnkup_acq_first_touch`, `_lnkup_acq_last_touch`). Every conversion row (`CompleteRegistration`, `SubmitApplication`, `InitiateCheckout`, `Purchase`, `Lead`, ...) snapshots `{ft, lt}` in `attribution_json`. Click IDs are reduced to presence flags in snapshots (`"gclid": 1`, never the ID); link codes and markets are kept. Acquisition user meta is deleted 24 months after registration.

Tracked links are credited from conversion rows: a signup, application or booking whose first **or** last touch carries the link code increments that link's daily `signups` / `applications` / `bookings`.

## Revenue attribution

`LNKup_Revenue_Attribution::report( from, to, dimension, model )`:

- Dimensions: `utm_source`, `utm_medium`, `utm_campaign`, `lnk`, `market`, `ref`, `partner`, `device`. Models: last touch (default) or first touch.
- One row per payment from `lnkup_platform_fees` (duplicate fee rows for the same payment count once). The touch comes from the first non-test `Purchase` conversion for that payment.
- Columns: bookings, GMV (gross booth amount), refunds (approved/completed), platform fee gross, platform fee net of refunded fee, partner commissions (not void/reversed), net revenue = net platform fee − commissions, and ad spend when matched.
- Ad spend comes from Organizer Growth's manual spend entries (`lnkup_org_growth_spend`) by channel or campaign, so ROAS appears only in the `utm_source` and `utm_campaign` views.
- Payments without a touch are reported as **Unattributed**; nothing is guessed.
- A $500 booth payment is $500 of GMV; only the platform-fee share is LNKup revenue.

Visible in Command Center → Overview to users with financial access only, with CSV export.
