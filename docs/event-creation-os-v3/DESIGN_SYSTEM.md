# Creation design system

Built on Organizer Experience OS 2.0 (`LNKup_OS_UI`, `lnkup-os.css` tokens `--vos-*`, `lnkup-os.js`). New creation components use the `eb-` prefix in `public/css/event-builder.css`; native components live in `lnk-up-marketplace-mobile/components/event-builder/`.

## Tokens (reused)

Ink `--vos-ink`, canvas `--vos-canvas`, surfaces `--vos-surface/-2`, lines `--vos-line/-strong`, lime accent `--vos-lime` / `--vos-lime-soft` / `--vos-lime-ink`, status `--vos-success|warning|danger|info` (+ `-bg`), spacing `--vos-space-1..7`, radius `--vos-radius` 14px / `-sm` 10px, focus ring `--vos-focus`. Mobile: `colors.lime #B3EE55`, `spacing`, `radii`, `typeScale` from `lib/theme.ts`.

## Layout

| Region | Desktop (≥1200px) | Tablet (768–1199) | Mobile (<768) |
|---|---|---|---|
| Header | Exit, title, autosave pill, Preview, Save & exit | same | compact: back, title, pill |
| Steps | Horizontal stepper with numbers + state | horizontal, scrollable | "Step 2 of 5" + progress bar |
| Workspace | Max 760px form column | full width | full width, single column |
| Preview rail | 360px sticky marketplace card + summary | Preview button → sheet | Preview button → sheet |
| Action bar | Sticky bottom: Back · Save draft · Continue/Publish | same | sticky, primary full width |

## Components

| Component | Class / file | Behavior |
|---|---|---|
| Method card | `.eb-method` | Icon, title, one-line description, optional "Recommended" chip; keyboard focusable link |
| Stepper | `.eb-steps`, `[aria-current=step]` | Each step shows done / current / needs attention |
| Autosave pill | `.eb-save` `[data-state=saving|saved|dirty|error]` | "Saved" only after a 200 with the new revision; error shows Retry |
| Field | `.vos-field` + `.eb-help` | Label, help one-liner, inline error after blur |
| Option card | `.eb-option` | Category icon, name, price, spots, completeness, Edit / Duplicate / Remove; collapsed when complete |
| Advanced disclosure | `<details class="eb-adv">` | Dimensions, amenities, documents, deadline, questions |
| Bulk table | `.vos-table.eb-grid` | Inline inputs for name/category/price/spots/deadline |
| Inventory summary | `.eb-summary` | Booth types, total spaces, free/paid, **maximum** base fees (labeled as a maximum, not a forecast) |
| Question builder | `.eb-questions` | Type, label, required, options; Move up/down buttons (accessible alternative to drag) |
| Fee breakdown | `.eb-fees` | Base fee, LNKup fee, processing, tax, vendor total, organizer proceeds — from `LNKup_Fee_Engine` |
| Checklist | `.eb-check` | Required / recommended / optional, each with "Fix" link to its step |
| Marketplace card preview | `.eb-card` | Image (contain for portrait flyers), title, date, place, organizer, starting price, availability |
| Success screen | `.eb-success` | Public link, copy, share, QR, Find vendors, Invite, Dashboard, Packet, Operations |

## Copy rules

- Help text one sentence, plain words ("Vendor capacity is the number of businesses that can reserve this booth option.").
- Never "100% complete" unless every required check passes; creation progress, publication readiness and operational readiness are shown separately.
- Money values always from the backend; estimates labeled "maximum" or "estimate".
- External links: "Official event listing" — never "Buy tickets on LNKup".
