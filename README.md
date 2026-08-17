# LibreJoanne

LibreJoanne is a lightweight, self-hosted meeting room signage and personal dashboard solution optimized for 6-inch e-ink displays (1024x768). It provides a clean, high-contrast, bilingual interface to manage room availability and personal schedules.

## Key Features

- **Live Calendar Feeds**: Multi-calendar support via iCal with robust parsing. Supports internal execution of local `.php` templates for dynamic event generation.
- **Flexible View Modes**:
    - **Room View**: Large-format status (AVAILABLE/IN USE) for meeting rooms.
    - **Dashboard View**: Personal schedule view with a vertical list of upcoming events.
    - **7-Day Grid**: A full week overview with a detailed "Today" block featuring split events/weather.
- **Dynamic Widgets**:
    - **News Ticker**: Language-aware RSS aggregator with paged headline rotation (e-ink friendly).
    - **Custom Weather**: Integrated Open-Meteo weather with 3-day forecasts and high-contrast icons.
- **Diagnostics & Navigation**:
    - Real-time device IP, battery, and signal tracking (header + `okular` JS support).
    - **Demo Mode**: Automatically provides dummy telemetry (69% battery/signal) when using the `demo.ics.php` calendar.
    - On-device navigation to view past and future 7-day periods.
- **Bilingual Interface**: English and French support with manual toggle or automatic rotation.
- **Time Formatting**: Global preference for 12-hour or 24-hour time, with language-aware defaults (EN=12h, FR=24h).
- **Unified Management Dashboard**:
    - **Room & User Management**: Create and edit configurations, choose view types, and set custom display labels.
    - **Location Search**: Built-in city search to automatically set weather coordinates.
    - **Secure Architecture**: AES-256-CBC encrypted URL storage and token-based device access.

## Project Structure

```text
/
├── README.md          # Public documentation
├── CLAUDE.md          # Working conventions for contributors and AI agents
├── docs/
│   └── ARCHITECTURE.md # Design intent, subsystems, and roadmap
├── scripts/
│   ├── php            # Interpreter shim (native PHP, or pinned container)
│   └── smoke.sh       # Lint + endpoint/view smoke test
└── web/
    ├── app/           # Public Document Root (Only reachable files)
    │   ├── index.php  # Main UI logic
    │   ├── calendar.php# iCal JSON endpoint
    │   ├── rss.php     # RSS JSON endpoint
    │   ├── weather.php # Weather JSON endpoint
    │   └── manage.php  # Unified Management Dashboard
    ├── data/          # Private Data (URL-blocked via .htaccess)
    │   ├── config.php # System configuration
    │   ├── librejoanne.db # SQLite database
    │   └── cache/     # Isolated cache for ICS, XML, and JSON files
    └── lib/           # Shared Logic
        └── db.php     # Database and Security helper
```

## Setup & Installation

### 1. Requirements
- Web server (Apache/Nginx) with PHP 8.1+
- PHP Extensions: `pdo_sqlite`, `openssl`, `simplexml`, `mbstring`

### 2. Initial Configuration
1. Point your web server's document root to the `web/app/` directory.
2. Copy `web/data/config.sample.php` to `web/data/config.php`.
3. Open `web/data/config.php` and configure the `setup_password` and `encryption_key`.

### 3. Admin Setup
1. Visit `http://your-server/manage.php`.
2. Enter the `setup_password` to initiate the initial admin account creation.
3. Use the **Rooms** tab to configure public displays and the **Users** tab for personal schedules.

### 4. Device Deployment
Point your e-ink device to the absolute URLs provided in the management dashboard. 
- **Navigation**: Use the `<` and `>` buttons in the header to browse weeks.
- **Reset**: Tap the **Time/Today** button on the far left to return to the current period.

## Development

```bash
scripts/php -S 127.0.0.1:8000 -t web/app
scripts/smoke.sh                 # lint every PHP file, then check every endpoint and view
```

`scripts/php` uses a native `php` when one is on `PATH`, and otherwise runs a pinned
`php:8.3-cli` container via podman or docker — so no PHP installation is required.
Set `PHP_BIN` to point at a specific interpreter.

`scripts/smoke.sh` is the project's verification loop in place of a unit test suite.
It fails on PHP diagnostics rendered into a response body, which a status-code check
would miss. It passes offline: endpoints that depend on a remote API assert a
well-formed response, not live data.

## Deployment

`scripts/deploy-joanne.sh` deploys or updates a checkout on a server. It is written for
a shared-hosting layout with SSH and git, and every path is overridable by environment
variable (`JOANNE_APP_DIR`, `JOANNE_LINK`, `JOANNE_HEALTH_URL`, `JOANNE_REPO`).

```bash
~/scripts/deploy-joanne.sh              # redeploy whatever ref is already live
~/scripts/deploy-joanne.sh main         # switch to a branch, tag or commit
~/scripts/deploy-joanne.sh --status     # what is deployed, and is it healthy
```

**The checkout must live outside the web root.** The script keeps it in `~/apps/joanne`
and publishes only `web/app` through a symlink:

```text
~/apps/joanne                    checkout, no URL
~/htdocs/<site>/joanne  ->  ~/apps/joanne/web/app
```

This matters because `web/data/.htaccess` protects nothing on nginx or any other server
that does not read `.htaccess`. If the checkout sits inside the web root there, the
SQLite database and `config.php` — which holds the encryption key — are downloadable.
Keeping `web/data` outside the published tree means those files have no URL at all,
whatever the server is.

On first run the script generates `web/data/config.php` with a random encryption key and
setup password, inheriting all other defaults from `config.sample.php`. It is never
regenerated: **changing `encryption_key` makes every stored calendar URL undecryptable.**
`web/data` is git-ignored, so the config, database and caches survive every update.

After deploying it verifies the result: it lints, checks the page and `calendar.php`,
greps the response for PHP diagnostics, and probes the private paths to confirm they are
still unreachable. Note that it does not follow redirects when probing, since a host may
redirect unknown paths rather than returning 404.

If PHP-FPM runs as a different user than the SSH account, as it commonly does, the two
must share a group: `web/data` is created group-writable and setgid so both can maintain
it, and `config.php` is group-readable so PHP can load it.

## URL Parameter Overrides

You can override most configuration settings via URL parameters for testing or specific device needs:

- **`room`**: Load a specific room configuration by its key (e.g., `?room=boardroom`).
- **`userid`**: Load a personal schedule using an access token (e.g., `?userid=YOUR_TOKEN`). Providing this parameter automatically forces the room context to `personal`.
- **`view`**: Force a layout mode (`room`, `dashboard`, or `grid`).
- **`lang`**: Force a language (`en` or `fr`).
- **`show_rss`**: Toggle the news ticker (`1` or `0`).
- **`show_weather`**: Toggle the weather widget (`1` or `0`).
- **`show_clock`**: Toggle the header clock (`1` or `0`). See Battery Life below.
- **`power_save`**: Lengthen every refresh interval (`1` or `0`). See Battery Life below.

Toggles accept `1`/`0`, `true`/`false`, `yes`/`no` and `on`/`off`.
- **`cal`**: (Personal view only) Append an additional iCal feed URL. Can be used multiple times.
- **`dev_ip` / `dev_batt` / `dev_sig`**: Manually provide telemetry data (normally handled by Visionect headers).

## Battery Life

A Visionect panel repaints when the rendered page changes, and each repaint wakes the
display and the radio. Battery life is therefore governed by **how often pixels change**,
not by how much work the page does. Two mechanisms control that:

**`?show_clock=0`** removes the header clock. A visible clock guarantees one repaint every
minute forever, which is usually the single largest avoidable cost on the page. With the
clock hidden the panel only repaints when the schedule, weather or headlines actually
change. In the 7-day grid the button remains available as "Today" whenever you have
navigated away from the current week.

**`?power_save=1`** lengthens every refresh interval:

| | Default | Power save |
| --- | --- | --- |
| Clock | 1 min | 5 min |
| Device telemetry | 1 min | 5 min |
| News rotation | 10 s | 60 s |
| News refresh | 10 min | 30 min |
| Weather | 30 min | 60 min |

In power save the clock also displays time rounded down to the 5-minute step, so it is
coarse rather than silently stale.

Independently of both flags, the page now writes to the DOM only when a value has actually
changed, so a refresh that returns identical content costs no repaint at all.

For the longest battery life on a status panel, combine them and drop the ticker:

```text
?room=boardroom&show_clock=0&power_save=1&show_rss=0
```

## Room Resolution & Special Keys

LibreJoanne uses a hierarchical resolution system for configurations:

1. **`default`**: The global fallback. If a requested `room` key is not found in the database or `config.php`, the settings from the `default` block are used.
2. **`personal`**: The template for all User-tokenized views. Using `?userid=` automatically switches the context to `personal`. The settings in this block (name, base calendar feeds) act as the starting point before a user's individual database preferences (view mode, custom display label, coordinates) are applied.
3. **Database Precedence**: Settings stored in the SQLite database (via the Management Dashboard) always override the hardcoded arrays in `config.php` if the keys match.

Language resolves in the same order: the site default in `config.php` (`ui.lang`), then a
user's **Language** preference in the dashboard, then `?lang=`. A user set to *Site
Default* follows `config.php`, so changing it moves every such user at once. Because
`time_format` defaults to `auto` — 24-hour for French, 12-hour for English — setting a
user's language also changes how their times read unless they pin a format.

Example: `http://your-server/index.php?room=bedroom&view=grid&lang=en&show_rss=0`

## Security & Deployment Model

LibreJoanne is designed for deployment on trusted internal networks (LAN). Its security model assumes the following:

- **Directory Protection**: Direct browser access to the `data/` folder is blocked by `.htaccess` (on Apache) to prevent exposure of the database and configuration files.
- **Trusted Environment**: The application should be accessed by known devices (tablets, e-ink panels) within a private network. It is not hardened for direct public internet exposure.
- **Admin Competence**: Administrative access to `manage.php` assumes that the administrator is responsible for configuring trusted calendar and RSS feeds.
- **SSRF Protection**: Remote fetches (calendars, weather, RSS) include basic SSRF protection by rejecting requests to private/reserved IP ranges and loopback addresses.
- **Local Feed Hardening**: Local iCal feeds are restricted to the `demo.ics.php` file within the `web/app/` directory to prevent directory traversal and unauthorized file execution.
- **Encrypted Storage**: Sensitive data, such as calendar URLs, are stored encrypted using AES-256-CBC.
- **Access Control**: The management dashboard is protected by session-based authentication. Personal views are protected by unique, non-sequential access tokens.
- **CSRF Protection**: Every state-changing dashboard request must carry a per-session token, and destructive actions are POST-only. The session cookie is `HttpOnly` and `SameSite=Lax`, and the session id is regenerated on login.
- **Login Throttling**: Failed logins are counted in a sliding window, per source address and per account name, and a lockout rejects further attempts until the window passes. The initial setup password is throttled the same way. See below.

### Hardening the dashboard

If `manage.php` is reachable from the internet, tune these in `config.php`:

```php
'security' => [
    // Sliding-window lockout. A correct login clears both counters.
    'login_window'       => 900,  // seconds
    'login_max_per_ip'   => 10,   // catches one address spraying many usernames
    'login_max_per_user' => 5,    // catches many addresses targeting one account

    // Strongest available control: if non-empty, every other address gets 403 before
    // the login form is even rendered. Addresses or CIDR ranges, v4 or v6.
    'manage_allow_ips' => ['203.0.113.4', '10.20.28.0/22'],

    // Only set this if a reverse proxy really fronts the app. Otherwise a client can
    // forge X-Forwarded-For and choose its own rate-limit bucket.
    'trusted_proxies' => [],
],
```

Both counters exist because they catch different attacks: one address trying many
usernames never trips a per-account counter, and many addresses trying one username
never trips a per-address counter.

Lockout rejects rather than delaying. A deliberate response delay would hold a PHP-FPM
worker open for its duration, which turns a brute-force attempt into a way to exhaust
the worker pool.

Throttling raises the cost of guessing; it does not make an internet-facing dashboard
equivalent to a private one. If you can, put the allowlist in front of it, or a reverse
proxy with its own authentication.

If you must expose the application to the internet, it is strongly recommended to use a reverse proxy with additional authentication (e.g., Basic Auth, Authelia) and IP allow-listing.



## Credits & Licensing

- **LibreJoanne** is licensed under the [MIT License](LICENSE).

- **Weather Icons**: Created by [Erik Flowers](https://erikflowers.github.io/weather-icons/).

    - Icons: [SIL OFL 1.1](http://scripts.sil.org/OFL)

    - CSS: [MIT License](https://opensource.org/licenses/mit-license.html)

- **Geocoding & Weather**: Powered by [Open-Meteo](https://open-meteo.com/).



## License

MIT
