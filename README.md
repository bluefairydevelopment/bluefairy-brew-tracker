# BlueFairy Brew Tracker

A batch log for mead, wine, cider and beer: style, vessel, start date, gravity
readings, additions, stage, and what is due next. This is the free base plugin;
[Brew Tracker Pro](https://wordpress.bluefairydevelopment.com/product/bluefairy-brew-tracker-pro/)
is an add-on that extends it and holds no copy of its work.

## Requirements

- WordPress 6.5 or later
- PHP 8.1 or later
- No other plugin. WooCommerce is not needed.

## Install

1. Upload the `bluefairy-brew-tracker` folder to `wp-content/plugins/`, or
   install the zip through **Plugins → Add New → Upload Plugin**.
2. Activate it. Activation seeds the style tree (24 terms) once.
3. **Brew Tracker** appears in the admin menu with **Batches** and **Settings**
   beneath it.

From the command line:

```
wp plugin install bluefairy-brew-tracker-1.0.0.zip --activate
```

## Architecture

```
bluefairy-brew-tracker.php          header, constants, requires, bfbt_plugin() marker
uninstall.php                       deletes the option set by literal name; no LIKE sweep
includes/class-plugin.php           boot() on plugins_loaded, activate()
includes/class-batch.php            the batch record: post types, taxonomies, CORE_FIELDS,
                                    register_field(), create(), update()
includes/class-log.php              immutable timeline entries: create(), trash(), OG pin,
                                    latest gravity, chart series
includes/class-task.php             tasks: create(), complete() (idempotent), uncomplete(),
                                    open_for_batch(), next_due_for()
includes/class-stage.php            the derived stage, its order, labels, colours, badge
includes/class-abv.php              ABV (two formulas) and attenuation
includes/class-chart.php            inline SVG of gravity over time
includes/class-csv.php              streamed, quoted, formula-defused export
includes/class-validate.php         date, datetime, gravity, quantity, unit, temperature, pH, volume
includes/class-units.php            litres/grams canonical, US or metric display
includes/class-seeder.php           the style tree
includes/class-feature-registry.php the feature switches Pro adds its own to
includes/class-batch-cascade.php    trash / restore / delete children with the batch
admin/class-batch-admin.php         menu, router, every admin_post_bfbt_* handler, notices
admin/class-batch-list-table.php    the paged list
admin/class-batch-view.php          one batch: header, chart, due panel, forms, timeline, milestones
admin/class-settings.php            display units, CSV export
admin/class-pro-upsell.php          the panel and action link shown when Pro is absent
assets/css/bfbt-admin.css           loaded on the plugin's screens only
assets/js/bfbt-admin.js             shows the fieldset for the chosen entry / task type
```

`BFBT_Batch` is the only place that knows where a batch's data lives. Pro calls
`register_field()` on `bfbt_loaded` and answers `bfbt_batch_stage`; it does not
register the post types, the taxonomies, or any meta key of its own.

## Configuration reference

### Settings → Display units

| Option | Values | Default | Governs |
|---|---|---|---|
| `bfbt_units` | `us`, `metric` | `us` | how a *derived* volume is shown (a batch size: "1.00 gal" or "3.79 l"), the unit each form's select opens on (`us`: gal, oz, °F; `metric`: l, g, °C), and the unit a stored temperature is converted to beside its entered value. A logged quantity or temperature is always shown in the unit that was typed. |

The radios read "US (gallons, ounces, °F)" and "Metric (litres, grams, °C)".
Anything else stored under `bfbt_units` reads as `us`. The value can be
overridden at read time through the `bfbt_units` filter; `BFBT_Units::
temperature_unit()`, `volume_unit()` and `mass_unit()` answer from the
filtered value.

### Other options

| Option | Written by | Purpose |
|---|---|---|
| `bfbt_seeded` | activation | `1` once the style tree has been seeded |
| `bfbt_features` | nothing in the free plugin | the feature switches Pro declares through `bfbt_feature_labels`; an unknown key reads as on |
| `bfbt_style_children` | WordPress core | the cached parent/child map core keeps for every hierarchical taxonomy |

All four are deleted on uninstall, by literal name; core rebuilds the last
one when the taxonomy is next registered and read. Two per-user transients,
both thirty seconds and both swept on uninstall: `bfbt_notice_{user_id}`, the
one-shot admin notice, and `bfbt_form_{user_id}`, a refused form's input,
written by `BFBT_Batch_Admin::remember_input()` and read back — once — by
`old()` so the form comes back filled.

### Capabilities

| Capability | Lets a user |
|---|---|
| `edit_posts` | see the screen; add and edit batches; add entries; add, complete and reopen tasks; pin the OG |
| `delete_others_posts` | trash, restore and permanently delete a batch; trash an entry |
| `manage_options` | change settings; export the CSV |

The constants are `BFBT_Batch_Admin::CAP_RECORD`, `CAP_DESTROY` and
`CAP_MANAGE`. Every handler calls `guard()` before it verifies its nonce.

### Handlers

Every write is a form post (or a nonced link) to `admin-post.php`.

| Action | Nonce | Capability |
|---|---|---|
| `bfbt_save_batch` | `bfbt_add_batch` or `bfbt_edit_batch_{id}` | `edit_posts` |
| `bfbt_add_log` (multipart; up to five images as `photos[]`) | `bfbt_add_log_{batch}` | `edit_posts` |
| `bfbt_save_task` | `bfbt_add_task_{batch}` | `edit_posts` |
| `bfbt_complete_task`, `bfbt_uncomplete_task` | `bfbt_complete_task_{id}` | `edit_posts` |
| `bfbt_pin_og` | `bfbt_pin_og_{batch}` | `edit_posts` |
| `bfbt_trash_batch`, `bfbt_restore_batch`, `bfbt_delete_batch` | `bfbt_trash_batch_{id}`, `bfbt_restore_batch_{id}`, `bfbt_delete_batch_{id}` | `delete_others_posts` |
| `bfbt_trash_log` | `bfbt_trash_log_{id}` | `delete_others_posts` |
| `bfbt_save_settings` | `bfbt_settings` | `manage_options` |
| `bfbt_export_csv` | `bfbt_export` | `manage_options` |

### Post types and taxonomies

`bfbt_batch`, `bfbt_log` (parent: batch), `bfbt_task` (parent: batch);
`bfbt_style` (hierarchical) and `bfbt_vessel` on batches. None is public, shown
in the UI, or exposed to REST. All use `capability_type => 'post'` with
`map_meta_cap`. An entry's photos are core `attachment` posts whose parent is
the entry; `BFBT_Photos` deletes them on `before_delete_post` of the entry.

## Extension reference

The seams the Pro add-on uses. Each exists in the code with exactly this
signature.

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `bfbt_loaded` | — | on `plugins_loaded`, after the batch record and the cascade are wired and before the admin loads |
| `bfbt_batch_created` | `int $batch_id, array $clean` | after `BFBT_Batch::create()` has written everything |
| `bfbt_batch_updated` | `int $batch_id, array $clean` | after `BFBT_Batch::update()` |
| `bfbt_log_created` | `int $log_id, int $batch_id, array $clean` | after `BFBT_Log::create()` |
| `bfbt_log_updated` | `int $log_id, int $batch_id, array $clean` | after `BFBT_Log::update()` (an add-on's edit; this plugin never calls it) |
| `bfbt_task_completed` | `int $task_id, int $batch_id, int $log_id` | once, on the open → done transition |
| `bfbt_admin_menu` | `string $parent_slug` | after the top-level menu is registered (`bfbt-batches`) |
| `bfbt_batch_form_fields` | `int $batch_id` (0 when adding) | inside the add/edit form, after the core rows |
| `bfbt_batch_actions` | `int $batch_id` | in the batch screen header |
| `bfbt_after_batch_list` | — | below the list, above the Pro panel |
| `bfbt_settings_after` | — | below the free settings |

### Filters

| Hook | Arguments | Returns |
|---|---|---|
| `bfbt_batch_stage` | `string $stage, int $batch_id` | the stage key |
| `bfbt_stage_order` | `string[] $order` | the ordered stage keys |
| `bfbt_terminal_stages` | `string[] $stages` | stages after which nothing is scheduled (default `complete`, `dumped`) |
| `bfbt_stage_labels` | `array<string,string>` | stage → label |
| `bfbt_stage_colours` | `array<string,string>` | stage → `"colour;background:colour"` |
| `bfbt_log_types` | `string[]` | entry types |
| `bfbt_task_types` | `string[]` | task types |
| `bfbt_feature_labels`, `bfbt_feature_descriptions` | `array<string,string>` | feature key → text |
| `bfbt_units` | `string $setting` | `us` or `metric` |
| `bfbt_ingredient_label` | `string $label, string $ingredient` | the display label for an ingredient typed as text |
| `bfbt_batch_form_submit` | `array $fields, int $batch_id` | the fields about to be saved from the batch form |
| `bfbt_log_row_controls` | `string $html, int $log_id, int $batch_id` | escaped HTML placed at the start of a timeline row's last cell, before Trash |

### Public API

```php
BFBT_Batch::register_field( string $name, string $meta_key ): void
BFBT_Batch::create( array $data ): int|WP_Error
BFBT_Batch::update( int $batch_id, array $data ): true|WP_Error
BFBT_Batch::get( int $batch_id, string $field, mixed $fallback = '' ): mixed
BFBT_Batch::set( int $batch_id, string $field, mixed $value ): bool
BFBT_Log::create( int $batch_id, array $data ): int|WP_Error
BFBT_Log::update( int $log_id, array $data ): true|WP_Error   // same keys as create(); type and task_id fixed; marks _bfbt_edited
BFBT_Log::trash( int $log_id ): true|WP_Error
BFBT_Log::pin_as_og( int $batch_id, int $log_id ): true|WP_Error
BFBT_Log::latest_gravity( int $batch_id ): ?array
BFBT_Log::for_batch( int $batch_id, int $page = 1, int $per_page = 50 ): array
BFBT_Photos::split( mixed $files ): array                 // $_FILES['photos'] → one array per file
BFBT_Photos::too_many( array $list ): ?WP_Error            // more than BFBT_Photos::MAX (5)
BFBT_Photos::attach( int $log_id, array $list ): array     // ['ids' => int[], 'errors' => string[]]
BFBT_Photos::for_log( int $log_id ): int[]                 // attachment ids, oldest first
BFBT_Photos::urls( int $log_id ): string[]
BFBT_Task::create( int $batch_id, array $data ): int|WP_Error
BFBT_Task::complete( int $task_id ): int|WP_Error
BFBT_Task::uncomplete( int $task_id ): true|WP_Error
BFBT_Task::open_for_batch( int $batch_id ): WP_Post[]
BFBT_Task::next_due_for( array $batch_ids ): array
BFBT_Stage::get( int $batch_id ): string
BFBT_Stage::order(): string[]
BFBT_ABV::from_gravities( float $og, float $fg ): float
BFBT_ABV::attenuation( float $og, float $fg ): float
BFBT_Units::setting(): string                       // 'us' or 'metric', filtered
BFBT_Units::temperature_unit(): string              // 'f' or 'c' from the setting
BFBT_Units::volume_unit(): string                   // 'gal' or 'l'
BFBT_Units::mass_unit(): string                     // 'oz' or 'g'
BFBT_Units::to_celsius( float $value, string $unit ): float
BFBT_Units::from_celsius( float $celsius, string $unit ): float
BFBT_Units::format_temperature( float $value, string $unit ): string   // "74 °F"
BFBT_Units::display_temperature( float $celsius ): string             // in the setting's unit
BFBT_Validate::temperature( string $value, string $unit, bool $allow_empty = false ): string|WP_Error   // °C
BFBT_Validate::temperature_c( string $value, bool $allow_empty = false ): string|WP_Error              // the °C case
BFBT_Batch_Admin::remember_input( string $form, array $fields ): void   // before redirecting on a refusal
BFBT_Batch_Admin::old( string $form, string $key, string $default = '' ): string   // what the form reads back
bfbt_plugin(): string   // the version; Pro compares it with its MIN_BASE
```

`BFBT_Log::create()` takes a temperature as `temp` + `temp_unit` (`'c'` or
`'f'`; absent, the setting's unit) or as `temp_c` already in °C. It stores
`_bfbt_temp_c` and `_bfbt_temp_entered` ("74 °F"), and the CSV exports both.

## Development

```
composer install
vendor/bin/phpunit                                          # unit tests over the pure classes
wp eval-file wp-content/plugins/bluefairy-brew-tracker/tests/integration-checks.php   # from the site root
```

The E2E suite lives in the release rig at `~/Sites/wp-testkit/suites/brew-tracker/`.

## Licence

GPL-2.0-or-later.
