# Brew Tracker: free and Pro

The source of truth for every comparison table on the storefront, in the
plugins, and in the guides. Change the code, change this file, then rebuild
everything downstream. This file is byte-identical in both repositories.

| | Free | Pro |
|---|---|---|
| Batch record: name, style, vessel, start date, volume, target OG/FG, notes | yes | yes, inherited |
| Style taxonomy seeded with Mead, Wine, Cider, Beer and common substyles | yes | yes, inherited |
| Vessel taxonomy with type and capacity | yes | yes, inherited |
| Timeline log per batch: additions, gravity, temperature, pH, notes, racking | yes | yes, inherited |
| ABV and attenuation from the pinned OG and the latest reading, both formulas stated | yes | yes, inherited |
| Gravity chart per batch, inline SVG | yes | yes, inherited |
| Six-stage cycle: planned, primary, secondary, packaged, complete, dumped | yes | extended |
| Manual tasks with due dates, a Next due column and a Due panel | yes | yes, inherited |
| Paged, sortable batch list with a Trash view and Restore | yes | yes, inherited |
| CSV export of batches and entries | yes | yes, inherited |
| Batches kept on uninstall | yes | yes, inherited |
| **Recipes: ingredient bill and schedule template** | — | **yes** |
| **Seeded recipe library** at 1, 3 or 5 US gallons | — | **yes** |
| **Start a batch from a recipe** — bill scaled, additions planned, tasks generated | — | **yes** |
| **Bottle count and priming sugar** from batch volume and bottle size | — | **yes** |
| **Staggered nutrient preset** and gravity-check recurrence | — | **yes** |
| **Gravity-conditioned tasks** ("rack when SG ≤ 1.000") | — | **yes** |
| **Daily digest email** | — | **yes** |
| **Dashboard widget, admin calendar, .ics feed** | — | **yes** |
| **Extended stages**: cold crash, stabilised, back-sweetened, conditioning, carbonating | — | **yes** |
| **Vessel board** | — | **yes** |
| **Brewer role and front-end shortcodes**: board, tap-to-log, calendar | — | **yes** |
| **Reports**: batches per style, average ABV and attenuation, days per stage, vessel utilisation | — | **yes** |
| **Clone / re-brew a batch** | — | **yes** |
| **Ingredient library** with default units | — | **yes** |

## How the two fit together

One batch record, owned by the free plugin. Pro registers its fields through
`BFBT_Batch::register_field()` on `bfbt_loaded` and answers the
`bfbt_batch_stage` and `bfbt_stage_order` filters; it does not register the
batch, log or task post types, the style or vessel taxonomies, or any of their
meta keys. There is exactly one definition of where a batch's data lives.

Pro declares `Requires Plugins: bluefairy-brew-tracker` and boots on
`bfbt_loaded`, which the base fires before registering anything of its own.
Pro compares `bfbt_plugin()` with its `MIN_BASE` and does nothing but show a
notice when the base is too old.

Pro registers only what is its own: the `bfbt_recipe` post type and the
`bfbt_ingredient` taxonomy.

## Names

One prefix family for both halves, chosen fresh: `BFBT_` / `bfbt_` in free,
`BFBTP_` / `bfbtp_` in Pro, `bfbt` for CSS in both. Nothing here is inherited
from an older data model.

`bfbt_` is a prefix of `bfbtp_`. Neither uninstall may sweep options with a
`LIKE` pattern: `option_name LIKE 'bfbt_%'` matches Pro's settings too, and `_`
is itself a LIKE wildcard. Every option is deleted by its literal name.

## Things that must never be claimed

- **That the free plugin sends email.** It does not. No cron, no `wp_mail`,
  no scheduled work. The daily digest is Pro.
- **That the free plugin calculates a schedule.** It derives ABV, attenuation
  and stage from what was logged, and nothing else. Every task in free is one
  the maker typed; expanding a recipe into tasks is Pro.
- **"Unlimited batches."** The list is paged at twenty-five in the database.
  Advertising the absence of a limit invites the reader to imagine one.
- **That anything of the maker's is removed on uninstall.** Free's uninstall
  deletes `bfbt_features`, `bfbt_units`, `bfbt_seeded`, its notice
  transients and the `bfbt_style_children` hierarchy cache core keeps. Pro's uninstall deletes recipes and the ingredient taxonomy —
  its own — and nothing of free's. Batches, entries, tasks, styles and vessels
  stay in both cases.
- **Per-batch assignment or ownership.** Version 1 is one shared logbook: any
  Brewer can log to any batch. Deliberate scope, stated in the FAQ.
- **Brix or Plato.** Gravity is SG only. A value that looks like Brix is
  refused with a hint.

## Pricing

Free plugin: $0, wordpress.org.
Pro: $79 once, lifetime licence, one site. Requires the free plugin.
