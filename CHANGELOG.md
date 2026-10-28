# Changelog

## [1.0.4] — 2026-10-27

### Fixed
- The plugin package no longer includes developer and internal files that were never meant to ship.

1.0.3] — 2026-09-19

### Fixed
- The grey example in the Ingredient, SG and pH boxes (entry and task forms)
  read like a typed value — "Fermaid-O" looked filled in and the save answered
  "An addition needs an ingredient." Every example now reads "e.g. …".

### Changed
- Compatibility: tested against WordPress 7.1.

## [1.0.2] — 2026-09-19

### Added
- `BFBT_Log::update( $log_id, $data )`: rewrite an entry in place through the
  same validation as a new one. The type and the task an entry completes never
  change; a field left empty is cleared; photos are untouched; the entry is
  marked `_bfbt_edited` / `_bfbt_edited_by` and `bfbt_log_updated` fires.
  Nothing in this plugin calls it — entries stay immutable here — it is the
  seam Pro's Edit screen uses.
- `bfbt_log_row_controls` filter: controls at the start of a timeline row's
  last cell, before Trash. The timeline shows an *edited* mark, with the time
  of the edit as its tooltip, on an entry that was rewritten.

### Changed
- Compatibility: tested against WordPress 7.1.

## [1.0.1] — 2026-09-19

### Changed
- Compatibility: tested against WordPress 7.1.

### Added
- Temperatures follow the display-unit setting and carry their own unit. The
  entry form's temperature field has a °C / °F select that opens on the
  setting's unit (US → °F, metric → °C); the unit picked on the entry is the
  one that counts, as it already is for quantities. A reading is checked in
  the unit it was typed in — −10…60 °C or 14…140 °F — and refused in that
  unit: "“200” is outside the temperature range this plugin accepts (14 to
  140 °F)". A °C entry above 60 that would be an ordinary °F reading is
  refused with the hint "If you meant Fahrenheit, pick °F next to the field or
  set your units to US in Settings." Nothing is ever converted for you.
- The stored value stays °C in `_bfbt_temp_c`; the text as typed is kept in
  `_bfbt_temp_entered` ("74 °F"). Titles and timeline rows show the entered
  value and, when the setting's unit differs, the converted value in brackets:
  "74 °F (23.3 °C)". The CSV export gains a `temp_entered` column after
  `temp_c`.
- `BFBT_Units::temperature_unit()`, `volume_unit()`, `mass_unit()` (the unit
  each form offers first, from the setting), `to_celsius()`, `from_celsius()`,
  `format_temperature()`, `display_temperature()`; `BFBT_Validate::temperature(
  $value, $unit, $allow_empty )`, with `temperature_c()` kept as its °C case.
  `BFBT_Log::create()` takes `temp` + `temp_unit` and still accepts `temp_c`.
- Every unit select in the admin — batch volume, vessel capacity, quantity,
  racking volume, temperature — opens on the setting's unit instead of a fixed
  one. The Settings radios read "US (gallons, ounces, °F)" and "Metric (litres,
  grams, °C)".
- Photos on an entry. The entry form takes up to five images (`photos[]`,
  image types only); each becomes a media-library attachment whose parent is
  the entry, shown as a thumbnail in a new Photos column of the timeline and
  named by URL in a new last column of the CSV entries section, `photos`. A
  file that is not an image is refused by name while the entry and the other
  photos are kept ("Entry added, but 1 photo was not saved. “notes.txt”: …");
  more than five refuses the whole entry before anything is written. Deleting
  an entry permanently deletes its photos, row and file; trashing keeps them;
  uninstall keeps them. `BFBT_Photos::split()`, `too_many()`, `attach()`,
  `for_log()`, `urls()` are the seam the Pro front-end form uses.

### Fixed
- A refused save no longer sends you back to an empty form. Every admin form —
  add and edit batch, add entry, add task, milestones — comes back filled with
  what was typed, the refused value included so you can see what was wrong,
  and the entry form reopens on the type that was submitted. The copy lives in
  a per-user transient, `bfbt_form_{user_id}`, for thirty seconds beside the
  notice and is deleted the first time the form reads it; uninstall sweeps it
  with the notice transients. The option set is unchanged.
  `BFBT_Batch_Admin::remember_input()` and `old()` are public for add-ons that
  print their own fields.

## [1.0.0] — 2026-09-18

### Changed
- Compatibility: tested against WordPress 7.1.

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
