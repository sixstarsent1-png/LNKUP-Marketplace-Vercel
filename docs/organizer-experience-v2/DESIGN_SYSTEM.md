# LNKup OS design system (shared by vendor and organizer)

## Layers

| File | Scope | Contents |
| --- | --- | --- |
| `public/css/lnkup-os.css` | `body.lnkup-os` | Tokens, shell (top bar, grouped sidebar, collapse), primitives (buttons, focus, cards, page header, section head), metrics, status, progress, empty, layout grid, tabs, data table, drawer, dialog, EventIdentity, capacity bar, menu |
| `public/css/vendor-os.css` | `body.lnkup-vos` | Vendor screens only |
| `public/css/organizer-os.css` | `body.lnkup-oos` | Organizer screens only |
| `public/js/lnkup-os.js` | both | Nav groups, sidebar collapse, unread count, `[data-vos-tabs]`, drawer/dialog controller (`window.LNKupOS`), menus, table selection |
| `public/js/organizer-os.js` | organizer | Events view toggle, New menu, duplicate dialog, inventory drawer, portfolio filters |

Body classes: vendor surfaces get `lnkup-os lnkup-vos`; organizer surfaces get `lnkup-os lnkup-oos`. `LNKup_Vendor_UI` is now a thin subclass of `LNKup_OS_UI` so existing calls keep working.

## Tokens

Token names keep the `--vos-` prefix so vendor CSS needs no rewrite.

| Token | Value | Use |
| --- | --- | --- |
| `--vos-ink` | `#141414` | Primary text, primary dark fills |
| `--vos-ink-2` | `#3f3f46` | Secondary text |
| `--vos-muted` | `#62626b` | Meta text (4.9:1 on white) |
| `--vos-canvas` | `#f6f5f1` | Page background |
| `--vos-surface` | `#ffffff` | Cards, tables |
| `--vos-line` / `--vos-line-strong` | `#e7e5df` / `#d6d3cb` | Borders |
| `--vos-lime` | `oklch(0.88 0.19 128)` | Primary action fill (text is `--vos-lime-ink`) |
| `--vos-success` / `-bg` | `#157347` / `#e8f5ee` | Confirmed, paid |
| `--vos-warning` / `-bg` | `#9a5b00` / `#fff4e0` | Held, due, needs action |
| `--vos-danger` / `-bg` | `#b42318` / `#fdecea` | Declined, failed, blocked |
| `--vos-info` / `-bg` | `#3b4a8a` / `#eef0fa` | Neutral state |
| Spacing | 4, 8, 12, 16, 24, 32, 48 | `--vos-space-1…7` |
| Type | 30 / 20 / 17 / 15 / 13 px | page / section / card / body / meta |
| Radius | 14 / 10 px | cards / controls |

## Components (`LNKup_OS_UI`)

| Method | Output |
| --- | --- |
| `page_header()` | Eyebrow, title, subtitle, actions |
| `metric()` / `metrics()` | Metric tile; links to its filtered destination when `href` is set |
| `section_head()` | Section title + action link |
| `status( $label, $tone )` | Pill with glyph (✓ ! × •) so color is never the only signal |
| `progress()` | Accessible progress bar |
| `empty_state()` | Dashed empty card with optional CTA |
| `event_identity( $summary, $args )` | Thumbnail, canonical title, date · venue · place, publication + chronology status |
| `capacity_bar( $capacity )` | Confirmed / held / open segments + text legend with counts (pattern fills, not color alone) |
| `data_table( $columns, $rows, $args )` | Sticky header, optional sort links, optional row selection + bulk bar, pagination, `data-label` cells for the card fallback under 768px |
| `drawer_shell( $id, $title, $body )` | Right-side drawer markup driven by `LNKupOS.drawer` |
| `dialog_shell( $id, $title, $body )` | Viewport-centered dialog markup driven by `LNKupOS.dialog` |
| `menu( $label, $items )` | Disclosure menu (New menu, row overflow) |

## Interaction rules

- Drawers and dialogs: focus moves to the first field, Tab is trapped, Escape closes, focus returns to the trigger, body scroll is locked.
- Every async panel has loading, empty and error states; skeletons are replaced on error with a friendly card and a retry.
- Tables collapse to labeled cards under 768px; no horizontal page overflow at any width.
- Focus ring: `0 0 0 2px surface, 0 0 0 4px ink` on every interactive element.
- Status uses glyph + text + color.
- Minimum target 40px (34px for `--sm` buttons in dense tables).

## Navigation (organizer)

| Group | Items |
| --- | --- |
| Workspace | Home, Events, Tours & Series |
| Vendor Management | Vendors, Applications, Invitations, Vendor Lists |
| Business | Money, Operations, Marketing, Intelligence |
| Organization | Team & Permissions, Profile, Settings |
| Footer | Help, Notifications |

Get started leaves the nav and becomes a compact Home module. All existing `view=` values still resolve. The top bar shows organization identity, a short breadcrumb (event names truncated to 32 characters with the full title in `title`), the New / Create Event button, and the notifications bell.
