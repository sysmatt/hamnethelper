# hamnethelper — Spec (Draft v0.2)

> **Status:** All open questions resolved as of this draft (§8 is empty). Read through end to end
> before implementation starts — flag anything that's wrong or has drifted from what you meant.

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
│   ├── api/                          ← small PHP endpoints (JSON in/out)
│   ├── assets/                       ← JS/CSS, no build step
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
through `escapeshellarg()`. This means `hamnethelper` needs its own `HAMDAT_BIN`/`HAMDAT_DB` config
— it does not depend on `hamdatweb` being installed or running.

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

Columns (draft): **Name · Date · Net Control · Check-ins · Status · Actions**

Per-row actions:
- **Open / Resume** — navigate to `net.php?id=...`
- **Delete** — confirm, then remove the file
- **Download CSV** — check-in table only, one row per check-in. Name column uses the composed
  `preferred_name (name)` form (or just `name` if no preferred name is set), matching the on-screen
  table.
- **Download Report** — clean plain-text summary, decided v1 format (an HTML option may follow
  later, not v1). Intended to be pasted directly into a follow-up email, so it favors readability
  over structure: net header (name, date, net control, frequency, status), the `script_notes`
  content, then the check-in list (composed name form, city/state, check-in/out times, per-check-in
  notes).
- **Download JSON backup** — the raw net file, as-is
- **Start new net like this one** — opens the same creation form used by **Begin New Net** (below),
  pre-filled with: name, net_type, frequency, description, net_control, hamdat zip/radius, roster,
  and `script_notes`. The operator can edit any field before submitting — nothing is created until
  they confirm. On submit: fresh `checkins: []`, new `id`, new `created_at`, `status: "open"`,
  `ended_at: null`, and a fresh (empty) `hamdat_lookup.cached_results` — the zip/radius carries over
  but the cache itself is not copied, so the operator re-runs Load for current data.

Page-level action: **Begin New Net** — opens a blank version of the same creation form (name,
net_type, net control, frequency, description, hamdat zip/radius) and creates the file on submit.

`net_type` is a fixed dropdown: **Weekly · Emergency/ARES · Drill/Training · Special Event ·
Other** (exact labels/values open to adjustment, but the set stays fixed rather than free text —
keeps net list data consistent for any future filtering/reporting by type).

---

## 5. Screen 2 — Net operation

### 5.1 Layout

- Header: net name, net control, status, a "Saved/Saving…" indicator, **Close Net** / **Re-open
  Net** button, and an **Exit to Net List** link. See §5.5 for how these two differ.
- **Callsign/name lookup box** — single text input. As the operator types, live-filters against
  roster (level-1) merged with hamdat cache (level-2) — matches ranked exact callsign > callsign
  prefix > callsign substring > name substring (hamdat entries only; the roster carries no name
  data). Shows up to 8 ranked results in a dropdown, first one highlighted by default. Arrow
  keys move the highlight, Enter selects the highlighted suggestion (or, if the dropdown has no
  matches, adds a row with just the typed callsign and no `name` — the visitor/unlisted-station
  case; the operator can still set a `preferred_name` via the pencil icon, §5.2). Escape closes
  the dropdown without clearing the typed text.
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
  and a "Last refreshed: <relative time>" indicator.
- **Script & Notes panel** — a persistent text area (or collapsible panel) separate from the
  check-in table, for the net-wide welcome script, announcements, and running notes. See §5.3.
- **Check-in table**: columns `# · Callsign · Name · City · State · Check-in time · Check-out time
  · Notes · Actions`.
  - Rows added sequentially as operator checks people in.
  - Drag-and-drop reordering via [SortableJS](https://github.com/SortableJS/Sortable) (vendored as
    a single static JS asset, no build step) — chosen over native HTML5 drag-and-drop for reliable
    touch/tablet support in case this ever runs off a laptop. Rows renumber (`#` column) after a
    drop.
  - Notes cell is an inline-editable text box (net control's free-text notes about that specific
    check-in — distinct from the net-wide Script & Notes panel).
  - Actions: **Delete** (remove row, confirm), **73** (marks checked out — sets
    `checked_out_at` to now, grays out the row, button becomes **Un-73** which clears
    `checked_out_at` and restores normal styling), **✏️ Edit name** (see §5.2).

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

Backed by `script_notes` (markdown source, net-wide, autosaved same as everything else). Default
plan: [EasyMDE](https://github.com/Ionaru/easy-markdown-editor) — a single vendored JS + CSS file,
no build step, drops onto a `<textarea>`. Gives a formatting toolbar and a built-in preview toggle
without any backend involvement; the underlying `<textarea>` value (raw markdown) is what gets
saved. Low-risk to swap for a plain `<textarea>` later if it turns out to be more than needed.

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

---

## 6. Proposed API (all under `api/`, JSON in/out, all behind simplewebauth)

| Endpoint | Method | Purpose |
|---|---|---|
| `api/nets_list.php` | GET | List all nets + metadata (scans data dir) |
| `api/net_create.php` | POST | Create a new net; optional `source_id` to carry over metadata |
| `api/net_get.php?id=` | GET | Fetch full net JSON |
| `api/net_save.php` | POST | Overwrite a net's JSON (autosave target) |
| `api/net_delete.php?id=` | POST | Delete a net file |
| `api/net_download.php?id=&format=csv\|json\|report` | GET | Stream a download |
| `api/hamdat_lookup.php` | POST | `{zip, radius_miles}` → hamdat CLI query → `[{callsign,name,city,state}]` |

Exact shapes/names are easy to change — flagging for your review, not final.

---

## 7. Security notes (draft)

- Every page and every `api/*.php` endpoint requires `simplewebauth` (`require __DIR__ .
  '/../simplewebauth/auth.php';` or equivalent for files under `api/`).
- Net data lives outside the docroot — not servable by nginx under any config.
- `net-id` path components validated as UUIDs (or similar fixed format) before touching the
  filesystem, to prevent path traversal.
- hamdat CLI args (`zip`, `radius`) validated (5-digit zip, numeric radius) and passed through
  `escapeshellarg()`, matching `hamdatweb`'s existing approach.
- Uploaded roster files: size-limited, parsed as plain text, one token per line, loosely validated
  as callsign-shaped (not strictly enforced — partial/garbage entries just won't match anything
  useful).

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
