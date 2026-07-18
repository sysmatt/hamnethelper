# hamnethelper — Spec (v1.0, implemented)

> **Status:** Fully implemented and iterated on with real usage — this describes the app as it
> actually works today, not a pre-implementation plan. §8 (open questions) is empty; §9 lists
> what's deliberately out of scope rather than missing. If anything here drifts from actual
> behavior in the future, treat the code as authoritative and fix this doc to match.

## 1. Overview

A web tool for running amateur radio "nets" (structured on-air check-in sessions). An operator
(net control) opens a browser UI, starts or resumes a net, and logs check-ins as stations call in —
looking up each caller against a locally-uploaded roster and/or FCC license data (via `hamdat`) to
auto-fill name/city/state.

Two screens:

1. **Net list** — browse past nets, resume/delete/export them, or start a new one.
2. **Net operation** — the live check-in workspace for a single net.

Architecture mirrors [`hamdatweb`](../hamdatweb/) and [`simplewebauth`](../simplewebauth/):
a lightweight PHP backend (no framework, no build step) doing mostly file I/O, with the bulk of
interactive behavior in vanilla browser JavaScript. PHP's job is almost entirely "negotiate
storage" and "shell out to `hamdat`" — not rendering UI server-side.

---

## 2. Dependencies & deployment layout

| Requirement | Notes |
|---|---|
| PHP 8.0+ | With `exec()` enabled — required for `hamdat` CLI calls |
| Nginx + PHP-FPM | Matches existing deployment pattern |
| [`hamdat`](../hamdat/) | Installed and accessible to the web server process (same requirement as `hamdatweb`) |
| [`simplewebauth`](../simplewebauth/) | Deployed as a sibling directory in the docroot; protects every page/endpoint |

```
/var/www/html/                        ← docroot
├── hamnethelper/                     ← this repo, cloned/deployed here
│   ├── index.php                     ← net list page
│   ├── net.php                       ← net operation page
│   ├── lib/                          ← config loading, net file I/O, shared hamdat CLI call
│   ├── api/                          ← small PHP endpoints (JSON in/out)
│   ├── assets/                       ← JS/CSS/vendored libs, no build step
│   └── hamnethelper-config.php.example
├── hamnethelper-config.php           ← live config (created by you; NOT in the repo)
├── simplewebauth/
├── hamdatweb/                        ← unrelated sibling; not a runtime dependency of hamnethelper
└── ...

/var/lib/hamnethelper/nets/           ← net data, OUTSIDE the docroot
├── <net-id>.json                     ← one file per net: metadata + roster + check-ins
└── ...
```

**Data storage lives outside the docroot** (`/var/lib/hamnethelper/nets/` or similar — exact path
configurable), owned by `www-data`, never web-servable regardless of nginx config — same reasoning
as `auth_users.php` living outside the docroot in simplewebauth. A small PHP API in `api/` is the
only thing that reads/writes these files; the browser never touches them directly.

**hamdat integration is a direct CLI call**, same pattern as `hamdatweb/index.php`: PHP shells out
to the `hamdat` binary with `exec()`, using `--zip`, `--radius-miles`, `--json`, all arguments
through `escapeshellarg()`. This means `hamnethelper` needs its own `hamdat_bin`/`hamdat_db` config
— it does not depend on `hamdatweb` being installed or running.

hamdat is a `#!/usr/bin/env python3` script, not a compiled binary — invoking `hamdat_bin`
directly relies on that shebang resolving to a `python3` that actually has hamdat's own
dependencies (`requests`, `pgeocode`) installed, which is not guaranteed to be the web server
process's default `python3`. If hamdat needs to run from a specific venv, `hamdat_python_bin`
(e.g. `/home/hamdat/venv/bin/python3`) is invoked explicitly as the interpreter instead — see
`lib/hamdat.php` and the config reference in README.md. Confirmed the failure mode this fixes is
real, not theoretical: reproduced hamdat's own `Install pgeocode: pip install pgeocode` error
through the actual endpoint when invoked via a `python3` lacking that package, then confirmed the
same request gets meaningfully further (past the import, into the real query) once
`hamdat_python_bin` points at a venv that has it.

**Save model:** debounced autosave, no manual save button. Any change to the active net (check-in
added, edited, 73'd, reordered, note typed, script/notes edited, net closed/reopened) triggers a
background save a short delay after the last edit (e.g. 800ms debounce), via
`POST /api/net_save.php`. A small "Saved" / "Saving…" indicator sits near the check-in table.

If a save request fails (the one real reason a manual button would matter — a network/server
hiccup), autosave retries automatically with backoff, and the indicator switches to a persistent
"Save failed — click to retry" state until a save succeeds. If the operator tries to leave the page
while a save is pending or failed, a `beforeunload` confirmation warns them so in-progress work
isn't silently lost.

**Concurrency:** single-operator assumption. One net control operator runs one net at a time;
saves are last-write-wins with no locking. If you open the same net in two tabs, the second one to
save wins — acceptable for this workflow.

**Theming:** dark theme by default, with a light theme available via a toggle persisted in
`localStorage` — same pattern as `hamdatweb`.

---

## 3. Data model

### 3.1 Net file — `/var/lib/hamnethelper/nets/<net-id>.json`

```json
{
  "id": "b3e1f0b2-...",
  "schema_version": 1,
  "name": "Weekly ARES Net",
  "net_type": "weekly",
  "created_at": "2026-07-16T18:30:00-05:00",
  "updated_at": "2026-07-16T20:05:12-05:00",
  "opened_at": "2026-07-16T18:28:40-05:00",
  "official_start": "19:00",
  "ended_at": null,
  "net_control": "K1ABC",
  "frequency": "146.940 MHz -0.6 PL 100.0",
  "description": "",
  "status": "open",

  "script_notes": "# Welcome script\n\nGood evening, this is ... net control...\n\n## Announcements\n- ...",

  "hamdat_lookup": {
    "zip": "60601",
    "radius_miles": 25,
    "cached_results": [
      {"callsign": "W2XYZ", "name": "Bob Jones", "city": "Evanston", "state": "IL"}
    ],
    "last_refreshed_at": "2026-07-16T18:45:00-05:00"
  },

  "roster": [
    "K1ABC", "W2XYZ", "N3DEF"
  ],

  "checkins": [
    {
      "order": 1,
      "callsign": "K1ABC",
      "name": "Jane Smith",
      "preferred_name": "Janie",
      "city": "Chicago",
      "state": "IL",
      "checked_in_at": "2026-07-16T19:02:11-05:00",
      "checked_out_at": null,
      "notes": "Net control backup"
    }
  ]
}
```

Field notes:
- `roster` is the uploaded level-1 participant list — operators *likely* to check in, not a
  closed set. It's a first-pass lookup convenience only; any callsign can check in, including
  ones on neither the roster nor in the hamdat cache (visitors, etc.) — those just get added with
  whatever the operator types plus an optional `preferred_name` (§5.2), no `name` match. Persisted
  with the net so it doesn't need re-uploading on resume.
- `hamdat_lookup` stores the zip/radius the operator configured, plus the last-fetched level-2
  result set (`cached_results`) and when it was last refreshed. `radius_miles: 0` means exact-ZIP
  match only, same convention as `hamdatweb`'s `--radius-miles`. The cache is persisted so
  reopening a net doesn't require re-querying hamdat; a **Refresh** button in the Lookup Settings
  dialog re-runs the query on demand and overwrites both fields. See §3.3.
- `status` is `"open"` or `"closed"`. Set to `"closed"` via the **Close Net** action, which also
  stamps `ended_at`. Closing does not lock or hide data — see §5.5.
- `net_type` is a fixed dropdown, not free text — see §4 for the value list.
- `order` is the display/check-in order; renumbered on drag-reorder.
- `script_notes` is net-wide free text (welcome script, announcements, running notes) — markdown
  source, distinct from the per-check-in `notes` field below. See §5.3.
- `name` is the value as returned by the roster/hamdat lookup (or typed raw if no match) — never
  edited in place. `preferred_name` is an optional operator override (nickname/preferred name);
  `null` until set. Display logic composes them — see §5.2.
- `opened_at` is a full timestamp, distinct from `created_at` — deliberately so, since
  `created_at` is used elsewhere for identity/filenames and must stay immutable, whereas
  `opened_at` is meant to be operator-resettable (e.g. a net pre-created ahead of time, but
  check-ins didn't actually start until later). Defaults to `created_at` at creation time; nets
  written before this field existed fall back to `created_at` on read (`hnh_read_net()`) rather
  than requiring an on-disk migration. See §5.6.
- `official_start` is `"HH:MM"` (24h), no date component — some nets run for a fixed weekly
  time-slot regardless of what date they land on, others run long enough or start early enough
  that a bound date wouldn't mean anything useful. `null` if not set. See §5.6 for how it's
  resolved to an actual moment in time (it needs a calendar date from somewhere to be compared
  against "now").

### 3.2 Level-1 cache (browser)

The uploaded roster (`roster` above) is loaded into memory/`localStorage` client-side, keyed by
net id, for instant prefix/substring matching as the operator types — no server round-trip per
keystroke.

### 3.3 Level-2 cache (hamdat)

Result of the hamdat query: array of `{callsign, name, city, state}`. Persisted server-side in the
net file (`hamdat_lookup.cached_results` / `last_refreshed_at`, §3.1) so it survives across
sessions without re-querying hamdat every time the net is opened. Also mirrored into browser memory
on load for instant lookup-box matching, same as the level-1 roster.

The **Load** button in the Lookup Settings dialog runs on first setup (zip/radius not yet queried);
once results exist, the same control acts as **Refresh** — re-runs the query and overwrites the
cache. The dialog shows "Last refreshed: <relative time>" so the operator knows if the data might
be stale (e.g. after an FCC database update).

---

## 4. Screen 1 — Net list

Table/list of all nets, one row each, sourced by scanning `/var/lib/hamnethelper/nets/*.json` and
reading each file's metadata (name, date, net control, check-in count, status).

Columns: **Name · Date · Net Control · Check-ins · Status · Actions**

**UI conventions for this table** (revised after early testing showed mixed links/buttons reading
as inconsistent, and columns-per-action rejected as too wide for the alignment it would buy — see
below):
- **No dedicated Open/Resume button at all.** The distinction was cosmetic (both did the exact
  same navigation; "Resume" vs "Open" just echoed the Status column). Instead: the Name cell is a
  real `<a href="net.php?id=...">` (styled as plain text, not link-blue — but a real anchor, so
  native ctrl/middle-click-to-new-tab still works), and clicking *anywhere else* in the row that
  isn't itself a link/button/menu-summary also navigates there via a delegated click handler on
  the table body. Row gets `cursor: pointer` and a hover highlight so this is discoverable.
- **Actions column is icon-only buttons, uniform size**, not a mix of text links and text
  buttons — this is what actually produces the "always lined up" effect the per-column-per-action
  idea was reaching for, without needing 6+ extra table columns for it. Each icon carries a
  `title`/`aria-label` for what it does, since icon-only necessarily pushes the description into
  the tooltip.
- **Downloads collapse into one "⬇" menu** (native `<details>/<summary>`, closes on outside click)
  rather than 4 separate icon buttons for CSV/Report/Report w/ Notes/JSON — otherwise "several
  mismatched links" just becomes "several same-looking icons," which is no less visually busy.

Per-row actions:
- **Delete** (🗑 icon) — confirm, then remove the file
- **Download CSV / Report / Report w/ Notes / JSON backup** — all four grouped under one "⬇" menu
  (see UI conventions above). CSV is check-in table only, one row per check-in, Name column using
  the composed `preferred_name (name)` form (or just `name` if no preferred name is set), matching
  the on-screen table. Report is a clean plain-text summary, decided v1 format (an HTML option may
  follow later, not v1) — intended to be pasted directly into a follow-up email, so it favors
  readability over structure: net header (name, date, net control, frequency, status), the
  `script_notes` content — converted from its markdown source to clean plain text and word-wrapped
  at 80 columns (`hnh_markdown_to_plain()` in `api/net_download.php`: strips heading/bold/italic/
  link/code/strikethrough syntax, normalizes bullet markers, collapses horizontal rules to a blank
  line; a small regex-based stripper, not a full CommonMark parser, since script_notes is
  short-form announcements/scripts, not arbitrary complex markdown) — then the check-in list as a
  column-aligned plain-text table (#,
  Callsign, composed name form, City/State, Check-in, Check-out — widths computed from the
  actual data per download, like hamdat's own `--table` output, so columns line up cleanly
  whatever the longest callsign/name/city in that particular net happens to be, rather than a
  fixed width that would either truncate or misalign). The plain **Report** omits each check-in's
  per-row `notes` — those are often just internal shorthand for the net controller, not meant for
  an outward-facing follow-up — while **Report w/ Notes** appends each one as an indented line
  below its row (notes are free-text and can be long, so they're not forced into a table column).
  JSON backup is the raw net file, as-is. Every download
  filename includes the net's creation date (`<slug>-<YYYY-MM-DD>...`), since nets that share a
  name (a weekly net run under the same title every week) would otherwise all download to
  indistinguishable filenames.
- **Start new net like this one** (🔁 icon) — opens the same creation form used by **Begin New Net** (below),
  pre-filled with: name, net_type, frequency, description, net_control, official start time, hamdat
  zip/radius, roster, and `script_notes`. The operator can edit any field before submitting — nothing is created until
  they confirm. On submit: fresh `checkins: []`, new `id`, new `created_at`, `status: "open"`,
  `ended_at: null`, and a fresh (empty) `hamdat_lookup.cached_results` — the zip/radius carries over
  but the cache itself is not copied; if the zip is still filled in, submitting re-runs the lookup
  immediately (see below) rather than requiring a separate trip into Lookup Settings.

Page-level action: **Begin New Net** — opens a blank version of the same creation form (name,
net_type, net control, official start time, frequency, description, hamdat zip/radius) and creates
the file on submit. Official start time is optional (a plain `HH:MM` text field, 24h, no date —
see §5.6) — a net with a fixed weekly time-slot fills it in, a purely ad-hoc net leaves it blank.
Deliberately not a native `<input type="time">`: that control's displayed format (12h with AM/PM,
or 24h) follows the browser/OS locale, not anything the page can control, so it can't guarantee
consistent 24h display the way the rest of the app does (`HNH.formatTime()`, §5.1) — a plain text
field with a validated `HH:MM` pattern always shows exactly what's typed, no locale involved.

Page-level action: **Import Net** — restores a previously-downloaded JSON backup (the "Download
JSON backup" link above) as a new net. File picker → read client-side → `POST api/net_import.php`
with the parsed JSON as the body. Unlike "Start new net like this one," this is a **faithful
restore**: checkins, roster, script_notes, hamdat cache, status, and ended_at all carry over
exactly as they were. The one thing that never carries over is identity — `id`, `created_at`, and
`updated_at` are always reassigned fresh, regardless of whatever the uploaded file's own values
were. This is deliberate: importing the same backup twice, or a backup whose `id` happens to
collide with a net that already exists on this server (e.g. re-uploading to the server it came
from), must never silently overwrite anything — an import always produces one more net, never
fewer. A file that doesn't look like a hamnethelper backup (no `checkins` key) is rejected with a
clear error rather than attempting a best-effort partial import.

**If the ZIP field is filled in on submit** (either flow above), net creation runs the hamdat
lookup immediately server-side and populates `hamdat_lookup.cached_results`/`last_refreshed_at`
before the net is even opened — the operator shouldn't have to fill in the same zip/radius twice
(once on this form, again in Lookup Settings) to get the data they already asked for. This is
best-effort: a failed lookup (bad zip, hamdat unavailable) never blocks net creation — the net is
still created, and the error is surfaced back to the client (`hamdat_lookup_error` on the create
response) as a non-blocking warning; the operator can retry from Lookup Settings once the net is
open. The create dialog's submit button shows "Creating & looking up HAMDAT…" instead of a plain
"Creating…" when a zip is present, since the request can take a few seconds longer. Both this and
the Lookup Settings dialog's Load/Refresh (§5.1) enforce a minimum visible loading duration
(~500ms) so the indicator can't flash by unnoticed on a fast request — a plain create with no zip,
or a hamdat call that fails fast against a missing database, would otherwise complete quickly
enough that "loading" and "done" are indistinguishable from "nothing happened."

`net_type` is a fixed dropdown: **Weekly · Emergency/ARES · Drill/Training · Special Event ·
Other** (exact labels/values open to adjustment, but the set stays fixed rather than free text —
keeps net list data consistent for any future filtering/reporting by type).

---

## 5. Screen 2 — Net operation

### 5.1 Layout

- Header: net name, net control, status, a "Saved/Saving…" indicator, **Close Net** / **Re-open
  Net** button, and an **Exit to Net List** link. See §5.5 for how these two differ.
- **Two-column layout: Script & Notes on the left, lookup bar stacked directly above the
  check-in table on the right.** The lookup bar lives inside the same column as the table (not as
  a page-level bar above everything), so the two stay aligned with each other — same left edge,
  same width — regardless of how wide that column ends up being, rather than either the lookup
  bar spanning the full page width or Script & Notes eating into the table's column.
- **Callsign/name lookup box** — single text input. As the operator types, live-filters against
  roster (level-1) merged with hamdat cache (level-2) — matches ranked exact callsign > callsign
  prefix > callsign substring > name substring (hamdat entries only; the roster carries no name
  data), and **within each of those tiers, roster members sort ahead of hamdat-only matches** —
  the roster is the "expected to check into this net" list, so those calls should be the easiest
  to spot rather than however a text-match tiebreak happens to land. (An exact callsign match
  still wins outright regardless of roster status — typing the full call is effectively
  confirming identity either way.) Roster matches are also visually distinct in the dropdown: a
  green "★ Roster" badge and a tinted/accented row, shown whenever a callsign is on the roster
  even if it also matched via the hamdat cache. Shows up to `lookup_suggestion_limit` ranked
  results in a dropdown (config-driven, default 15 — see Configuration reference in README.md),
  first one highlighted by default. Arrow keys move the highlight, Enter selects the highlighted
  suggestion (or, if the dropdown has no matches, adds a row with just the typed callsign and no
  `name` — the visitor/unlisted-station case; the operator can still set a `preferred_name` via
  the pencil icon, §5.2). Escape closes the dropdown without clearing the typed text.
  - **A callsign can only be checked in once.** A callsign already on the check-in list (whether
    or not they've since been 73'd — the list entry still exists either way) is excluded from the
    suggestions dropdown entirely, and attempting to add one anyway (typing the exact callsign
    with no matching suggestion and pressing Enter) silently no-ops — no error, no duplicate row.
    To log the same callsign again, delete the existing row first.
  - **Global "/" shortcut**: pressing `/` anywhere on the page (when focus isn't already in a
    text field, including the Script & Notes editor) refocuses this box, select-all, so the
    operator can jump back to it without reaching for the mouse.
  - If `/` ends up as the box's own leading character (e.g. the shortcut fires while the box
    already had focus, so the keystroke types itself in instead of being intercepted), it's
    silently stripped — but only a *leading* `/`, since callsigns legitimately contain `/`
    mid-string for portable/mobile suffixes (`W1AW/4`, `/QRP`, `/MM`).
- **"Upload participant list" button** — file picker for a plain text file, one callsign per line.
  Replaces the net's `roster` outright (new upload is treated as authoritative). This list is a
  convenience shortlist of likely check-ins, not a restriction — any callsign can still be
  checked in.
- **"HAMDAT Lookup settings" button** — opens a dialog: zip code + radius (miles) fields, a
  **Load/Refresh** button that runs the hamdat query and (re)populates the persisted level-2 cache,
  and a "Last refreshed: <relative time>" indicator. While the query runs, the button disables and
  reads "Loading…" and the indicator reads "Querying hamdat…" — a hamdat call can take a few
  seconds, and giving no feedback while it's in flight reads as broken rather than slow.
- **Script & Notes panel** — a persistent text area (or collapsible panel) separate from the
  check-in table, for the net-wide welcome script, announcements, and running notes. See §5.3.
- **Check-in table**: columns `# · Callsign · Name · City · State · Check-in time · Check-out time
  · Notes · Actions`. All times/dates app-wide (check-in/out times, net list dates, "last
  refreshed") are shown 24-hour, no seconds — `HNH.formatTime()`/`HNH.formatDateTime()` in
  `assets/js/api.js`, shared everywhere rather than each page picking its own locale-default
  format (which is inconsistently 12- or 24-hour depending on browser locale, and always includes
  seconds by default).
  - Rows added sequentially as operator checks people in.
  - Drag-and-drop reordering via [SortableJS](https://github.com/SortableJS/Sortable) (vendored as
    a single static JS asset, no build step) — chosen over native HTML5 drag-and-drop for reliable
    touch/tablet support in case this ever runs off a laptop. Rows renumber (`#` column) after a
    drop.
  - Notes cell is an inline-editable text box (net control's free-text notes about that specific
    check-in — distinct from the net-wide Script & Notes panel). Its column is given `width: 100%`
    so it absorbs whatever space is left after every other (content-sized) column takes its
    minimum — this is what actually made room for it once QRZ/Delete became compact icon buttons
    (see below) rather than text buttons/links.
  - Actions: **🌐 QRZ** (opens `qrz.com/db/<callsign>` in a new tab), **73/Un-73** (marks checked
    out — sets `checked_out_at` to now, grays out + strikethroughs the row, button becomes
    **Un-73** which clears `checked_out_at` and restores normal styling), **🗑 Delete** (remove row,
    confirm), **✏️ Edit name** (see §5.2). QRZ and Delete are icon-only buttons matching the net
    list's icon-button convention (§4); **73 deliberately stays text**, not a picture-icon — it's
    the actual ham-radio nomenclature for signing off, immediately recognizable to the audience in
    a way an invented glyph wouldn't be. Styled as a pill matching the icon buttons'
    height/color/radius, just auto-width instead of a fixed square so "Un-73" isn't cramped.
  - **"Who's next" row highlight** — clicking anywhere in a row that isn't itself a button/input/
    link toggles that row as the single "current" one (click again to clear it; selecting a
    different row moves the highlight, it's never more than one at a time). Purpose is purely
    operational — helping net control keep track of which check-in they're currently working
    through — so it is **client-side UI state only, never persisted** to the net JSON or included
    in any save. Styled with a distinct amber/warning accent (not the blue used for
    buttons/active-suggestions, not the green used for the roster badge, so it can't be confused
    with either of those existing meanings) and composes fine with the 73'd strikethrough styling
    — a row can be both "done" and "currently pointed at" at once, they mean different things.
  - Below the table, a one-line status shows how many entries are actually loaded in each lookup
    list — e.g. `Roster: 42 callsigns · HAMDAT cache: 187 records (refreshed 5m ago)` (or "not
    loaded yet" in place of the refreshed time before the first Load). Without this there's no way
    to distinguish "roster upload silently produced 0 rows" from "nobody happens to be near this
    ZIP" other than noticing the lookup box quietly matching nothing. Updates automatically
    whenever the roster or hamdat cache changes (net load, roster upload, hamdat load/refresh).

### 5.2 Preferred / nickname handling

The Name cell displays `preferred_name (name)` when a preferred name is set (e.g. `Janie (Jane
Smith)`), or just `name` otherwise. The looked-up `name` is never overwritten in place — it's
provenance, showing what the roster/hamdat match actually said.

A pencil (✏️) action button switches the Name cell into edit mode: a text input pre-filled with
the current `preferred_name` (blank if unset). Enter or blur saves the value to `preferred_name`
and returns the cell to display mode. Clearing the field back to empty unsets `preferred_name`
(falls back to showing just the looked-up `name`).

`callsign` itself is not inline-editable in v1 — a mistyped entry is fixed via Delete + re-add
through the lookup box, not in-place correction.

### 5.3 Script & Notes editor

Backed by `script_notes` (markdown source, net-wide, autosaved same as everything else).

Originally [EasyMDE](https://github.com/Ionaru/easy-markdown-editor), replaced with
[Vditor](https://github.com/Vanessa219/vditor) after user testing found EasyMDE's edit/preview
toggle clunky — flipping between raw markdown and a separate, non-editable rendered view didn't
feel like a normal editor. Vditor's **"ir" (instant-render) mode** renders formatting inline as you
type — `**bold**` becomes actual bold text in place, no separate preview pane or mode switch at
all, much closer to Typora/Notion than a classic markdown-plus-preview split. Still backed by
plain markdown text (`script_notes` itself, the plain-text Report download, and the JSON export
are all unaffected by this swap — only the editing *experience* changed, not the storage format).

Vendored (not npm-installed — no build step, per this project's convention) at pinned version
3.11.2. Notably heavier than EasyMDE (~4.4 MB vs ~326 KB, see `assets/vendor/VERSIONS.md` for the
full breakdown) — the vast majority of that is Vditor's underlying "Lute" markdown engine, which
instant-render mode requires outright, not an optional extra. Judged acceptable for an internal
ops tool (one-time cached download, not a public/mobile-data-sensitive page). Only the subset
needed for the "ir" mode itself was vendored — Vditor's optional lazy-loaded renderers for math
(KaTeX/MathJax), diagrams (Mermaid/Graphviz/PlantUML/flowchart.js), charts (ECharts), music
notation (ABCJS), and chemistry (SMILES) were deliberately left out, since net notes will never
need them; if that syntax ever appears in notes, the relevant renderer 404s gracefully rather than
crashing (falls back to a plain code block).

Falls back to a plain `<textarea>` if the vendor asset is missing (same as the EasyMDE-era
fallback behavior) — low-risk either way, since the storage format never depended on which editor
was in front of it.

Height is left at Vditor's default (`'auto'`, only `minHeight: 150` set) rather than a fixed
value, so the editor grows to fit its content — same behavior as the old EasyMDE integration.
Fixing a height produces an internal scrollbar for longer scripts, which reads as cramped for what
is otherwise the primary place a net controller writes running notes through a whole net.

### 5.4 Other interaction notes

- Check-in time is set automatically when a row is created; check-out time only ever moves via the
  73/Un-73 toggle. No manual time editing in v1 — confirmed not needed.

### 5.5 Closing, reopening, and exiting a net

These are three distinct operations, easy to conflate but functionally separate:

- **Close Net** — a state change, not a navigation. Sets `status: "closed"` and stamps `ended_at`.
  The entire workspace grays out and becomes read-only — callsign/name lookup box, upload
  participant list, HAMDAT Lookup settings, the Script & Notes editor, and every check-in row action
  (Delete, 73/Un-73, drag-reorder, the notes cell, the preferred-name pencil) — but nothing is
  hidden; all data stays visible. Locked rather than just visually dimmed, since net control
  sometimes needs to log a straggler who keeps talking after the formal close — that requires
  Re-open first, by design, so a close is a deliberate signal.
- **Re-open Net** — undoes the above: sets `status: "open"` and clears `ended_at`, restoring full
  editability. The button appears in place of Close Net whenever a net is closed. (v1 keeps a
  single `ended_at` value, overwritten on each close — it reflects the *most recent* close time,
  not a full close/reopen history.)
- **Exit to Net List** — pure navigation back to Screen 1 (`index.php`). Does not change `status`
  or `ended_at`. Available regardless of open/closed state — a net doesn't need to be closed before
  the operator can leave it (autosave already covers persistence).

On the net list (Screen 1), a closed net still shows **Open/Resume**, which lands on the same
`net.php` view — closed just means it opens already grayed out, with **Re-open Net** one click
away.

### 5.6 Net-clocks ribbon

Four small clocks live in the header, between the net name and the Saved indicator/Close-Net/
Theme buttons — abbreviated labels (**Start · Open · Dur · Closed**) to keep the ribbon compact,
each showing a big `HH:MM` (24h) value with a small date underneath, except **Start** (no date —
it's a dateless field, showing a date under it would misleadingly imply otherwise) and **Duration**
(an offset, not a point in time). On a window too narrow to fit the whole header on one line, it
wraps to a second line rather than cramming or scrolling.

- **Start** shows `official_start` if set, static, with a small pencil-edit affordance (same
  interaction as the preferred-name edit, §5.2) that turns it into an inline `HH:MM` text field
  (not a native `<input type="time">` — see §4 for why) — Enter or blur commits, Escape cancels
  without saving. If no `official_start` is set yet, the
  edit affordance shows as "+" instead of a pencil, so one can be added at any time, not just at
  net creation. Editing is disabled while the net is closed, consistent with the rest of the
  workspace lockout (§5.5).
- **Open** shows `opened_at`, with a small "reset" button (↺, `title="Reset Opened time to now"`)
  that sets `opened_at` to the current moment — for a net pre-created ahead of time where check-ins
  didn't actually start until later. Also disabled while closed.
- **Duration** is a live `±HH:MM:SS` count-up/count-down timer, the only clock that shows seconds,
  ticking once per second while the net is open. The sign is what actually encodes count-down vs
  count-up — there's a single formula, not two separate code paths:

  ```
  anchor = official_start resolved to a real datetime (see below), or opened_at if unset
  duration = (ended_at if closed, else now) − anchor
  ```

  Negative (`-HH:MM:SS`) reads as "counting down to official start"; positive (`+HH:MM:SS`) reads
  as "elapsed since official start" (or, with no `official_start` set, simply elapsed since
  `opened_at` — always counting up in that case, per the formula above). Hours are **unbounded**,
  not wrapped at 24 (`+30:15:22` for a multi-day net), since some nets legitimately run that long.
  **Freezes at its final value once the net is closed** — stops ticking, computed once against
  `ended_at` rather than live wall-clock time, so it doesn't keep counting up after the net (and the
  static Closed clock next to it) says otherwise. Resumes live ticking immediately on Re-open.

  Resolving `official_start` (`"HH:MM"`, no date) to a comparable datetime requires anchoring it to
  some calendar day — the chosen day is `opened_at`'s. This is naturally correct for the common
  case (net opened a bit before its same-day official start), but has one edge case worth spelling
  out: a net opened just before midnight for an official start just after midnight (e.g. opened
  23:50, `official_start` "00:15") would, anchored naively to the *same* calendar day, put the
  anchor *before* `opened_at` — Duration would immediately read as "~24h already elapsed," which is
  backwards (the real start is 25 minutes away, not a day in the past). Fixed by rolling the anchor
  forward one calendar day whenever the same-day anchor would fall before `opened_at` — implemented
  identically in both `assets/js/net.js` (`computeAnchorMs()`, for the live ribbon) and
  `api/net_download.php` (`hnh_official_start_anchor()`, for the report, §6) so the two can never
  disagree with each other.
- **Closed** only appears once the net is closed, showing `ended_at`.

`official_start` and the computed `Duration` (same formula, frozen/live the same way, generated at
download time) are included in the plain-text **Report** header — see §6/§4.

---

## 6. API (all under `api/`, JSON in/out, all behind the session check in `api/_bootstrap.php`)

| Endpoint | Method | Purpose |
|---|---|---|
| `api/config.php` | GET | Public, browser-safe config subset (net types, debounce timing, theme, etc. — §2/README) |
| `api/nets_list.php` | GET | List all nets + metadata (scans data dir) |
| `api/net_create.php` | POST | Create a new net from submitted form fields (blank or pre-filled — see §4's two creation flows). Runs the hamdat lookup inline if a ZIP is present. No `source_id` param — the client resolves any carry-over fields itself before submitting, so this endpoint always just creates from whatever fields it's given |
| `api/net_import.php` | POST | Restore a previously-downloaded JSON backup as a **new** net — full fidelity (checkins, roster, script_notes, hamdat cache, status) except identity, which is always reassigned fresh (§4) |
| `api/net_get.php?id=` | GET | Fetch full net JSON |
| `api/net_save.php` | POST | Merge-and-overwrite a net's JSON (autosave target); `id`/`created_at` are server-authoritative, `updated_at` always recomputed |
| `api/net_delete.php?id=` | POST | Delete a net file |
| `api/net_download.php?id=&format=csv\|json\|report[&notes=1]` | GET | Stream a download — `report` omits per-check-in notes unless `notes=1` is also given (§4), and includes `official_start`/`Duration` in its header, computed at generation time (§5.6) |
| `api/hamdat_lookup.php` | POST | `{zip, radius_miles}` → hamdat CLI query → `{results: [{callsign,name,city,state}]}` |

This is the actual, implemented API — not a proposal. `lib/hamdat.php` holds the shared hamdat CLI
invocation used by both `net_create.php` and `hamdat_lookup.php`, so the exec()/escapeshellarg()
logic exists in exactly one place.

---

## 7. Security notes

- **Pages** (`index.php`, `net.php`) require `simplewebauth` directly (`require __DIR__ .
  '/../simplewebauth/auth.php';`) — redirects to the login page on a missing/expired session,
  correct for a browser navigation.
- **`api/*.php` endpoints** use a separate session check in `api/_bootstrap.php` instead — a
  redirect response can't be sensibly parsed by `fetch()` (the browser just follows it silently
  and returns login-page HTML with a 200 status), so the API layer checks the same session
  simplewebauth set (same cookie name/lifetime/`gc_maxlifetime`) and returns a 401 JSON body on
  failure instead. This intentionally duplicates simplewebauth's session-validation logic rather
  than modifying that shared repo (it's reused by other tools too) — see the comment at the top
  of `api/_bootstrap.php` for the full reasoning, and revisit if this pattern recurs enough to
  justify a shared "auth_api.php" in simplewebauth itself.
- Net data lives outside the docroot — not servable by nginx/Apache under any config.
- `net-id` path components validated against a fixed pattern before touching the filesystem, to
  prevent path traversal.
- Net file writes are atomic (write to a temp file in the same directory, then `rename()`), so a
  request that dies mid-write never leaves a truncated net file behind.
- hamdat CLI args (`zip`, `radius`) validated (5-digit zip, non-negative integer radius) and
  passed through `escapeshellarg()`, matching `hamdatweb`'s existing approach. If
  `hamdat_python_bin` is configured, it's passed through `escapeshellarg()` too before being used
  as the interpreter (§2).
- Uploaded roster files: size-limited, parsed as plain text, one token per line, loosely validated
  as callsign-shaped (not strictly enforced — partial/garbage entries just won't match anything
  useful).
- `official_start` is validated against a strict `HH:MM` (24h) pattern server-side
  (`hnh_valid_official_start()` in `lib/net_store.php`) on every write path (create, autosave,
  import) — anything else, including empty string, normalizes to "not set" rather than being
  rejected outright.
- Imported net JSON backups (`api/net_import.php`) are merged over a full-shape skeleton rather
  than trusted wholesale, so a hand-edited or partially-missing backup can't produce a
  structurally invalid net file — and identity fields (`id`/`created_at`/`updated_at`) are always
  reassigned server-side regardless of what the uploaded file claims, so an import can never
  collide with or silently overwrite an existing net (§4).

---

## 8. Open questions

None remaining for this round — every item from the original list has been resolved and folded
into the relevant section above.

---

## 9. Explicitly out of scope for v1 (call out if wrong)

- Multi-operator concurrent editing / conflict resolution beyond last-write-wins.
- Real-time updates across multiple browser tabs/devices watching the same net.
- Any audio/radio integration (this is a logging tool, not a net-control radio interface).
- User/role management beyond what `simplewebauth` already provides (single flat user list, no
  per-user permissions).
