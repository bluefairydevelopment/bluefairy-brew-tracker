=== BlueFairy Brew Tracker ===
Contributors: bluefairydevelopment
Donate link: https://ko-fi.com/bluefairydevelopment
Tags: mead, wine, cider, homebrew, beer, fermentation, brewing log
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A batch log for mead, wine, cider and beer. Record what went in and what you measured, see where every batch is, and what is due next.

== Description ==

If you make mead, wine, cider or beer at home you already keep this record somewhere — a notebook, a spreadsheet, an app tied to one vendor's kit. This plugin keeps it in WordPress instead, where you can reach it from the phone in your pocket while you are standing in front of the carboy.

= The batch =

* **Name, style, vessel** — the style tree comes seeded with Mead, Wine, Cider and Beer and their common substyles; vessels build up as you name them, so "Carboy A" stays one vessel however many batches go through it.
* **Started** — the day the yeast went in. Leave it blank for a batch you are planning.
* **Volume** — typed as "1 gal" or "4 l", stored in litres, shown as you entered it.
* **Target OG and FG**, and notes.

= The timeline =

Every entry is dated when it happened: an addition ("Fermaid-O 1.2 g"), a gravity reading ("SG 1.042 @ 20 °C"), a temperature, a pH, a note, a racking with the volume that came across, a stage change. Entries are never edited — a typo is trashed and re-entered — so a number on the screen can always be traced to the reading that produced it.

= ABV, attenuation and the chart =

The first gravity reading is pinned as the original gravity; pin a different one with a click. ABV and apparent attenuation are worked out from the pinned OG and the latest reading and shown with their dates. The standard formula is used up to OG 1.070 and the high-gravity formula above it, and the screen says which. An inline chart draws every reading over time.

= Stage =

Planned, primary, secondary, packaged, complete, dumped — worked out from the milestone dates you record, never stored separately, so a batch cannot disagree with itself.

= What is due =

Type a task with a due date — "Fermaid-O 1.2 g on the 14th" — and it appears in the Due panel on the batch and in the Next due column on the list, red when overdue. Done logs the addition to the timeline; Undo reopens it.

= The list =

Twenty-five batches to a page, fetched a page at a time however many seasons you have logged. Sort by name or start date; filter by stage or style. Trash, restore, delete permanently — a batch's entries and tasks go with it.

= What it does not do =

It does not send email, run anything on a schedule, or work out a schedule for you. It records what you type and derives ABV, attenuation and stage from it. Every reminder is one you added. The add-on below is where recipes and schedules live.

= Where the record lives =

Batches, entries and tasks are ordinary WordPress records, so they are in your database backup, they survive deactivating the plugin, and **they are not deleted when you uninstall it**. If you later add Brew Tracker Pro, every batch you have already logged is simply there — the two plugins share one record.

== Brew Tracker Pro ==

The paid add-on installs alongside this plugin and keeps everything you have logged. It adds:

* Recipes — an ingredient bill and a schedule template — and a seeded library of common meads, wines, ciders and beers at 1, 3 or 5 US gallons
* Start a batch from a recipe: the bill scaled to your volume, every task created with its date
* Staggered nutrient presets, gravity-check recurrence, and tasks that become due when a reading satisfies them ("rack when SG ≤ 1.000")
* A daily digest email, a dashboard widget, an admin calendar and an .ics feed
* Bottle count and priming sugar from the batch volume
* Extended stages, a vessel board, reports, clone and re-brew
* A Brewer role and phone-sized front-end forms

https://wordpress.bluefairydevelopment.com/product/bluefairy-brew-tracker-pro/

== Installation ==

= Automatic =

1. In your WordPress admin go to **Plugins → Add New**.
2. Search for "BlueFairy Brew Tracker" and click **Install Now**, then **Activate**.

= Upload the zip =

1. Download `bluefairy-brew-tracker-1.0.0.zip`.
2. Go to **Plugins → Add New → Upload Plugin**, choose the zip, click **Install Now**, then **Activate**.

= FTP =

1. Unzip the download and upload the `bluefairy-brew-tracker` folder to `/wp-content/plugins/`.
2. Activate it from **Plugins**.

= After activation =

**Brew Tracker** appears in the admin menu. Activation seeds the style tree once. Open **Brew Tracker → Batches** and add your first batch; open **Brew Tracker → Settings** to choose US or metric display units.

== Admin Instructions ==

= Settings =

* **Display units** — US (gallons, pounds) or metric (litres, grams). This governs derived volumes only, such as a batch size. A quantity you logged is always shown in the unit you typed: 1.2 g stays 1.2 g.
* **Export CSV** — every batch and every entry as one file, batches first, then entries keyed by batch id. Administrators only.

= Who can do what =

Anyone who can edit posts can add batches, log entries and complete tasks. Trashing and deleting need the capability to delete others' posts. Settings and export need `manage_options`.

= WP-CLI =

* `wp post list --post_type=bfbt_batch --fields=ID,post_title,post_status` — your batches
* `wp post list --post_type=bfbt_log --post_parent=<id> --fields=ID,post_title,post_date` — a batch's timeline
* `wp post list --post_type=bfbt_task --post_parent=<id> --fields=ID,post_title` — a batch's tasks
* `wp term list bfbt_style --fields=term_id,name,slug,parent` — the style tree
* `wp option get bfbt_units` — the display-unit setting

= Debugging =

The plugin writes nothing to the error log in normal use. With `WP_DEBUG` and `WP_DEBUG_LOG` on, any line mentioning `bluefairy-brew-tracker` in `wp-content/debug.log` is a defect — please report it with the line.

== Frequently Asked Questions ==

= Will I lose my batches if I deactivate or uninstall? =

No. Deactivating changes nothing. Uninstalling removes only this plugin's own settings. Your batches, entries, tasks, styles and vessels are your records; a plugin should not delete them because you removed it.

= Is there a limit on how many batches I can log? =

The list is paged at twenty-five, and each page is its own database query, so the screen stays quick however long the log gets.

= Why was my gravity reading refused? =

Type it as `1.090`. A value like `1090` or `90` is refused with a hint rather than reinterpreted, because it might be Brix or gravity points and a guess would put a reading you did not take on your chart. Brix is not supported in this version.

= Why was my date refused? =

Dates are year-month-day, and they are checked against the calendar: `2026-02-30` has the right shape and is not a day. `03/14/2026` is refused rather than read as the 3rd of February.

= Which ABV formula does it use? =

`(OG − FG) × 131.25` for an original gravity up to 1.070, and `76.08 × (OG − FG) / (1.775 − OG) × (FG / 0.794)` above it, which is what a mead needs. The batch screen names the formula it used and the two readings it used it on.

= Can I edit an entry? =

No. Trash it and add it again. An entry that could be edited would let a number on the screen disagree with the reading that produced it.

= Can it remind me when something is due? =

Not by itself. The Due panel and the Next due column show you when you look. The daily digest, calendar and feed are in Brew Tracker Pro.

= Can more than one person log to the same batch? =

Yes. Anyone with an account that can edit posts can log to any batch. There is no per-batch assignment in this version.

= Does it need WooCommerce, or any other plugin? =

No.

== Screenshots ==

1. Brew Tracker — every batch with its stage, latest gravity, ABV and what is due next. (BrewTrackerBatchList.png)
2. Adding a batch: name, style, vessel, start date, volume, target OG and FG. (BrewTrackerAddBatch.png)
3. One batch: ABV with its provenance, the Due panel, the entry form and the timeline. (BrewTrackerBatchView.png)
4. The gravity chart. (BrewTrackerChart.png)
5. The Trash view with Restore and Delete permanently. (BrewTrackerTrash.png)
6. Settings: display units and the CSV export. (BrewTrackerSettings.png)
7. The Pro panel under the batch list. (BrewTrackerProPanel.png)

== Changelog ==

= 1.0.4 =
* Fixed: The plugin package no longer includes developer and internal files that were never meant to ship.

= 1.0.3 =
* Compatibility: tested against WordPress 7.1.
* The grey examples in the Ingredient, SG and pH boxes now read "e.g. Fermaid-O", "e.g. 1.090", "e.g. 3.4" so they are not mistaken for a typed value.

= 1.0.2 =
* Compatibility: tested against WordPress 7.1.
* An entry rewritten by an add-on (Brew Tracker Pro's Edit control) shows an "edited" mark on the timeline with the time of the edit. Entries stay immutable in this plugin. For add-ons: BFBT_Log::update() and the bfbt_log_row_controls filter.

= 1.0.1 =
* Compatibility: tested against WordPress 7.1.
* Temperatures follow the display-unit setting and carry their own unit: a °C / °F select beside the field, opening on the setting's unit, and the unit you pick is the one stored. A reading is checked and refused in the unit you typed it in (−10 to 60 °C, 14 to 140 °F); 74 typed as °C is refused with a hint towards °F rather than converted. The timeline shows the value as typed and, under the other setting, the conversion beside it — "74 °F (23.3 °C)". The CSV export gains a temp_entered column.
* Every unit select in the admin opens on the setting's unit. The setting's choices now read "US (gallons, ounces, °F)" and "Metric (litres, grams, °C)".
* A refused save keeps what you typed: every admin form comes back filled, the refused value included, and the entry form reopens on the type you submitted. Kept for thirty seconds in a per-user transient that uninstall sweeps; no new option.
* Photos on an entry: attach up to five images when you add an entry. They go into the media library against that entry, show as thumbnails in a new Photos column of the timeline, and are named by URL in the CSV. A file that is not an image is refused by name while the entry is kept; more than five refuses the entry. Deleting an entry permanently deletes its photos; trashing and uninstall keep them.

= 1.0.0 =
* Compatibility: tested against WordPress 7.1.
* First release: batches, a seeded style tree, vessels, an immutable timeline, pinned original gravity, ABV and attenuation with both formulas, an inline gravity chart, six derived stages, tasks with a Due panel and a Next due column, a paged list with Trash and Restore, display units, and a CSV export. Nothing of yours is removed on uninstall.

== Upgrade Notice ==

= 1.0.4 =
Removes files that did not belong in the plugin package. No action required.

= 1.0.0 =
First release.
