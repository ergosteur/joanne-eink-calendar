# LibreJoanne

LibreJoanne is a lightweight, self-hosted meeting room signage and personal dashboard solution optimized for 6-inch e-ink displays (1024x768). It provides a clean, high-contrast, bilingual interface to manage room availability and personal schedules.

## Key Features

- **Live Calendar Feeds**: Multi-calendar support via iCal with robust parsing (handles timezones, line folding, all-day events, and merging multiple feeds).
- **Flexible View Modes**:
    - **Room View**: Large-format status (AVAILABLE/IN USE) for meeting rooms.
    - **Dashboard View**: Personal schedule view with a vertical list of upcoming events.
    - **7-Day Grid**: A full week overview with a detailed "Today" block featuring split events/weather.
- **Dynamic Widgets**:
    - **News Ticker**: Language-aware RSS aggregator with paged headline rotation (e-ink friendly).
    - **Custom Weather**: Integrated Open-Meteo weather with 3-day forecasts and high-contrast icons.
- **Diagnostics & Navigation**:
    - Real-time device IP, battery, and signal tracking (header + `okular` JS support).
    - On-device navigation to view past and future 7-day periods.
- **Bilingual Interface**: English and French support with manual toggle or automatic rotation.
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
    │   └── *.cache    # Cached ICS, XML, and JSON files
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
- **`userid`**: Load a personal schedule using an access token (e.g., `?room=personal&userid=YOUR_TOKEN`).
- **`view`**: Force a layout mode (`room`, `dashboard`, or `grid`).
- **`lang`**: Force a language (`en` or `fr`).
- **`show_rss`**: Toggle the news ticker (`1` or `0`).
- **`show_weather`**: Toggle the weather widget (`1` or `0`).
- **`cal`**: (Personal view only) Append an additional iCal feed URL. Can be used multiple times.
- **`dev_ip` / `dev_batt` / `dev_sig`**: Manually provide telemetry data (normally handled by Visionect headers).

Example: `http://your-server/index.php?room=bedroom&view=grid&lang=en&show_rss=0`

## Security Notes

- Direct browser access to the `data/` folder is blocked by `.htaccess`.

- All calendar URLs are encrypted at rest.

- Access tokens obfuscate personal schedule URLs.



## Credits & Licensing

- **LibreJoanne** is licensed under the [MIT License](LICENSE).

- **Weather Icons**: Created by [Erik Flowers](https://erikflowers.github.io/weather-icons/).

    - Icons: [SIL OFL 1.1](http://scripts.sil.org/OFL)

    - CSS: [MIT License](https://opensource.org/licenses/mit-license.html)

- **Geocoding & Weather**: Powered by [Open-Meteo](https://open-meteo.com/).



## License

MIT
