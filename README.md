> [!WARNING]
> **This project is a work-in-progress scaffold.** Net list, net creation, autosave, check-ins,
> close/reopen, drag-reorder, and the script/notes editor work. The hamdat second-level lookup
> and the CSV/Report/JSON download endpoints are stubbed (return HTTP 501) pending further work.
> See [SPEC.md](SPEC.md) for the full design.

# hamnethelper

A simple web-based net helper tool for running amateur radio "net" check-in sessions, integrating
with [hamdat](../hamdat/) for FCC license lookups. Protected by
[simplewebauth](../simplewebauth/) session authentication.

Full design and rationale: **[SPEC.md](SPEC.md)**.

---

## How it works

A lightweight PHP backend (no framework, no build step) that mostly negotiates file storage, with
the bulk of interactive behavior — the check-in table, autosave, drag-reorder — in vanilla
browser JavaScript. Net data is stored as one JSON file per net, outside the docroot.

Almost everything that should be changeable without touching code — the list of net types, HAMDAT
lookup defaults, autosave timing, upload limits, branding — lives in the site config file
(`hamnethelper-config.php`), not hardcoded in PHP or JS. See [Configuration reference](#configuration-reference)
below.

---

## Repository layout

```
hamnethelper/                        ← this repo, cloned into docroot
├── index.php                       ← net list page
├── net.php                         ← net operation page
├── lib/
│   ├── config.php                  ← loads + defaults the site config
│   └── net_store.php               ← net JSON file I/O (read/write/list/delete)
├── api/                            ← JSON endpoints, all behind simplewebauth
│   ├── _bootstrap.php              ← shared auth check + config + helpers
│   ├── config.php                  ← public config subset for the frontend
│   ├── nets_list.php
│   ├── net_create.php
│   ├── net_get.php
│   ├── net_save.php                ← autosave target
│   ├── net_delete.php
│   ├── net_download.php            ← TODO: csv/json/report
│   └── hamdat_lookup.php           ← TODO: hamdat CLI integration
├── assets/
│   ├── css/style.css
│   ├── js/                         ← api.js, theme.js, net-list.js, net.js
│   └── vendor/                     ← SortableJS + EasyMDE, vendored (see VERSIONS.md)
├── hamnethelper-config.php.example
└── SPEC.md
```

The live configuration file lives **outside** the repository, in the docroot parent directory, so
shallow clones/pulls never overwrite it:

```
/var/www/html/                       ← docroot
├── hamnethelper/                    ← this repo
│   ├── index.php
│   └── hamnethelper-config.php.example
├── hamnethelper-config.php          ← live config (you create this; NOT in the repo)
├── simplewebauth/                   ← sibling, required
└── ...

/var/lib/hamnethelper/nets/          ← net data, OUTSIDE the docroot entirely
├── <net-id>.json
└── ...
```

---

## Prerequisites

| Requirement | Notes |
|---|---|
| PHP 8.0+ | Core extensions only (`json`, `session`) — nothing non-standard. `exec()` must not be disabled — required once hamdat integration is implemented |
| Nginx or Apache + PHP-FPM | No special web server directives needed — net data lives outside the docroot, so unlike `simplewebauth` there's no nginx snippet/`.htaccess` rule to install for this app specifically |
| [hamdat](../hamdat/) | Installed and accessible to the web server process. Assumed already provisioned on target servers (shared with the existing `hamdatweb` deployment) — the app-level integration (`api/hamdat_lookup.php`) is still a stub, but the binary/DB access itself does not need separate provisioning |
| [simplewebauth](../simplewebauth/) | Deployed as a sibling directory in the docroot |

No `composer`, no `npm`, nothing to build or fetch at deploy time — third-party JS
(`SortableJS`, `EasyMDE`) is vendored directly into this repo under `assets/vendor/` (pinned
versions in `assets/vendor/VERSIONS.md`) and committed like any other source file. A deploy is
just: clone/pull, drop the config file, ensure `nets_dir` permissions.

> **A note on "the web server user"** — every command below uses `www-data` (Debian/Ubuntu
> convention, matching the rest of this doc). Substitute your actual web server process user
> (e.g. `apache`, `nginx`) if your target servers use a different distro/convention.

---

## Installation

### 1. Clone the repository

```bash
cd /var/www/html
git clone --depth 1 --branch main git@github.com:sysmatt/hamnethelper.git hamnethelper
```

For a re-deploy/update, `git pull` in place is safe — the live config lives outside the repo
(step 2) and is never touched by a pull.

### 2. Create the configuration file

**Idempotent** — only copy the template if a live config doesn't already exist, so a re-run
never clobbers a configured server:

```bash
test -f /var/www/html/hamnethelper-config.php || \
  cp /var/www/html/hamnethelper/hamnethelper-config.php.example \
     /var/www/html/hamnethelper-config.php
```

Edit `/var/www/html/hamnethelper-config.php` — see [Configuration reference](#configuration-reference)
below for every key.

Lock down ownership/permissions the same way `simplewebauth` treats `auth_users.php` — readable
by the web server process, not writable by it, and not world-readable:

```bash
sudo chown root:www-data /var/www/html/hamnethelper-config.php
sudo chmod 640 /var/www/html/hamnethelper-config.php
```

### 3. Create the nets data directory

Must be writable by the web server user (e.g. `www-data`) and **outside the docroot**:

```bash
sudo mkdir -p /var/lib/hamnethelper/nets
sudo chown www-data:www-data /var/lib/hamnethelper/nets
sudo chmod 750 /var/lib/hamnethelper/nets
```

If you point `nets_dir` at a different path in the config, adjust accordingly.

### 4. Ensure simplewebauth is deployed

```
/var/www/html/simplewebauth/
/var/www/html/hamnethelper/
```

See the [simplewebauth documentation](../simplewebauth/README.md) for setup, including adding
users with `authctl add <username>`.

### 5. Verify

Navigate to `https://your-server/hamnethelper/`. You should be redirected to the simplewebauth
login page; after logging in, an empty net list with a "Begin New Net" button should appear.

For an automated/scriptable check instead of a browser, see below.

---

## Automated health check

Two `curl`-able checks confirm the app is deployed and wired to auth correctly, without needing a
real logged-in session:

```bash
# 1. A page load with no session redirects to the simplewebauth login page (302, not 200/500).
curl -s -o /dev/null -w '%{http_code}\n' https://your-server/hamnethelper/index.php
# expected: 302

# 2. An API call with no session returns a 401 JSON body, not a redirect -- confirms
#    api/_bootstrap.php's session check (and, incidentally, that hamnethelper-config.php
#    exists and parses, since that's loaded before the auth check can even return).
curl -s -w '\nHTTP %{http_code}\n' https://your-server/hamnethelper/api/config.php
# expected body: {"error":"Not authenticated"}
# expected status: 401
```

If check 2 instead returns a 500 with a body mentioning "hamnethelper is not configured", the
config file (step 2 above) is missing or not readable by the web server user — the app
distinguishes that failure mode explicitly rather than a generic PHP error.

---

## Configuration reference

All configuration is in `hamnethelper-config.php` (docroot parent, not in this repo). Every key is
optional — omitted keys fall back to the defaults in `lib/config.php`.

| Key | Default | Description |
|---|---|---|
| `hamdat_bin` | `/usr/local/bin/hamdat` | Path to the hamdat binary (used once lookup is implemented) |
| `hamdat_db` | *(none)* | Path to the hamdat SQLite database |
| `nets_dir` | `/var/lib/hamnethelper/nets` | Where net JSON files are stored — must be outside the docroot and writable by the web server user |
| `app_name` | `HamNetHelper` | Shown in the page header/title |
| `net_types` | Weekly / Emergency-ARES / Drill-Training / Special Event / Other | Dropdown options on the net creation form. Add, remove, or relabel entries here — no code change needed |
| `default_hamdat_radius_miles` | `25` | Pre-filled radius in the HAMDAT Lookup Settings dialog for new nets |
| `autosave_debounce_ms` | `800` | Delay after the last edit before autosaving |
| `roster_upload_max_bytes` | `65536` | Max size accepted for an uploaded participant-list text file |
| `default_theme` | `dark` | Theme shown before any saved `localStorage` preference is applied |

If `hamnethelper-config.php` is missing, pages and API calls show a clear setup error rather than
a blank page or a PHP warning.

---

## Status / what's stubbed

Two `api/` endpoints intentionally return HTTP 501 pending further design/implementation — see the
TODO comments in each file and the corresponding SPEC.md sections:

- **`api/hamdat_lookup.php`** — hamdat CLI integration (SPEC.md §2, §3.3)
- **`api/net_download.php`** — CSV / plain-text report / JSON backup downloads (SPEC.md §4)

The callsign/name lookup box currently adds a check-in on Enter (matching against the hamdat
cache if present) but doesn't yet show a live-filtering dropdown as you type against the roster —
see the TODO comment in `assets/js/net.js`.

---

## Security notes

- Every page (`index.php`, `net.php`) and every `api/*.php` endpoint requires authentication.
  Pages use `simplewebauth/auth.php` directly (redirects to login on an expired session, correct
  for browser navigation); API endpoints use a session check in `api/_bootstrap.php` that returns
  a 401 JSON body instead of redirecting, since a redirect can't be sensibly parsed by `fetch()`.
  This intentionally duplicates simplewebauth's session-validation logic rather than modifying
  that shared repo — see the comment at the top of `api/_bootstrap.php`.
- Net data lives outside the docroot — not servable by the web server under any configuration.
- Net IDs are validated against a fixed pattern before touching the filesystem, preventing path
  traversal.
- Net file writes are atomic (write to a temp file, then rename) so an interrupted request never
  leaves a truncated net file behind.

---

## Related projects

- **[hamdat](../hamdat/)** — the FCC Amateur Radio license database CLI tool this app will query
- **[hamdatweb](../hamdatweb/)** — sibling project this app's architecture is modeled on
- **[simplewebauth](../simplewebauth/)** — the session authentication layer protecting this app
