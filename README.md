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
├── gemini.md          # Project vision and AI context
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

## URL Parameter Overrides

You can override most configuration settings via URL parameters for testing or specific device needs:

- **`room`**: Load a specific room configuration by its key (e.g., `?room=boardroom`).
- **`userid`**: Load a personal schedule using an access token (e.g., `?userid=YOUR_TOKEN`). Providing this parameter automatically forces the room context to `personal`.
- **`view`**: Force a layout mode (`room`, `dashboard`, or `grid`).
- **`lang`**: Force a language (`en` or `fr`).
- **`show_rss`**: Toggle the news ticker (`1` or `0`).
- **`show_weather`**: Toggle the weather widget (`1` or `0`).
- **`cal`**: (Personal view only) Append an additional iCal feed URL. Can be used multiple times.
- **`dev_ip` / `dev_batt` / `dev_sig`**: Manually provide telemetry data (normally handled by Visionect headers).

## Room Resolution & Special Keys

LibreJoanne uses a hierarchical resolution system for configurations:

1. **`default`**: The global fallback. If a requested `room` key is not found in the database or `config.php`, the settings from the `default` block are used.
2. **`personal`**: The template for all User-tokenized views. Using `?userid=` automatically switches the context to `personal`. The settings in this block (name, base calendar feeds) act as the starting point before a user's individual database preferences (view mode, custom display label, coordinates) are applied.
3. **Database Precedence**: Settings stored in the SQLite database (via the Management Dashboard) always override the hardcoded arrays in `config.php` if the keys match.

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

If you must expose the application to the internet, it is strongly recommended to use a reverse proxy with additional authentication (e.g., Basic Auth, Authelia) and IP allow-listing.



## Credits & Licensing

- **LibreJoanne** is licensed under the [MIT License](LICENSE).

- **Weather Icons**: Created by [Erik Flowers](https://erikflowers.github.io/weather-icons/).

    - Icons: [SIL OFL 1.1](http://scripts.sil.org/OFL)

    - CSS: [MIT License](https://opensource.org/licenses/mit-license.html)

- **Geocoding & Weather**: Powered by [Open-Meteo](https://open-meteo.com/).



## License

MIT
