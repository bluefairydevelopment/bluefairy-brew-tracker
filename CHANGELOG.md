# Changelog

## [1.0.0] — 2026-09-18

First release. The base plugin of the Brew Tracker pair; Brew Tracker Pro
extends this record rather than keeping its own.

### Added

- The batch record: `bfbt_batch` with a seeded `bfbt_style` tree (24 terms) and
  a `bfbt_vessel` taxonomy carrying vessel type and capacity. Fields are
  registered through `BFBT_Batch::CORE_FIELDS`; add-ons contribute theirs with
  `register_field()`.
- Validation that refuses rather than guesses: calendar-checked dates, gravity
  as `1.xxx` in 0.980–1.200, non-negative quantities, a fixed unit list,
  temperature −10…60 °C, pH 0…14, volume above zero. Every refusal quotes the
  value back; a refused save is never reported as saved.
- An immutable timeline per batch (`bfbt_log`): addition, gravity, temperature,
  pH, note, racking and stage entries, dated when they happened. Trash and
  re-enter; there is no edit.
- The first gravity reading is pinned as the original gravity; any reading can
  be pinned instead; trashing the pinned reading clears the pin.
- ABV and apparent attenuation derived at read time from the pinned OG and the
  latest reading, rendered with their provenance. The standard formula up to OG
  1.070 and the high-gravity formula above it, both named on screen.
- An inline SVG gravity chart, no JavaScript library.
- Six derived stages — planned, primary, secondary, packaged, complete, dumped —
  decided from the milestone dates, with a filterable order for add-ons.
- Tasks (`bfbt_task`) with due dates, a Due panel with Done and Undo, and a
  Next due column on the list computed in one grouped query. Completing a task
  logs the addition and is idempotent.
- A paged list (25 a page, in the database) sortable by name and start date,
  filterable by stage and style, with All | Trash views, Restore and Delete
  permanently. Trashing, restoring and deleting a batch cascade to its entries
  and tasks.
- Display units setting (US or metric) governing derived volumes only.
- CSV export of batches and entries, streamed 500 rows at a time, every field
  quoted, formula-leading values defused.
- Capabilities per handler: `edit_posts` to record, `delete_others_posts` to
  destroy, `manage_options` to configure and export.
- An uninstall that deletes `bfbt_features`, `bfbt_units` and `bfbt_seeded` by
  literal name and sweeps the notice transients — never a batch.
- Unit tests over the pure classes, an integration check script, and an E2E
  suite on the release rig.
