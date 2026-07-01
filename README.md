# LNKup Marketplace Platform

Monorepo containing the LNKup vendor opportunity marketplace: a **Next.js reference UI** and a **WordPress theme + plugin** implementation.

For the architecture reference, see [docs/SYSTEM_OVERVIEW.md](docs/SYSTEM_OVERVIEW.md). For a lighter feature inventory, see [docs/CURRENT_STATE.md](docs/CURRENT_STATE.md).

## Structure

```
lnk-up-marketplace-platform/
├── lnk-up-marketplace-ui/        # Canonical runnable mock app (Phase 1)
├── app/                          # Legacy Next.js reference (older v0 template)
├── components/                   # Legacy React components
├── wp-content/
│   ├── themes/lnkup-theme/       # WordPress theme (production)
│   └── plugins/lnkup-marketplace/ # Marketplace plugin (production)
```

## WordPress Setup

1. Install WordPress locally (Local WP, Docker, or your host).
2. Link or copy this repo's `wp-content/themes/lnkup-theme` and `wp-content/plugins/lnkup-marketplace` into your WordPress `wp-content/` directory.
3. In **wp-admin → Plugins**, activate **LNKup Marketplace**.
4. In **Appearance → Themes**, activate **LNKup Theme**.
5. The plugin auto-creates pages: Vendor Dashboard, Organizer Dashboard, Find Events, Vendor Profile.
6. Set **Settings → Reading** to use a static front page (plugin creates a Home page if needed).

### Theme CSS Build

```bash
cd wp-content/themes/lnkup-theme
npm install
npm run build
```

### User Roles

On registration, users choose **Vendor** or **Organizer**. Custom roles:

- `lnkup_vendor` — browse opportunities, apply, manage profile/documents/payments
- `lnkup_organizer` — create events, opportunities, review applications
- `lnkup_admin` — full platform management

Administrators receive all LNKup capabilities.

### Shortcodes

| Shortcode | Purpose |
|-----------|---------|
| `[lnkup_vendor_dashboard]` | Vendor overview |
| `[lnkup_organizer_dashboard]` | Organizer overview |
| `[lnkup_opportunities_feed]` | Public opportunities feed |
| `[lnkup_vendor_profile_form]` | Vendor business profile |
| `[lnkup_organizer_events]` | Event list + opportunity creation |
| `[lnkup_applications_manager]` | Application review |
| `[lnkup_documents_manager]` | Document uploads |
| `[lnkup_payments_dashboard]` | Payment history |

### Payments

Configure Stripe keys under **LNKup → Settings**. When test mode is enabled or Stripe is not configured, payments use a simulation flow for local development.

Stripe webhook endpoint: `POST /wp-json/lnkup/v1/stripe-webhook`

### Sample Data

On first activation, the plugin seeds five sample events (Dirty South Rodeo Charlotte, Soul District Block Party, etc.) with open vendor opportunities.

## Next.js Reference UI (canonical)

**`lnk-up-marketplace-ui/`** is the active mock marketplace app — role picker entry, 5-item nav per role, 10 core screens, mock data only.

```bash
cd lnk-up-marketplace-ui
pnpm install   # if pnpm blocks build scripts, run: pnpm approve-builds
pnpm dev       # or: npx next build
```

Open http://localhost:3000 and pick Vendor, Organizer, or Admin.

**QA panel states:** append `?empty=1` or `?error=1` to core dashboard routes.

The root `app/` folder is a legacy v0 template (marketing landing + older dashboards). Use it only for historical comparison; new UI work belongs in `lnk-up-marketplace-ui/`.

## MVP Scope

Vendor-only marketplace layer — not attendee ticketing. Ticketing stays on Posh, Eventbrite, Universe, etc.
