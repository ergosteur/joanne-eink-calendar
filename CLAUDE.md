# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project

LibreJoanne — self-hosted meeting-room signage and personal dashboard for 6" e-ink displays (Visionect ecosystem). Plain PHP 8.1+ with SQLite. **No build step, no dependency manager, no test suite.** `web/app/` is the document root; `web/data/` and `web/lib/` must stay outside it.

`README.md` covers user-facing setup, URL parameters, and the security model. `docs/ARCHITECTURE.md` covers design intent, subsystems, and roadmap. Both are current — read them before large changes.

## Running locally

```bash
scripts/php -S 127.0.0.1:8000 -t web/app            # serve the app
cp web/data/config.sample.php web/data/config.php   # optional; sample is the automatic fallback
```

**Use `scripts/php`, not `php`.** There is no PHP on this host; the shim falls back to a pinned `php:8.3-cli` container via podman/docker, mounting the repo at its real path with `--network host`. Set `PHP_BIN` to override. A native `php` on `PATH` is preferred automatically.

Every entry point falls back to `config.sample.php` when `config.php` is missing, so the app boots without configuration. The dashboard shows a "Using config.sample.php defaults" banner in that state.

The built-in server is single-threaded. `manage.php`'s cache-exposure self-check makes a loopback HTTP request to itself and will hang against `php -S` unless `PHP_CLI_SERVER_WORKERS=2` is set (the shim forwards that variable into the container).

## Verifying changes

```bash
scripts/smoke.sh              # lint all PHP, boot the server, check every endpoint and view
scripts/smoke.sh --no-lint    # skip the lint stage
PORT=9000 scripts/smoke.sh    # non-default port
```

This is the verification loop — there is no unit test suite. It exists because PHP renders warnings and deprecations **into the response body**, where a status-code check cannot see them; the script greps every response for PHP diagnostic prefixes. It passes offline, since network-dependent endpoints are asserted to be well-formed rather than live (503 is a correct degraded response).

Run it after any change to a PHP file. When adding an endpoint, view mode, or URL parameter, add a case to it.

Two constraints worth knowing if you extend the script: the interpreter reads stdin, so any loop feeding it a file list must redirect stdin or collect the list first; and the container must be force-removed before waiting on the client process, or teardown hangs.

A `PostToolUse` hook (`.claude/hooks/php-lint.sh`) runs `php -l` on every edited PHP file and blocks on a parse error — a syntax error here otherwise shows up only as a blank page.

View modes: `/` (room), `/?room=personal` (dashboard), `/?room=personal-grid` (7-day grid), `/?userid=<token>` (personal). Get a token with:

```bash
sqlite3 web/data/librejoanne.db "SELECT access_token FROM users LIMIT 1"
```

## Architecture

### Configuration resolution (the core mechanic)

Every request resolves one "room config" through a fixed precedence chain. Both `index.php` and `calendar.php` implement this independently — **changes to resolution must be made in both, and they must agree**, or the UI and its data will disagree.

1. `?userid=<token>` present → `$roomId` is forced to `'personal'`, ignoring `?room=`.
2. `LibreDb::getRoomConfig($roomId)` — SQLite `rooms` row wins if the `room_key` matches.
3. Otherwise `$config['rooms'][$roomId]`, falling back to `$config['rooms']['default']`.
4. For a valid `?userid=`, the `users` row overrides view, `time_format`, `timezone`, weather coords, `display_name`, and horizons; the user's `calendars` rows (decrypted) replace the config feed list.
5. URL params (`view`, `lang`, `show_rss`, `show_weather`, `cal`) override last.

`default`, `personal`, and `personal-grid` are reserved `config.php` keys; `manage.php` rejects them as DB room keys so a DB row can never shadow a template.

### Timezone handling

Time is the most bug-prone area here (see the long run of timezone fixes in git history). The rules that hold today:

- The server resolves `$activeTimezone` (room → user → `config['calendar']['timezone']`) and passes it to JS as `serverTimezone`.
- `calendar.php` emits both wall-clock strings (`time`, `ends`) and absolute values (`start_iso`, `end_iso`, `start_ts`, `end_ts`). **Render from the ISO/timestamp fields** — formatting the `HH:MM` strings re-applies the browser's local zone and double-converts.
- `formatTime()` and `getServerNow()` in `index.php` are the only places that turn instants into displayed text; `is24h` derives from `time_format` (`12h`/`24h`/`auto`, where `auto` = 24h for FR, 12h for EN).
- `demo.ics.php` emits UTC (`...Z`) deliberately, so the normalization path is always exercised.

### Caching

All caches live in `web/data/cache/` as `<kind>.cache.<md5(salt + key)>.<ext>`, with a per-kind salt so filenames can't be guessed from a known feed URL. Build paths with `LibreApp::cachePath()`; the directory is created by `LibreApp::boot()` and is not in version control. TTLs: calendar 30s (`config['calendar']['cache_ttl']`), RSS 300s, weather 900s (hardcoded in `weather.php`). On a fetch failure the stale cache file is served rather than an error.

`manage.php::clearAllCaches()` wipes all three types and is called after **every** mutation (room, user prefs, calendar add/edit/delete) — keep that invariant when adding new write paths, otherwise config edits appear not to take effect.

Dynamic pages and all JSON endpoints send `no-store` headers; the e-ink gateway and any CDN in front must never serve a stale render.

### Security model

Designed for trusted LAN deployment, not public internet. Existing invariants to preserve:

- `LibreDb::isValidRemoteUrl()` is the single SSRF gate (http/https only, DNS-resolved IP rejected if private/reserved). Apply it to any new outbound fetch.
- `calendar.php` treats a URL that fails `isValidRemoteUrl()` as a *local* feed and executes it via `include` — hard-restricted to `basename() === 'demo.ics.php'` plus a `realpath()` prefix check. Loosening either check turns this into arbitrary local file execution.
- `?cal=` overrides are accepted only in the `personal` context and only after `isValidRemoteUrl()` filtering. Config/DB feed URLs are intentionally *not* filtered, since they may legitimately be local.
- Calendar URLs are stored AES-256-CBC encrypted (`LibreDb::encrypt`/`decrypt`, random IV prefixed); the `rooms.calendar_url` column stores a plain JSON array instead.
- `manage.php` is session-based with per-action RBAC: admins manage everything, a standard user may only touch their own row and their own calendars. Each mutation re-checks `$_SESSION['is_admin'] || $_SESSION['user_id'] == $target`.
- `web/data/.htaccess` (`Deny from all`) is the only protection on the DB and caches. `manage.php` self-tests this by fetching a cache file over HTTP and warns loudly if it returns 200 — that check is Apache-specific and will report a false positive under `php -S`.

### Schema migrations

`LibreDb::init()` runs `CREATE TABLE IF NOT EXISTS` plus a list of `ensureColumn()` calls on every instantiation. To add a column, add it to both the `CREATE TABLE` body and an `ensureColumn()` line — there are no migration files.

### Front end

`index.php` is a single 1400-line file: PHP resolution → inline `<style>` → markup → inline `<script>`. Server values cross into JS as `htmlspecialchars`'d constants near the top of the script block; view branching happens in `renderCalendar()` on the `view` constant (`room` / `dashboard` / `grid`).

E-ink constraints shape the CSS and JS: fixed 1024x768 body, pure black on white, no gradients or shadows, and **pagination instead of scrolling or continuous animation** (the news ticker swaps pages rather than marquees). Keep new UI within those rules.

**Repaints are the battery cost.** The panel repaints when the rendered page changes, so any unconditional DOM write on a timer drains the device even when the content is identical. Two rules hold in `index.php`: periodic code writes through `setText`/`setHtml`/`setStyle`, which return early when the value is unchanged; and all intervals come from the `REFRESH` object, which `?power_save=1` lengthens. `?show_clock=0` removes the once-a-minute clock repaint entirely. When adding anything that updates on a timer, route it through those helpers and add its interval to `REFRESH` rather than hardcoding one.

All user-visible strings live in the `i18n` object (`en` / `fr`) — add both when adding text. Device telemetry comes from `X-Visionect-*` / `X-Device-*` headers, the `okular` JS object, or `?dev_ip`/`?dev_batt`/`?dev_sig`; a room whose feed list contains `demo.ics.php` gets dummy 69% battery/signal.

## Conventions

- Commit subjects are `Category: Imperative summary` — used categories: `UI`, `Fix`, `Docs`, `Config`, `Security`, `Refactor`, `Feature`, `Enhancement`, `UX`, `Tooling`.
- Cast config values explicitly (`(string)`, `(int)`, `(float)`) before use — nullable DB columns and absent config keys have repeatedly caused PHP 8 deprecation notices in rendered output.
