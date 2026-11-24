# Vendor route map

All of these URLs stay valid.

| Screen | URL | Renderer |
|---|---|---|
| Home | `/vendor-dashboard/` | `LNKup_Vendor_Dashboard_Views::render_home()` |
| Explore | `/opportunities/` | `LNKup_Shortcodes::render_opportunities_feed()` |
| Saved | `/favorites/` | favorites shortcode |
| Invitations | `/vendor-dashboard/?view=passport&tab=invitations` | passport tab |
| Bookings | `/vendor-dashboard/?view=applications` | `render_applications()` |
| Event operations | `/vendor-dashboard/?view=operations&app={id}&tab=` | `render_operations()` |
| Wallet | `/vendor-dashboard/?view=payments` | `render_payments_dashboard()` |
| Wallet alias | `?view=financial` | same |
| Wallet tab | `?view=payments&fin_tab=overview\|invoices\|payment-methods\|receipts\|reports\|wallet\|referrals\|refunds\|disputes\|tax` | `financial.js` |
| Receipt | `GET lnkup/v1/financial/receipts/{id}/view` signed | `LNKup_Receipts::render_html()` |
| Receipt PDF | `GET lnkup/v1/financial/receipts/{id}/pdf` signed | `LNKup_Receipts::render_pdf()` |
| Passport | `?view=passport` | `passport.js` |
| Documents | `?view=documents` | documents manager |
| Growth | `?view=growth` | `growth.js` |
| Reviews | `?view=reviews` | reputation views |
| Referrals | `?view=referrals` | organizer recruit hub |
| Help | `?view=help` | knowledge base |
| Profile editor | `/vendor-profile/` | profile form |

Next.js mirrors these under `/vendor/*` via the BFF. Expo calls `wp-json/lnkup/v1` directly. Application list field `event_name` is the canonical title. `place` is `City, ST`.
