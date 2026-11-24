# Implementation plan

Phases in dependency order; each is committed separately with its tests. Feature flags (all off by default) keep the current wizard, REST and mobile `launch.tsx` unchanged until enabled.

| Phase | Scope | Flag |
|---|---|---|
| 0 | Audit, research, provider matrix, design system, before screenshots | — |
| 1 | Builder drafts (private CPT + revision), schema, validator, publisher (via onboarding), REST | `event_builder_v3` |
| 2 | Method chooser, Quick/Advanced create, stepper, autosave, preview rail | `event_builder_v3` |
| 3 | Vendor Opportunities Builder (cards, advanced, bulk table, summary) | `event_builder_v3` |
| 4 | Applications (questions, documents, preview), payments (deposit, deadline, cancellation note), fee preview, Connect handoff | `event_builder_v3` |
| 5 | Preview modes, checklist with jump links, publish options, success + recruit | `event_builder_v3` |
| 6 | `LNKup_Safe_Fetch` (fixes F1 SSRF regardless of flags), registry, normalizer, sources table, dedupe, ownership | `event_import_v3` |
| 7 | Single URL import → review → builder; existing-event association | `event_import_v3` |
| 8 | Multi-URL, CSV, ICS, jobs, Eventbrite OAuth (credential-gated) | `event_import_bulk`, `eventbrite_oauth` |
| 9 | Recurring builder over `LNKup_Portfolio_Recurrence` | `portfolio_builder_v3` |
| 10 | Tour stop table, attach existing events, inheritance + bulk apply, selective publish; series | `portfolio_builder_v3` |
| 11 | Template library, save-as-template, Duplication 2.0 (→ draft) | `event_builder_v3` |
| 12 | Edit 2.0 + material-change review, External Sources, sync + change review | `event_builder_v3`, `event_source_sync` |
| 13 | Native create/import/drafts/preview/publish, basic recurring/tour, templates | server flags |
| 14 | Imports Center, admin page, public official-listing section, attribution | `event_import_v3` |
| 15 | Tests, screenshots, a11y/overflow, performance, deliverables, version bump | — |

Coordination: another session has uncommitted edits in `class-organizer-dashboard-views.php`, `class-shortcodes.php`, `organizer-os.*`. New UI goes in new files; shared files receive single guarded lines staged by hunk.
