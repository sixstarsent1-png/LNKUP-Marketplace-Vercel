# LNKup Phase 2 — QA Checklist

Use after uploading theme + plugin changes to WordPress.

## Mock app regression

```bash
cd lnk-up-marketplace-platform/lnk-up-marketplace-ui
npx next build
```

Optional: `pnpm dev` and spot-check role picker + vendor find.

## Vendor nav

Log in as `lnkup_vendor`.

- [ ] Sidebar primary: Home, Find Events, Requests, Wallet, Profile (5 items)
- [ ] Sidebar **More** section: Documents, Event Operations, Growth, Reviews, Favorites
- [ ] Mobile bottom: Home, Find, Requests, Wallet, More (5 slots)
- [ ] `/vendor-dashboard/?view=documents` still loads
- [ ] `/favorites/` still loads from More
- [ ] Prelaunch lock on Find Events if marketplace closed

## Organizer nav

Log in as `lnkup_organizer`.

- [ ] Sidebar primary: Home, Events, Vendors, Money (4) + More section
- [ ] Mobile bottom: Home, Events, Vendors, Money, More
- [ ] `#applications` highlights Vendors (primary) and Vendor requests (secondary)
- [ ] `#create`, `#intelligence`, `#lists` reachable from More

## Find Events (`/opportunities/`)

- [ ] Hero: **Find Events** / **Apply for free. Pay only when accepted.**
- [ ] Search shows skeleton cards, then results
- [ ] Zero-result search shows empty state (not blank grid)
- [ ] Network error shows retry block; Retry works
- [ ] Apply + save still work
- [ ] Explore More rails hidden when filtering or when empty

## Organizer command center

- [ ] Overview loads; vendor preview shows skeleton then content or error+retry

## Role gates

- [ ] Vendor cannot open organizer dashboard
- [ ] Organizer cannot open vendor-only views
- [ ] `manage_options` bypass still works

## Deploy

- Upload `wp-content/themes/lnkup-theme/` (especially `inc/dashboard.php`, `assets/js/dashboard.js`, `assets/dist/components.css`)
- Upload plugin changes: `class-shortcodes.php`, `class-ui-copy.php`, `marketplace-v2.js`, `organizer-command.js`
