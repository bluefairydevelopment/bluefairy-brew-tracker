# BlueFairy Brew Tracker — features

Every statement here is a claim about the shipped code. If one stops being
true, the code is wrong or this file is.

## The batch record

One record, `bfbt_batch`, owned by this plugin. The Pro add-on extends the same
record through `BFBT_Batch::register_field()` rather than keeping its own, which
is why installing Pro shows you every batch you have already logged.

| Field | Where it lives |
|---|---|
| Name | the post title |
| Style | a term in the `bfbt_style` taxonomy |
| Vessel | a term in the `bfbt_vessel` taxonomy, with its type and capacity as term meta |
| Started | `_bfbt_started` — the day the yeast went in; blank for a batch you are planning |
| Volume | `_bfbt_volume_l` in litres, with `_bfbt_volume_entered` keeping "1 gal" as you typed it |
| Target OG, target FG | `_bfbt_target_og`, `_bfbt_target_fg` |
| Racked to secondary, packaged, completed | `_bfbt_secondary_date`, `_bfbt_packaged_date`, `_bfbt_completed_date` |
| Dumped | `_bfbt_dumped` — `1`, or absent |
| Original gravity | `_bfbt_og_log_id` — which reading counts as OG |
| Notes | the post content, through `wp_kses_post` |

The style tree is seeded on activation: Mead (Traditional, Melomel, Cyser,
Metheglin, Braggot, Pyment), Wine (Red, White, Rosé, Fruit, Sparkling), Cider
(Dry, Sweet, Perry), Beer (Ale, Lager, Stout, IPA, Wheat, Sour) — twenty-four
terms. Seeding is by slug and never overwrites a term, so activating twice seeds
once and a style you renamed keeps its name.

A vessel is a term with a type (carboy, bucket, demijohn, conical, keg, barrel,
other) and a capacity. You pick an existing vessel from a list or type a new one
on the batch form, so "Carboy A" stays one vessel however many batches go
through it.

**Check your batches from the command line:**

```
wp post list --post_type=bfbt_batch --fields=ID,post_title,post_status
wp post list --post_type=bfbt_log --post_parent=<batch id> --fields=ID,post_title,post_date
wp post list --post_type=bfbt_task --post_parent=<batch id> --fields=ID,post_title
wp term list bfbt_style --fields=term_id,name,slug,parent
wp term list bfbt_vessel --fields=term_id,name
```

## Validation refuses, never guesses

Every value is checked before anything is written and refused with the value
quoted back. A refused save is never reported as a success.

- **Dates** are calendar-checked in year-month-day. `2026-02-30` has the right
  shape and is not a day, so it is refused. `03/14/2026` is refused rather than
  read as the 3rd of February.
- **Gravity** must be typed as `1.090`, in `0.980` to `1.200`. `1090`, `90` and a
  value that looks like Brix are refused with the hint "enter as 1.090" rather
  than reinterpreted into a reading you did not take.
- **Quantities** are non-negative decimals with a dot. **Units** come from a fixed
  list: g, kg, oz, lb, ml, l, gal, tsp, tbsp, pkt, tablet, each.
- **Temperature** is typed in °C or °F — a select beside the field, opening on
  the unit your setting implies — and checked in that unit: −10 to 60 °C, or
  14 to 140 °F. The refusal names the unit you typed in ("“200” is outside the
  temperature range this plugin accepts (14 to 140 °F)"). A °C entry above 60
  that reads like an ordinary °F number — 74, say — is refused with the hint
  "If you meant Fahrenheit, pick °F next to the field or set your units to US
  in Settings." It is never converted for you.
- **pH** is 0 to 14.
- **Volume** is a number and a volume unit (ml, l, gal) and must be more than
  zero.

### Your input survives a refused save

A refused save does not send you back to an empty form. Every admin form —
add and edit batch, add entry, add task, milestones — comes back filled with
what you typed, the refused value included so you can see what was wrong, and
the entry form reopens on the type you submitted. The copy is kept for thirty
seconds in a per-user transient beside the notice and dropped the first time
the form reads it: a reload after that is a fresh form.

## Units

Volumes are stored in litres, mass in grams, temperature in °C. One setting,
**Display units** — "US (gallons, ounces, °F)" or "Metric (litres, grams,
°C)" — decides two things: how a *derived* volume is shown (the batch size,
say, appears as "1 gal (3.79 l)" in metric and "1 gal (1.00 gal)" in US), and
which unit every form offers first (gal, oz and °F under US; l, g and °C under
metric). A value you logged is never converted: a 1.2 g Fermaid-O dose is
shown as 1.2 g whatever the setting says, and a reading typed as 74 °F stays
"74 °F" — under metric it reads "74 °F (23.3 °C)", the same reading in the unit
you now work in. The unit you pick on the entry is the one that counts.

## The timeline

Every entry is a `bfbt_log` post with the batch as its parent, dated when it
happened rather than when it was typed (the "When" field, `Y-m-d H:i`,
calendar-checked). Seven entry types: addition, gravity, temperature, pH, note,
racking, stage.

| Type | What it records | Generated title |
|---|---|---|
| Addition | ingredient as typed, quantity, unit | `Fermaid-O 1.2 g` |
| Gravity | SG, and a temperature if you took one, in the unit you typed | `SG 1.042 @ 20 °C`, `SG 1.050 @ 68 °F` |
| Temperature | °C or °F as typed; stored in °C as well | `74 °F` |
| pH | pH | `pH 3.40` |
| Racking | optionally the volume that came across | `Racked — 3.5 l across` |
| Note | free text | the first sixty characters |
| Stage | a label | the label |

**Entries are immutable.** There is no edit form and the handler refuses any
posted entry id. A typo is trashed and re-entered, so a number on the batch
screen can always be traced to the entry that produced it.

The timeline shows fifty entries a page, newest first. Trash is offered only to
users who may delete others' posts.

An entry dated ahead of the site clock stays published. WordPress would
otherwise schedule it as a future post and drop it from every query.

### Photos on an entry

The entry form takes up to five images with the entry — the krausen, the
hydrometer, the colour after a racking. Each becomes an ordinary media-library
attachment whose parent is the entry, shown as a thumbnail in the timeline's
Photos column (the thumbnail opens the full image) and named by URL in the
CSV. Only image types the site accepts are stored; anything else is refused by
name while the entry and the other photos are kept, because a reading typed in
is worth more than a picture of it: "Entry added, but 1 photo was not saved.
“notes.txt”: Sorry, you are not allowed to upload this file type." More than
five refuses the whole entry before anything is written.

Photos follow the entry. Trashing the entry (or its batch) leaves them in
place for a restore; deleting it permanently deletes them, row and file, where
WordPress on its own would only orphan them. Uninstall keeps them, as it keeps
the entry. A browser never re-fills a file input, so a refused save gives back
every typed field but the photos; the form says so.

## Original gravity, ABV and attenuation

The first gravity reading you log is pinned as the original gravity. Any other
gravity reading on the timeline has a **Use as OG** button; trashing the pinned
reading clears the pin.

ABV and apparent attenuation are worked out at read time from the pinned OG and
the latest gravity reading. Neither number is stored, and both are shown with
their provenance — "OG 1.110, reading of 2026-03-01 · FG 1.000, reading of
2026-03-21" — so trashing or re-dating an entry changes a visible number, never
a hidden one.

Two formulas, and the screen names the one it used:

| OG | Formula |
|---|---|
| up to 1.070 | `(OG − FG) × 131.25` |
| above 1.070 | `76.08 × (OG − FG) / (1.775 − OG) × (FG / 0.794)` |

The standard formula under-reports a mead by around 1.4 % ABV, which is most of
the difference between a session mead and a sack mead.

## The gravity chart

An inline SVG of every gravity reading over time, up to five hundred points,
drawn by the plugin with no JavaScript library and no external asset. Hovering
a point shows the reading and its date. Every number is cast to a float and
every label is escaped before it reaches the markup. A batch with no readings
says "No readings yet."

## Stage

Derived from the milestone dates, never stored, so a batch cannot disagree with
itself.

| Stage | When |
|---|---|
| Dumped | the batch is marked dumped |
| Complete | a completed date is recorded |
| Packaged | a packaged date is recorded |
| Secondary | a racked-to-secondary date is recorded |
| Primary | a start date is recorded |
| Planned | nothing yet |

Most final wins: a batch that was completed and then poured away is dumped, and
the completed date left behind does not hide that. The batch screen says how
many days it has been in its stage.

Pro replaces the answer through the `bfbt_batch_stage` filter and inserts its
own states into the order through `bfbt_stage_order`. It supplies only the
states between the ends; planned, complete and dumped are decided here, once.

## Tasks and what is due

A task is a `bfbt_task` post: what to do, when, and when it was done. Five
types: addition, gravity check, rack, package, other. An addition task carries
an ingredient, quantity and unit, so "Fermaid-O 1.2 g" is one line and one
button; leave the quantity blank and the instruction lives in the title ("water
to 1 gal").

The **Due** panel on the batch screen lists open tasks soonest first — overdue
in red, today in amber, undated in grey. **Done** logs the addition (or a note
"Done: rack") to the timeline and stamps the task; **Undo** reopens it and
leaves the entry in place. Completing is idempotent: a double-click or replayed
form cannot log the same task twice.

The **Next due** column on the batch list shows each batch's earliest open
task, red when overdue, worked out in one grouped query for the whole page. A
task with no date never becomes "next due".

Tasks are typed by hand. Nothing here generates a schedule; that is the first
thing Pro adds.

## The list

Twenty-five batches to a page, sorted and paged **in the database**. Columns:
Batch, Style, Vessel, Started, Stage, Latest SG, ABV, Next due. Sort by name or
by start date in either direction — a planned batch with no start date stays on
the list. Filter by stage and by style.

**All | Trash** views. Trashing a batch takes its entries and tasks with it;
restoring brings back exactly the ones that went with it, not an entry you had
trashed on purpose beforehand; deleting permanently from the Trash view removes
the batch and its children for good.

## Who may do what

| Action | Capability |
|---|---|
| See the screen, add and edit batches, log entries, add and complete tasks, pin OG | `edit_posts` |
| Trash, restore and delete a batch; trash an entry | `delete_others_posts` |
| Settings and CSV export | `manage_options` |

A Contributor can log; only someone who may delete others' posts can destroy.
Every write is a nonced form post to an `admin_post_*` handler that checks its
capability first and confirms the post type of every id it is handed.

## CSV export

**Settings → Export CSV** streams every batch and then every entry as one file,
five hundred rows at a time, never built in memory. Every field is quoted, and a
value beginning with `=`, `+`, `-`, `@`, a tab or a carriage return is prefixed
with a single quote so a batch named `=HYPERLINK(...)` opens in a spreadsheet
as text, not as a formula. An entry's photos are named by URL in the last
column of the entries section, `photos`, space-separated.

## Uninstall

Uninstalling deletes `bfbt_features`, `bfbt_units`, `bfbt_seeded`, the
plugin's per-user transients (the one-shot notice and a refused form's
remembered input), and `bfbt_style_children` — the cached
parent/child map WordPress itself keeps for the style taxonomy — never a batch,
an entry, a task, a photo, a style or a vessel. There is no `LIKE 'bfbt_%'` sweep: that pattern would also match
the Pro add-on's `bfbtp_` settings.

## Extension points

| Hook | Purpose |
|---|---|
| `bfbt_loaded` | fires before this plugin registers its own subsystems; where Pro attaches |
| `BFBT_Batch::register_field()` | add a field to the batch record |
| `bfbt_batch_stage`, `bfbt_stage_order`, `bfbt_terminal_stages` | replace the derived stage, insert states, name the stages after which nothing is scheduled |
| `bfbt_stage_labels`, `bfbt_stage_colours` | name and colour new states |
| `bfbt_log_types`, `bfbt_task_types` | add entry and task types |
| `bfbt_batch_created`, `bfbt_batch_updated`, `bfbt_log_created`, `bfbt_task_completed` | react to a change |
| `bfbt_batch_form_fields`, `bfbt_batch_form_submit` | add fields to the add/edit form and read them back |
| `bfbt_batch_actions` | add buttons to the batch screen |
| `bfbt_after_batch_list`, `bfbt_settings_after` | add content below the list and the settings |
| `bfbt_admin_menu` | add pages under the Brew Tracker menu |
| `bfbt_ingredient_label` | resolve an ingredient typed as text |
| `bfbt_units` | override the display-unit setting |
| `bfbt_feature_labels`, `bfbt_feature_descriptions` | declare feature switches |

Signatures are in `README.md`.

## What this plugin does not do

- No schedule. It works out ABV and attenuation from what you logged, and
  nothing else; every task is one you typed.
- No email, no cron, no scheduled work, no dashboard widget.
- No REST routes, no front end, no database tables of its own.
- Three options of its own, all removed on uninstall, along with the style
  hierarchy cache WordPress keeps for it.

---

## Free and Pro

This plugin is the base of a pair. Pro is an add-on: it extends this plugin
rather than replacing it, so there is one batch record, one place your data
lives, and nothing to migrate when you upgrade.

Pro column describes Brew Tracker Pro 1.0.


| | Pro — $79 | Free |
|---|---|---|
| Batch record: name, style, vessel, start date, volume, target OG/FG, notes | Yes | Yes |
| Style taxonomy seeded with Mead, Wine, Cider, Beer and their substyles | Yes | Yes |
| Vessel taxonomy with type and capacity | Yes | Yes |
| Timeline log per batch: additions, gravity, temperature, pH, notes, racking | Yes | Yes |
| Up to five photos on an entry, thumbnails on the timeline | Yes | Yes |
| ABV and attenuation from the pinned OG and the latest reading, both formulas stated | Yes | Yes |
| Gravity chart per batch, inline SVG | Yes | Yes |
| Six-stage cycle: planned, primary, secondary, packaged, complete, dumped | Yes, extended | Yes |
| Manual tasks with due dates, a Next due column and a Due panel | Yes | Yes |
| Paged, sortable batch list with a Trash view and Restore | Yes | Yes |
| CSV export of batches and entries | Yes | Yes |
| Batches kept on uninstall | Yes | Yes |
| **Recipes** — ingredient bill and schedule template | Yes | — |
| **Seeded recipe library** of common meads, wines, ciders and beers at 1, 3 or 5 US gallons | Yes | — |
| **Start a batch from a recipe** — bill scaled, additions planned, tasks generated | Yes | — |
| **Bottle count and priming sugar** from batch volume and bottle size | Yes | — |
| **Staggered nutrient preset** (day 0 / 1 / 2 / 7) and gravity-check recurrence | Yes | — |
| **Gravity-conditioned tasks** — "rack when SG ≤ 1.000" | Yes | — |
| **Daily digest email** — overdue, due today, due tomorrow, silent batches | Yes | — |
| **Dashboard widget, admin calendar and .ics feed** | Yes | — |
| **Extended stages** — cold crash, stabilised, back-sweetened, conditioning, carbonating | Yes | — |
| **Vessel board** — which vessel holds what, which are free | Yes | — |
| **Brewer role and front-end shortcodes** — board, tap-to-log, calendar | Yes | — |
| **Reports** — batches per style, average ABV, days per stage, vessel use | Yes | — |
| **Clone or re-brew a batch** | Yes | — |
| **Ingredient library** with default units | Yes | — |

### Why the missing rows cost you

**You are keeping the schedule in your head.** This plugin records the tasks you
type. A one-gallon mead wants Fermaid-O on day 0, 1, 2 and 7, a gravity reading
on day 3, 7 and 14, and a rack when the gravity settles — and you are the one
counting the days for every carboy on the shelf. Pro keeps that as a recipe and
"Start batch from recipe" creates every task with its date.

**Nothing tells you until you look.** The Due panel is on the batch screen, so
you find out what is due when you open it. Pro sends a daily digest —
overdue, due today, due tomorrow, batches that have gone quiet — and puts the
same list on the dashboard, a calendar and a feed your phone subscribes to.

**A rack is a judgement, not a date.** "Rack to secondary when SG is at or below
1.000 and stable" cannot be typed as a task with a date. Pro's gravity-conditioned
tasks become due when a reading satisfies them, and only when the last few
readings agree.

**You are scaling the bill by hand.** Three pounds of honey for a gallon; how
much for three? How many 16 oz bottles from five gallons after losses? Pro scales
the recipe to the batch, keeps one yeast packet as one packet, rounds tablets up,
and puts the bottle count on the packaging task.

**Nobody else can log a reading.** This plugin is a wp-admin screen. Pro adds a
Brewer role and a phone-sized front end where the recipe's ingredients are
one-tap buttons.

### Get Pro

**https://wordpress.bluefairydevelopment.com/product/bluefairy-brew-tracker-pro/**

One payment, no subscription, lifetime licence for one site. Pro requires this
plugin to be installed and active — it is an add-on, not a replacement, and
every batch you have already logged carries straight over.
